<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Isp\Actions\CreateAccessService;
use Liberu\Billing\Isp\Actions\CreateIspCapability;
use Liberu\Billing\Isp\Actions\RecordRadiusAccounting;
use Liberu\Billing\Isp\Actions\TransitionAccessService;
use Liberu\Billing\Isp\Actions\TransitionIspCapability;
use Liberu\Billing\Isp\Contracts\NetworkAdapter;
use Liberu\Billing\Isp\Models\AccessService;
use Liberu\Billing\Isp\Models\IspCapability;
use Liberu\Billing\Isp\Policies\AccessServicePolicy;
use Liberu\Billing\Isp\Policies\IspCapabilityPolicy;
use Liberu\Billing\Isp\Queries\ListIspRecords;
use Liberu\Billing\Isp\Services\NetworkAdapterRegistry;

it('validates ISP action inputs and concurrent cancellation guards', function (): void {
    expect(fn () => (new CreateIspCapability())->handle(0, ['type' => 'coverage', 'name' => 'Fiber']))
        ->toThrow(InvalidArgumentException::class);

    $service = (new CreateAccessService())->handle(1, ['name' => 'Fiber 1G']);
    expect(fn () => (new RecordRadiusAccounting(app(DatabaseManager::class)))->execute($service, ['accounting_session_id' => '']))
        ->toThrow(InvalidArgumentException::class);

    $transitionService = new TransitionAccessService();
    expect(fn () => $transitionService->handle($service, 'bogus'))
        ->toThrow(InvalidArgumentException::class);

    // Simulate stale in-memory status when persisted status is already cancelled.
    $staleService = AccessService::query()->findOrFail($service->id);
    $transitionService->handle($service, 'cancelled');
    expect(fn () => $transitionService->handle($staleService, 'active'))
        ->toThrow(LogicException::class);

    $capability = (new CreateIspCapability())->handle(1, ['type' => 'coverage', 'name' => 'Zone A']);
    $transitionCap = new TransitionIspCapability();
    expect(fn () => $transitionCap->handle($capability, 'bogus'))
        ->toThrow(InvalidArgumentException::class);

    $staleCap = IspCapability::query()->findOrFail($capability->id);
    $transitionCap->handle($capability, 'cancelled');
    expect(fn () => $transitionCap->handle($staleCap, 'active'))
        ->toThrow(LogicException::class);
});

it('lists team ISP records and evaluates ISP policies and adapter registry', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.isp.read', 'billing.isp.write']);

    $service = (new CreateAccessService())->handle($user->current_team_id, ['name' => 'Fiber 1G']);
    $other = (new CreateAccessService())->handle($user->current_team_id + 1, ['name' => 'Other']);

    expect((new ListIspRecords())->handle($user->current_team_id)->pluck('id')->all())
        ->toBe([$service->id]);

    $servicePolicy = new AccessServicePolicy();
    expect($servicePolicy->viewAny($user))->toBeTrue()
        ->and($servicePolicy->create($user))->toBeTrue()
        ->and($servicePolicy->view($user, $service))->toBeTrue()
        ->and($servicePolicy->update($user, $service))->toBeTrue()
        ->and($servicePolicy->view($user, $other))->toBeFalse()
        ->and($servicePolicy->viewAny(null))->toBeFalse();

    $capability = (new CreateIspCapability())->handle($user->current_team_id, ['type' => 'radius', 'name' => 'NAS 1']);
    $capPolicy = new IspCapabilityPolicy();
    expect($capPolicy->viewAny($user))->toBeTrue()
        ->and($capPolicy->create($user))->toBeTrue()
        ->and($capPolicy->view($user, $capability))->toBeTrue()
        ->and($capPolicy->update($user, $capability))->toBeTrue()
        ->and($capPolicy->viewAny(null))->toBeFalse();

    $registry = new NetworkAdapterRegistry();
    $adapter = new class() implements NetworkAdapter
    {
        public function key(): string
        {
            return 'mikrotik';
        }

        public function install(array $attributes): array
        {
            return $attributes;
        }

        public function suspend(array $attributes): array
        {
            return $attributes;
        }

        public function remove(array $attributes): array
        {
            return $attributes;
        }
    };

    $registry->register($adapter);
    expect($registry->keys())->toBe(['mikrotik'])
        ->and($registry->resolve('mikrotik'))->toBe($adapter)
        ->and(fn () => $registry->register($adapter))->toThrow(InvalidArgumentException::class);
});
