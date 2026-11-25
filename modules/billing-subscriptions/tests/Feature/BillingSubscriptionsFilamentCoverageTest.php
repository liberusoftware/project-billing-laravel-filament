<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Filament\Admin\Resources\RecurringBillings\RecurringBillingResource;
use App\Filament\Admin\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Pages\SubscriptionPlansPage;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BillingService;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

it('configures RecurringBillingResource form, table, relations, and pages', function (): void {
    $schema = RecurringBillingResource::form(Schema::make());
    $components = $schema->getComponents();

    expect($components)->toHaveCount(5)
        ->and(RecurringBillingResource::getRelations())->toBe([])
        ->and(RecurringBillingResource::getPages())->toBe([]);

    $livewire = Mockery::mock(HasTable::class);
    $table = RecurringBillingResource::table(Table::make($livewire));

    expect($table->getColumns())->toHaveCount(6)
        ->and($table->getFilters())->toHaveCount(2)
        ->and($table->getRecordActions())->toHaveCount(2)
        ->and($table->getToolbarActions())->toHaveCount(1);
});

it('configures SubscriptionResource form, table, relations, and pages', function (): void {
    $schema = SubscriptionResource::form(Schema::make());
    $components = $schema->getComponents();

    expect($components)->toHaveCount(6)
        ->and(SubscriptionResource::getRelations())->toBe([])
        ->and(SubscriptionResource::getPages())->toBe([]);

    $livewire = Mockery::mock(HasTable::class);
    $table = SubscriptionResource::table(Table::make($livewire));

    expect($table->getColumns())->toHaveCount(6)
        ->and($table->getFilters())->toHaveCount(1)
        ->and($table->getRecordActions())->toHaveCount(2);
});

it('mounts active plans, builds the form schema, and handles all subscribe outcomes on SubscriptionPlansPage', function (): void {
    $activePlan = SubscriptionPlan::query()->create([
        'name' => 'Starter',
        'code' => 'starter-plan',
        'price' => 19.99,
        'currency' => 'USD',
        'is_active' => true,
        'trial_days' => 0,
    ]);

    SubscriptionPlan::query()->create([
        'name' => 'Archived',
        'code' => 'archived-plan',
        'price' => 9.99,
        'currency' => 'USD',
        'is_active' => false,
        'trial_days' => 0,
    ]);

    $page = new SubscriptionPlansPage();
    $page->mount();

    expect($page->plans)->toHaveCount(1)
        ->and($page->plans->first()->id)->toBe($activePlan->id);

    $schema = $page->form(Schema::make($page));
    $flatComponents = $schema->getFlatComponents(withHidden: true);

    /** @var TextInput $customInput */
    $customInput = collect($flatComponents)
        ->first(fn ($component): bool => $component instanceof TextInput && $component->getName() === 'customPeriodDays');

    expect($customInput)->toBeInstanceOf(TextInput::class);

    $page->billingCycle = BillingCycle::Monthly->value;
    expect($customInput->isVisible())->toBeFalse()
        ->and($customInput->isRequired())->toBeFalse();

    $page->billingCycle = BillingCycle::Custom->value;
    expect($customInput->isVisible())->toBeTrue()
        ->and($customInput->isRequired())->toBeTrue();

    // 1. Authenticated user without linked customer
    $userWithoutCustomer = User::factory()->create();
    $this->actingAs($userWithoutCustomer);

    $page->selectedPlan = $activePlan->id;
    $page->billingCycle = BillingCycle::Monthly->value;
    $page->customPeriodDays = null;

    expect($page->subscribe())->toBeNull();

    // 2. Authenticated user with linked customer -> success redirects to checkout route
    Route::get('/_test-checkout/{subscription}', fn (): string => 'checkout')
        ->name('filament.pages.checkout');
    app('router')->getRoutes()->refreshNameLookups();

    $userWithCustomer = User::factory()->create();
    $customer = Customer::factory()->create(['user_id' => $userWithCustomer->id]);
    $this->actingAs($userWithCustomer);

    $createdSubscription = Subscription::factory()->create([
        'customer_id' => $customer->id,
        'subscription_plan_id' => $activePlan->id,
    ]);

    $billingServiceSuccess = Mockery::mock(BillingService::class);
    $billingServiceSuccess->shouldReceive('createSubscription')
        ->once()
        ->withArgs(fn (Customer $c, SubscriptionPlan $p, string $cycle, ?int $days): bool => $c->id === $customer->id
            && $p->id === $activePlan->id
            && $cycle === BillingCycle::Monthly->value
            && $days === null)
        ->andReturn($createdSubscription);
    $this->app->instance(BillingService::class, $billingServiceSuccess);

    $redirect = $page->subscribe();
    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->getTargetUrl())->toContain('/_test-checkout/'.$createdSubscription->id);

    // 3. Authenticated user with linked customer -> BillingService throws Exception
    $billingServiceFailure = Mockery::mock(BillingService::class);
    $billingServiceFailure->shouldReceive('createSubscription')
        ->once()
        ->andThrow(new RuntimeException('Unable to create subscription'));
    $this->app->instance(BillingService::class, $billingServiceFailure);

    expect($page->subscribe())->toBeNull();
});
