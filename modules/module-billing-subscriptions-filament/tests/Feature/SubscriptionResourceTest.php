<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Subscriptions\Actions\ActivateSubscription;
use Liberu\Billing\Subscriptions\Enums\SubscriptionStatus;
use Liberu\Billing\Subscriptions\Filament\Resources\SubscriptionResource;
use Liberu\Billing\Subscriptions\Filament\Resources\SubscriptionResource\Pages\CreateSubscription;
use Liberu\Billing\Subscriptions\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Liberu\Billing\Subscriptions\Filament\SubscriptionsFilamentPlugin;
use Liberu\Billing\Subscriptions\Models\Subscription;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('registers the Filament plugin and manages subscriptions through Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $teamId = $actor->current_team_id;
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.subscriptions.read', 'billing.subscriptions.write']);

    $panel = Filament::getPanel('admin');
    $plugin = SubscriptionsFilamentPlugin::make();
    expect($plugin->getId())->toBe('module-billing-subscriptions-filament');
    $plugin->register($panel);
    $plugin->boot($panel);

    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);

    $customer = Customer::factory()->create(['team_id' => $teamId]);
    $planA = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => $teamId,
        'name' => 'Basic',
        'pricing_model' => 'flat',
        'currency' => 'USD',
        'unit_amount_minor' => 1000,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $planB = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => $teamId,
        'name' => 'Enterprise',
        'pricing_model' => 'flat',
        'currency' => 'USD',
        'unit_amount_minor' => 5000,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(CreateSubscription::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'pricing_plan_id' => $planA,
            'trial_days' => 0,
            'period_days' => 30,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $subscription = Subscription::query()->where('customer_id', $customer->getKey())->sole();
    $foreign = app(ActivateSubscription::class)->execute(['team_id' => $teamId + 1]);
    $due = app(ActivateSubscription::class)->execute([
        'team_id' => $teamId,
        'auto_renew' => false,
        'current_period_ends_at' => now()->subDay(),
    ]);

    Livewire::test(ListSubscriptions::class)
        ->assertCanSeeTableRecords([$subscription, $due])
        ->assertCanNotSeeTableRecords([$foreign])
        ->filterTable('customer_id', (string) $customer->getKey())
        ->assertCanSeeTableRecords([$subscription])
        ->assertCanNotSeeTableRecords([$due])
        ->resetTableFilters()
        ->callTableAction('renew', $subscription, data: ['period_days' => 15])
        ->callTableAction('change_plan', $subscription, data: ['pricing_plan_id' => $planB])
        ->callTableAction('pause', $subscription)
        ->callTableAction('resume', $subscription)
        ->callTableAction('cancel', $subscription)
        ->callTableAction('expire', $due);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->fresh()->pricing_plan_id)->toBe($planB)
        ->and($due->fresh()->status)->toBe(SubscriptionStatus::Expired);
});

it('scopes the Filament query to shared subscriptions when actor has no team', function (): void {
    $shared = Subscription::query()->create([
        'team_id' => null,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now(),
        'auto_renew' => true,
        'entitlement_state' => [],
    ]);
    app(ActivateSubscription::class)->execute(['team_id' => 42]);

    $actor = User::factory()->create();
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.subscriptions.read']);

    expect(SubscriptionResource::getEloquentQuery()->pluck('id')->all())->toBe([$shared->getKey()]);
});
