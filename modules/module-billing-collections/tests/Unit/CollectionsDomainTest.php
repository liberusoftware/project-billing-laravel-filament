<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Collections\Actions\ApplyCreditControl;
use Liberu\Billing\Collections\Actions\OpenCollectionCase;
use Liberu\Billing\Collections\Actions\PromisePayment;
use Liberu\Billing\Collections\Actions\RecoverCollectionCase;
use Liberu\Billing\Collections\Actions\RetryCollectionCase;
use Liberu\Billing\Collections\Actions\ScheduleDunning;
use Liberu\Billing\Collections\Actions\ScheduleReminder;
use Liberu\Billing\Collections\Actions\SuspendCollectionCase;
use Liberu\Billing\Collections\Actions\WriteOffCollectionCase;
use Liberu\Billing\Collections\Enums\CollectionStatus;
use Liberu\Billing\Collections\Events\CollectionCaseRetried;
use Liberu\Billing\Collections\Events\CollectionCaseWrittenOff;
use Liberu\Billing\Collections\Models\CollectionCase;
use Liberu\Billing\Collections\Queries\ListCollectionCases;
use Liberu\Billing\Collections\Support\CustomerReference;

it('retries and writes off collection cases and enforces terminal guards on stale and fresh instances', function (): void {
    Event::fake([CollectionCaseRetried::class, CollectionCaseWrittenOff::class]);

    $open = app(OpenCollectionCase::class);
    $case = $open->execute(['team_id' => 10, 'amount_minor' => 5000, 'currency' => 'USD']);

    $retried = app(RetryCollectionCase::class)->execute($case, now()->addDay());
    expect($retried->metadata['retry_count'] ?? null)->toBe(1);
    Event::assertDispatched(CollectionCaseRetried::class, 1);

    $writtenOff = app(WriteOffCollectionCase::class)->execute($retried, 'Uncollectible debt');
    expect($writtenOff->status)->toBe(CollectionStatus::WrittenOff);
    Event::assertDispatched(CollectionCaseWrittenOff::class, 1);

    // Direct terminal checks (fast-path)
    expect(fn () => app(ApplyCreditControl::class)->execute($writtenOff, ''))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(ApplyCreditControl::class)->execute($writtenOff, 'final'))
        ->toThrow(LogicException::class);
    expect(fn () => app(PromisePayment::class)->execute($writtenOff, now()->addDay()))
        ->toThrow(LogicException::class);
    expect(fn () => app(RecoverCollectionCase::class)->execute($writtenOff))
        ->toThrow(LogicException::class);
    expect(fn () => app(RetryCollectionCase::class)->execute($writtenOff, now()->addDay()))
        ->toThrow(LogicException::class);
    expect(fn () => app(ScheduleDunning::class)->execute($writtenOff, now()->addDay()))
        ->toThrow(LogicException::class);
    expect(fn () => app(ScheduleReminder::class)->execute($writtenOff, now()->addDay()))
        ->toThrow(LogicException::class);
    expect(fn () => app(SuspendCollectionCase::class)->execute($writtenOff, 'Overdue'))
        ->toThrow(LogicException::class);
    expect(fn () => app(WriteOffCollectionCase::class)->execute($writtenOff, 'Duplicate'))
        ->toThrow(LogicException::class);

    // Stale instance checks (persisted state became terminal while in-memory status is still Open)
    $stale = $open->execute(['team_id' => 10, 'amount_minor' => 4000, 'currency' => 'USD']);
    CollectionCase::query()->whereKey($stale->getKey())->update(['status' => CollectionStatus::Closed->value]);

    expect(fn () => app(ApplyCreditControl::class)->execute($stale, 'warning'))
        ->toThrow(LogicException::class);
    expect(fn () => app(PromisePayment::class)->execute($stale, now()->addDay()))
        ->toThrow(LogicException::class);
    expect(fn () => app(RetryCollectionCase::class)->execute($stale, now()->addDay()))
        ->toThrow(LogicException::class);
    expect(fn () => app(ScheduleDunning::class)->execute($stale, now()->addDay()))
        ->toThrow(LogicException::class);
    expect(fn () => app(ScheduleReminder::class)->execute($stale, now()->addDay()))
        ->toThrow(LogicException::class);
    expect(fn () => app(SuspendCollectionCase::class)->execute($stale, 'Late'))
        ->toThrow(LogicException::class);
});

it('validates customer and invoice references when host tables exist or are missing', function (): void {
    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    expect(CustomerReference::assertBelongsToTeam(app(DatabaseManager::class), $customer->id, $team->id))
        ->toBe($customer->id);

    Schema::partialMock()->shouldReceive('hasTable')->with('customers')->once()->andReturnFalse();
    expect(fn () => CustomerReference::assertBelongsToTeam(app(DatabaseManager::class), $customer->id, $team->id))
        ->toThrow(InvalidArgumentException::class, 'Customer reference is invalid.');

    Schema::partialMock()->shouldReceive('hasTable')->with('billing_invoices')->once()->andReturnFalse();
    expect(fn () => app(OpenCollectionCase::class)->execute([
        'team_id' => $team->id,
        'invoice_id' => 99,
        'amount_minor' => 1000,
        'currency' => 'USD',
    ]))->toThrow(InvalidArgumentException::class, 'Collection invoice reference is invalid.');
});

it('lists shared and team-scoped collection cases and enforces policies', function (): void {
    $open = app(OpenCollectionCase::class);
    $shared = $open->execute(['amount_minor' => 1000, 'currency' => 'USD']);
    $own = $open->execute(['team_id' => 10, 'amount_minor' => 2000, 'currency' => 'USD']);
    $foreign = $open->execute(['team_id' => 20, 'amount_minor' => 3000, 'currency' => 'USD']);

    expect(app(ListCollectionCases::class)->execute(null)->pluck('id')->all())->toBe([$shared->id])
        ->and(app(ListCollectionCases::class)->execute(10)->pluck('id')->all())->toContain($own->id, $shared->id)
        ->and(app(ListCollectionCases::class)->execute(10)->pluck('id')->all())->not->toContain($foreign->id);

    $actor = new User();
    $actor->forceFill(['current_team_id' => 10]);

    Sanctum::actingAs($actor, ['billing.collections.read']);
    expect(Gate::allows('viewAny', CollectionCase::class))->toBeTrue()
        ->and(Gate::denies('create', CollectionCase::class))->toBeTrue();

    Sanctum::actingAs($actor, ['billing.collections.write']);
    expect(Gate::allows('create', CollectionCase::class))->toBeTrue()
        ->and(Gate::allows('update', $own))->toBeTrue()
        ->and(Gate::allows('update', $shared))->toBeTrue()
        ->and(Gate::denies('update', $foreign))->toBeTrue();
});
