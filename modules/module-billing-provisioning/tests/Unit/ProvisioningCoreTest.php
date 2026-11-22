<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Provisioning\Actions\CreateProvisionedService;
use Liberu\Billing\Provisioning\Actions\QueueProvisioningOperation;
use Liberu\Billing\Provisioning\Actions\ReconcileProvisionedService;
use Liberu\Billing\Provisioning\Actions\RunProvisioningOperation;
use Liberu\Billing\Provisioning\Actions\TransitionProvisionedService;
use Liberu\Billing\Provisioning\Contracts\ProvisioningDriver;
use Liberu\Billing\Provisioning\Enums\ProvisioningState;
use Liberu\Billing\Provisioning\Events\ProvisionedServiceCreated;
use Liberu\Billing\Provisioning\Events\ProvisionedServiceReconciled;
use Liberu\Billing\Provisioning\Events\ProvisionedServiceStateChanged;
use Liberu\Billing\Provisioning\Events\ProvisioningOperationCompleted;
use Liberu\Billing\Provisioning\Events\ProvisioningOperationFailed;
use Liberu\Billing\Provisioning\Events\ProvisioningOperationQueued;
use Liberu\Billing\Provisioning\Models\ProvisionedService;
use Liberu\Billing\Provisioning\Models\ProvisioningOperation;
use Liberu\Billing\Provisioning\Policies\ProvisioningPolicy;
use Liberu\Billing\Provisioning\ProvisioningServiceProvider;
use Liberu\Billing\Provisioning\Queries\ListProvisionedServices;
use Liberu\Billing\Provisioning\Services\ProvisioningDriverRegistry;
use Liberu\Billing\Provisioning\Support\CustomerReference;
use Liberu\Billing\Subscriptions\Models\Subscription;

uses(RefreshDatabase::class);

it('creates a provisioned service with customer and subscription validation', function (): void {
    Event::fake([ProvisionedServiceCreated::class]);

    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $subscription = Subscription::query()->create([
        'team_id' => $team->id,
        'status' => 'active',
        'starts_at' => now(),
        'auto_renew' => true,
        'entitlement_state' => [],
    ]);

    $service = app(CreateProvisionedService::class)->execute([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'subscription_id' => $subscription->id,
        'provider' => '  cpanel  ',
        'external_id' => 'ext-1',
        'metadata' => ['plan' => 'pro'],
    ]);

    expect($service->provider)->toBe('cpanel')
        ->and($service->customer_id)->toBe($customer->id)
        ->and($service->subscription_id)->toBe($subscription->id)
        ->and($service->state)->toBe(ProvisioningState::Pending)
        ->and($service->metadata)->toBe(['plan' => 'pro']);

    Event::assertDispatched(ProvisionedServiceCreated::class);
});

it('rejects invalid provider, customer, and subscription references when creating a provisioned service', function (): void {
    $action = app(CreateProvisionedService::class);
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    expect(fn () => $action->execute(['team_id' => $team->id, 'provider' => '   ']))
        ->toThrow(InvalidArgumentException::class, 'A provisioning provider is required.');

    $otherSub = Subscription::query()->create([
        'team_id' => $otherTeam->id,
        'status' => 'active',
        'starts_at' => now(),
        'auto_renew' => true,
        'entitlement_state' => [],
    ]);

    expect(fn () => $action->execute([
        'team_id' => $team->id,
        'subscription_id' => $otherSub->id,
        'provider' => 'test',
    ]))->toThrow(InvalidArgumentException::class, 'Provisioning subscription reference is invalid.');

    Schema::partialMock()
        ->shouldReceive('hasTable')
        ->with('billing_subscriptions')
        ->andReturn(false);

    expect(fn () => $action->execute([
        'team_id' => $team->id,
        'subscription_id' => 999,
        'provider' => 'test',
    ]))->toThrow(InvalidArgumentException::class, 'Provisioning subscription reference is invalid.');
});

it('validates customer references including missing customers table', function (): void {
    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    expect(CustomerReference::assertBelongsToTeam(app('db'), null, $team->id))->toBeNull()
        ->and(CustomerReference::assertBelongsToTeam(app('db'), $customer->id, $team->id))->toBe($customer->id);

    expect(fn () => CustomerReference::assertBelongsToTeam(app('db'), 999999, $team->id))
        ->toThrow(InvalidArgumentException::class, 'Customer reference is invalid.');

    Schema::partialMock()
        ->shouldReceive('hasTable')
        ->with('customers')
        ->andReturn(false);

    expect(fn () => CustomerReference::assertBelongsToTeam(app('db'), $customer->id, $team->id))
        ->toThrow(InvalidArgumentException::class, 'Customer reference is invalid.');
});

it('queues valid provisioning operations and rejects invalid ones', function (): void {
    Event::fake([ProvisioningOperationQueued::class]);

    $service = app(CreateProvisionedService::class)->execute(['team_id' => 1, 'provider' => 'test']);
    $queue = app(QueueProvisioningOperation::class);

    expect(fn () => $queue->execute($service, 'invalid-op'))
        ->toThrow(InvalidArgumentException::class, 'Provisioning operation is invalid.');

    $operation = $queue->execute($service, 'provision', ['foo' => 'bar']);

    expect($operation->operation)->toBe('provision')
        ->and($operation->status)->toBe('queued')
        ->and($operation->payload)->toBe(['foo' => 'bar'])
        ->and($operation->service->is($service))->toBeTrue()
        ->and($service->operations()->count())->toBe(1);

    Event::assertDispatched(ProvisioningOperationQueued::class);
});

it('runs provision, deprovision, rollback, poll, reconcile, and handles error and running states', function (): void {
    Event::fake([ProvisioningOperationCompleted::class, ProvisioningOperationFailed::class]);

    $registry = app(ProvisioningDriverRegistry::class);
    $driver = new class() implements ProvisioningDriver
    {
        public int $deprovisions = 0;

        public function provision(ProvisionedService $service): string
        {
            return 'ext-prov-1';
        }

        public function deprovision(ProvisionedService $service): void
        {
            $this->deprovisions++;
        }

        public function poll(ProvisionedService $service): array
        {
            return ['state' => ProvisioningState::Suspended->value, 'external_id' => 'ext-poll-1', 'error' => null];
        }
    };
    $registry->register('core-test-driver', $driver);

    $service = app(CreateProvisionedService::class)->execute(['team_id' => 1, 'provider' => 'core-test-driver']);
    $queue = app(QueueProvisioningOperation::class);
    $run = app(RunProvisioningOperation::class);

    // Provision
    $provOp = $run->execute($queue->execute($service, 'provision'));
    expect($provOp->status)->toBe('completed')
        ->and($service->refresh()->state)->toBe(ProvisioningState::Active)
        ->and($service->external_id)->toBe('ext-prov-1');

    // Poll
    $pollOp = $run->execute($queue->execute($service, 'poll'));
    expect($pollOp->status)->toBe('completed')
        ->and($service->refresh()->state)->toBe(ProvisioningState::Suspended)
        ->and($service->external_id)->toBe('ext-poll-1');

    // Reconcile
    $reconcileOp = $run->execute($queue->execute($service, 'reconcile'));
    expect($reconcileOp->status)->toBe('completed');

    // Deprovision
    $deprovOp = $run->execute($queue->execute($service, 'deprovision'));
    expect($deprovOp->status)->toBe('completed')
        ->and($service->refresh()->state)->toBe(ProvisioningState::Deprovisioned)
        ->and($driver->deprovisions)->toBe(1);

    // Rollback
    $rollbackOp = $run->execute($queue->execute($service, 'rollback'));
    expect($rollbackOp->status)->toBe('completed')
        ->and($driver->deprovisions)->toBe(2);

    // Completed operation is a no-op
    expect($run->execute($rollbackOp)->status)->toBe('completed');

    // Running operation throws LogicException
    $runningOp = ProvisioningOperation::query()->create([
        'team_id' => 1,
        'provisioned_service_id' => $service->id,
        'operation' => 'provision',
        'status' => 'running',
        'payload' => [],
    ]);
    expect(fn () => $run->execute($runningOp))
        ->toThrow(LogicException::class, 'Provisioning operation is already running.');

    // Unsupported operation triggers default arm and catch block
    $unsupportedOp = ProvisioningOperation::query()->create([
        'team_id' => 1,
        'provisioned_service_id' => $service->id,
        'operation' => 'unknown',
        'status' => 'queued',
        'payload' => [],
    ]);
    expect(fn () => $run->execute($unsupportedOp))
        ->toThrow(InvalidArgumentException::class, 'Unsupported provisioning operation.');
    expect($unsupportedOp->refresh()->status)->toBe('failed');

    Event::assertDispatched(ProvisioningOperationCompleted::class);
    Event::assertDispatched(ProvisioningOperationFailed::class);
});

it('transitions provisioned services through all valid states and rejects invalid ones', function (): void {
    Event::fake([ProvisionedServiceStateChanged::class]);

    $create = app(CreateProvisionedService::class);
    $transition = app(TransitionProvisionedService::class);

    // Pending -> Provisioning -> Active -> Suspended -> Active -> Deprovisioning -> Deprovisioned
    $service = $create->execute(['team_id' => 1, 'provider' => 'test']);
    expect(fn () => $transition->execute($service, ProvisioningState::Active))
        ->toThrow(InvalidArgumentException::class, 'Invalid provisioning transition from [pending] to [active].');

    $service = $transition->execute($service, ProvisioningState::Provisioning);
    expect($service->state)->toBe(ProvisioningState::Provisioning);

    $service = $transition->execute($service, ProvisioningState::Active);
    expect($service->state)->toBe(ProvisioningState::Active);

    $service = $transition->execute($service, ProvisioningState::Suspended, 'Overdue');
    expect($service->state)->toBe(ProvisioningState::Suspended)
        ->and($service->last_error)->toBe('Overdue');

    $service = $transition->execute($service, ProvisioningState::Active);
    $service = $transition->execute($service, ProvisioningState::Deprovisioning);
    $service = $transition->execute($service, ProvisioningState::Deprovisioned);
    expect($service->state)->toBe(ProvisioningState::Deprovisioned);

    expect(fn () => $transition->execute($service, ProvisioningState::Active))
        ->toThrow(InvalidArgumentException::class, 'Invalid provisioning transition from [deprovisioned] to [active].');

    // Failed -> Provisioning
    $failed = $create->execute(['team_id' => 1, 'provider' => 'test', 'state' => ProvisioningState::Failed]);
    $failed = $transition->execute($failed, ProvisioningState::Provisioning);
    expect($failed->state)->toBe(ProvisioningState::Provisioning);

    // Stale persisted state rejected under lock
    $stale = $create->execute(['team_id' => 1, 'provider' => 'stale-test']);
    $stale->refresh();
    ProvisionedService::query()->whereKey($stale->getKey())->update(['state' => ProvisioningState::Active->value]);
    expect(fn () => $transition->execute($stale, ProvisioningState::Provisioning))
        ->toThrow(InvalidArgumentException::class, 'Invalid provisioning transition from [active] to [provisioning].');

    Event::assertDispatched(ProvisionedServiceStateChanged::class);
});

it('reconciles a provisioned service and dispatches event', function (): void {
    Event::fake([ProvisionedServiceReconciled::class]);

    $service = app(CreateProvisionedService::class)->execute(['team_id' => 1, 'provider' => 'test']);
    $reconciled = app(ReconcileProvisionedService::class)->execute($service);

    expect($reconciled->last_reconciled_at)->not->toBeNull();
    Event::assertDispatched(ProvisionedServiceReconciled::class);
});

it('lists provisioned services scoped by team or shared only', function (): void {
    $create = app(CreateProvisionedService::class);
    $shared = $create->execute(['team_id' => null, 'provider' => 'shared']);
    $teamA = $create->execute(['team_id' => 10, 'provider' => 'team-a']);
    $create->execute(['team_id' => 20, 'provider' => 'team-b']);

    $list = app(ListProvisionedServices::class);

    expect($list->execute(null)->pluck('id')->all())->toBe([$shared->id])
        ->and($list->execute(10)->pluck('id')->sort()->values()->all())->toBe([$shared->id, $teamA->id]);
});

it('validates driver registry registration and resolution errors', function (): void {
    $registry = new ProvisioningDriverRegistry();
    $driver = new class() implements ProvisioningDriver
    {
        public function provision(ProvisionedService $service): string
        {
            return 'id';
        }

        public function deprovision(ProvisionedService $service): void {}

        public function poll(ProvisionedService $service): array
        {
            return [];
        }
    };

    expect(fn () => $registry->register('   ', $driver))
        ->toThrow(InvalidArgumentException::class, 'Provisioning driver providers must be non-empty and unique.');

    $registry->register('aws', $driver);
    expect($registry->resolve('aws'))->toBe($driver);

    expect(fn () => $registry->register('aws', $driver))
        ->toThrow(InvalidArgumentException::class, 'Provisioning driver providers must be non-empty and unique.');

    expect(fn () => $registry->resolve('missing'))
        ->toThrow(InvalidArgumentException::class, 'Provisioning driver [missing] is not registered.');
});

it('enforces provisioning policy authorization across abilities and teams', function (): void {
    $policy = new ProvisioningPolicy();
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $otherTeam = Team::factory()->create();

    Sanctum::actingAs($user, ['billing.provisioning.read', 'billing.provisioning.write']);

    $ownService = ProvisionedService::query()->create(['team_id' => $user->current_team_id, 'provider' => 'test', 'state' => ProvisioningState::Pending]);
    $otherService = ProvisionedService::query()->create(['team_id' => $otherTeam->id, 'provider' => 'test', 'state' => ProvisioningState::Pending]);
    $sharedService = ProvisionedService::query()->create(['team_id' => null, 'provider' => 'test', 'state' => ProvisioningState::Pending]);

    expect($policy->viewAny(null))->toBeFalse()
        ->and($policy->create(null))->toBeFalse()
        ->and($policy->viewAny($user))->toBeTrue()
        ->and($policy->create($user))->toBeTrue()
        ->and($policy->view($user, $ownService))->toBeTrue()
        ->and($policy->view($user, $otherService))->toBeFalse()
        ->and($policy->view($user, $sharedService))->toBeTrue()
        ->and($policy->update($user, $ownService))->toBeTrue()
        ->and($policy->update($user, $otherService))->toBeFalse();

    // Plain object without tokenCan method
    $plainUser = (object) ['current_team_id' => $user->current_team_id];
    expect($policy->view($plainUser, $ownService))->toBeTrue()
        ->and($policy->update($plainUser, $ownService))->toBeTrue();
});

it('registers and boots the provisioning service provider', function (): void {
    $provider = new ProvisioningServiceProvider(app());
    $provider->register();
    $provider->boot();

    expect(app(ProvisioningDriverRegistry::class))->toBeInstanceOf(ProvisioningDriverRegistry::class);
});
