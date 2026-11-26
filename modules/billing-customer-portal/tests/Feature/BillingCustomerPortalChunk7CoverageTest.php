<?php

declare(strict_types=1);

use App\Enums\CustomerStatus;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientNoteController;
use App\Http\Controllers\ServiceManagementController;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Services\ClientContactService;
use App\Services\ServiceProvisioningService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

describe('ClientController', function (): void {
    it('lists, searches, and filters clients by status', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $teamId = $user->currentTeam->id;

        Client::create([
            'team_id' => $teamId,
            'name' => 'Alpha Widgets',
            'email' => 'alpha@widgets.test',
            'company' => 'Widgets Ltd',
            'status' => 'active',
        ]);

        Client::create([
            'team_id' => $teamId,
            'name' => 'Beta Logistics',
            'email' => 'beta@logistics.test',
            'company' => 'Logistics Corp',
            'status' => 'inactive',
        ]);

        $controller = new ClientController();

        // Unfiltered index
        $allView = $controller->index(Request::create('/clients', 'GET'));
        expect($allView->getName())->toBe('clients.index')
            ->and($allView->getData()['clients'])->toBeInstanceOf(LengthAwarePaginator::class)
            ->and($allView->getData()['clients']->total())->toBe(2);

        // Search by name/email/company + status filter
        $filteredView = $controller->index(Request::create('/clients', 'GET', [
            'search' => 'Widgets',
            'status' => 'active',
        ]));
        expect($filteredView->getName())->toBe('clients.index')
            ->and($filteredView->getData()['clients']->total())->toBe(1)
            ->and($filteredView->getData()['clients']->first()->email)->toBe('alpha@widgets.test');

        // Search by company with inactive status
        $inactiveView = $controller->index(Request::create('/clients', 'GET', [
            'search' => 'Logistics',
            'status' => 'inactive',
        ]));
        expect($inactiveView->getData()['clients']->total())->toBe(1)
            ->and($inactiveView->getData()['clients']->first()->name)->toBe('Beta Logistics');
    });

    it('returns create and edit views and handles store, update, and destroy', function (): void {
        $controller = new ClientController();

        expect($controller->create()->getName())->toBe('clients.create');

        // Store
        $storeResponse = $controller->store(Request::create('/clients', 'POST', [
            'name' => 'Gamma Solutions',
            'email' => 'gamma@example.com',
            'phone' => '+1-555-0199',
            'company' => 'Gamma LLC',
            'address' => '42 Example Ave',
            'notes' => 'Priority account',
            'status' => 'active',
        ]));

        expect($storeResponse->getStatusCode())->toBe(302)
            ->and($storeResponse->getTargetUrl())->toBe(route('clients.index'))
            ->and(session('success'))->toBe('Client created successfully');

        $client = Client::where('email', 'gamma@example.com')->firstOrFail();

        // Edit
        $editView = $controller->edit($client);
        expect($editView->getName())->toBe('clients.edit')
            ->and($editView->getData()['client']->is($client))->toBeTrue();

        // Update
        $updateResponse = $controller->update(Request::create("/clients/{$client->id}", 'PUT', [
            'name' => 'Gamma Solutions Updated',
            'email' => 'gamma@example.com',
            'phone' => '+1-555-0200',
            'company' => 'Gamma International',
            'address' => '99 Updated Blvd',
            'notes' => 'Updated notes',
            'status' => 'inactive',
        ]), $client);

        expect($updateResponse->getStatusCode())->toBe(302)
            ->and($updateResponse->getTargetUrl())->toBe(route('clients.index'))
            ->and(session('success'))->toBe('Client updated successfully')
            ->and($client->fresh()->name)->toBe('Gamma Solutions Updated')
            ->and($client->fresh()->status)->toBe('inactive');

        // Destroy
        $destroyResponse = $controller->destroy($client);

        expect($destroyResponse->getStatusCode())->toBe(302)
            ->and($destroyResponse->getTargetUrl())->toBe(route('clients.index'))
            ->and(session('success'))->toBe('Client deleted successfully')
            ->and(Client::find($client->id))->toBeNull();
    });
});

describe('ClientNoteController', function (): void {
    it('filters notes by content and dateRange options (today, week, month, custom)', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $teamId = $user->currentTeam->id;

        Sanctum::actingAs($user, ['client-notes:read']);
        Gate::before(fn (): true => true);

        $client = Client::create([
            'team_id' => $teamId,
            'name' => 'Notes Client',
            'email' => 'notes-client@example.com',
            'status' => 'active',
        ]);

        $todayNote = ClientNote::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'content' => 'Urgent billing follow up',
        ]);

        $oldNote = ClientNote::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'content' => 'Archived billing discussion',
        ]);
        $oldNote->forceFill([
            'created_at' => Carbon::now()->subMonths(3),
            'updated_at' => Carbon::now()->subMonths(3),
        ])->saveQuietly();

        // Content + today
        $todayResponse = $this->getJson('/api/client-notes?'.http_build_query([
            'client_id' => $client->id,
            'content' => 'Urgent',
            'dateRange' => 'today',
        ]));
        $todayResponse->assertOk();
        expect($todayResponse->json('data'))->toHaveCount(1)
            ->and($todayResponse->json('data.0.id'))->toBe($todayNote->id);

        // Week
        $weekResponse = $this->getJson('/api/client-notes?'.http_build_query([
            'client_id' => $client->id,
            'dateRange' => 'week',
        ]));
        $weekResponse->assertOk();
        expect($weekResponse->json('data'))->toHaveCount(1);

        // Month
        $monthResponse = $this->getJson('/api/client-notes?'.http_build_query([
            'client_id' => $client->id,
            'dateRange' => 'month',
        ]));
        $monthResponse->assertOk();
        expect($monthResponse->json('data'))->toHaveCount(1);

        // Custom with startDate and endDate
        $customResponse = $this->getJson('/api/client-notes?'.http_build_query([
            'client_id' => $client->id,
            'dateRange' => 'custom',
            'startDate' => Carbon::now()->subMonths(4)->toDateTimeString(),
            'endDate' => Carbon::now()->subMonths(2)->toDateTimeString(),
        ]));
        $customResponse->assertOk();
        expect($customResponse->json('data'))->toHaveCount(1)
            ->and($customResponse->json('data.0.id'))->toBe($oldNote->id);

        // Custom without startDate/endDate still succeeds without applying between filter
        $customEmptyResponse = $this->getJson('/api/client-notes?'.http_build_query([
            'client_id' => $client->id,
            'dateRange' => 'custom',
        ]));
        $customEmptyResponse->assertOk();
        expect($customEmptyResponse->json('data'))->toHaveCount(2);
    });

    it('returns distinct search suggestions matching query', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $client = Client::create([
            'team_id' => $user->currentTeam->id,
            'name' => 'Suggestion Client',
            'email' => 'suggest@example.com',
            'status' => 'active',
        ]);

        ClientNote::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'content' => 'Invoice renewal reminder',
        ]);
        ClientNote::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'content' => 'Invoice renewal reminder',
        ]);
        ClientNote::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'content' => 'Invoice payment confirmed',
        ]);

        $controller = new ClientNoteController();
        $response = $controller->suggestions(Request::create('/api/search-suggestions', 'GET', [
            'query' => 'Invoice',
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toHaveCount(2);
    });
});

describe('ServiceManagementController', function (): void {
    it('provisions and manages a subscription service on success and returns 400 on failure', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $teamId = $user->currentTeam->id;
        $customer = Customer::factory()->create(['team_id' => $teamId]);
        $subscription = Subscription::factory()->create([
            'team_id' => $teamId,
            'customer_id' => $customer->id,
        ]);

        $provisioningService = Mockery::mock(ServiceProvisioningService::class);
        $provisioningService->shouldReceive('provisionService')
            ->once()
            ->with(Mockery::on(fn (Subscription $s): bool => $s->id === $subscription->id))
            ->andReturn(['status' => 'success', 'message' => 'Provisioned']);
        $provisioningService->shouldReceive('provisionService')
            ->once()
            ->andThrow(new Exception('Control panel unreachable'));

        $provisioningService->shouldReceive('manageService')
            ->once()
            ->with(Mockery::on(fn (Subscription $s): bool => $s->id === $subscription->id), 'suspend')
            ->andReturn(['status' => 'success', 'message' => 'Suspended']);
        $provisioningService->shouldReceive('manageService')
            ->once()
            ->with(Mockery::on(fn (Subscription $s): bool => $s->id === $subscription->id), 'terminate')
            ->andThrow(new Exception('Termination failed'));

        $controller = new ServiceManagementController($provisioningService);

        // provisionService success
        $provisionOk = $controller->provisionService(Request::create('/provision', 'POST', [
            'subscription_id' => $subscription->id,
        ]));
        expect($provisionOk->getStatusCode())->toBe(200)
            ->and($provisionOk->getData(true)['message'])->toBe('Service provisioned successfully')
            ->and($provisionOk->getData(true)['result']['status'])->toBe('success');

        // provisionService failure
        $provisionErr = $controller->provisionService(Request::create('/provision', 'POST', [
            'subscription_id' => $subscription->id,
        ]));
        expect($provisionErr->getStatusCode())->toBe(400)
            ->and($provisionErr->getData(true)['message'])->toBe('Service provisioning failed')
            ->and($provisionErr->getData(true)['error'])->toBe('Control panel unreachable');

        // manageService success
        $manageOk = $controller->manageService(Request::create('/manage', 'POST', [
            'subscription_id' => $subscription->id,
            'action' => 'suspend',
        ]));
        expect($manageOk->getStatusCode())->toBe(200)
            ->and($manageOk->getData(true)['message'])->toBe('Service managed successfully')
            ->and($manageOk->getData(true)['result']['status'])->toBe('success');

        // manageService failure
        $manageErr = $controller->manageService(Request::create('/manage', 'POST', [
            'subscription_id' => $subscription->id,
            'action' => 'terminate',
        ]));
        expect($manageErr->getStatusCode())->toBe(400)
            ->and($manageErr->getData(true)['message'])->toBe('Service management failed')
            ->and($manageErr->getData(true)['error'])->toBe('Termination failed');
    });
});

describe('Models: Client, Customer, and Lead', function (): void {
    it('defines Client::notes, Customer relations & same-status transitionTo, and Lead::team', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        // Client::notes
        $client = Client::create([
            'team_id' => $team->id,
            'name' => 'Relation Client',
            'email' => 'rel-client@example.com',
            'status' => 'active',
        ]);
        $note = ClientNote::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'content' => 'First client note',
        ]);
        expect($client->notes())->toBeInstanceOf(HasMany::class)
            ->and($client->notes()->get())->toHaveCount(1)
            ->and($client->notes()->first()->id)->toBe($note->id);

        // Customer::transitionTo same status + relations
        $customer = Customer::factory()->create([
            'team_id' => $team->id,
            'lifecycle_status' => CustomerStatus::Active,
            'status_changed_at' => null,
        ]);
        $customer->forceFill(['user_id' => $user->id])->save();

        expect($customer->transitionTo(CustomerStatus::Active))->toBeTrue()
            ->and($customer->fresh()->status_changed_at)->toBeNull()
            ->and($customer->credits())->toBeInstanceOf(HasMany::class)
            ->and($customer->credits()->get())->toHaveCount(0)
            ->and($customer->quotes())->toBeInstanceOf(HasMany::class)
            ->and($customer->quotes()->get())->toHaveCount(0)
            ->and($customer->subscriptions())->toBeInstanceOf(HasMany::class)
            ->and($customer->subscriptions()->get())->toHaveCount(0)
            ->and($customer->ispServices())->toBeInstanceOf(HasMany::class)
            ->and($customer->ispServices()->get())->toHaveCount(0)
            ->and($customer->voipAccounts())->toBeInstanceOf(HasMany::class)
            ->and($customer->voipAccounts()->get())->toHaveCount(0)
            ->and($customer->user())->toBeInstanceOf(BelongsTo::class)
            ->and($customer->user->is($user))->toBeTrue();

        // Lead::team
        $lead = Lead::create([
            'team_id' => $team->id,
            'name' => 'Inbound Lead',
            'email' => 'lead@example.com',
            'status' => 'new',
        ]);
        expect($lead->team())->toBeInstanceOf(BelongsTo::class)
            ->and($lead->team->is($team))->toBeTrue();
    });
});

describe('CustomerPolicy', function (): void {
    it('delegates all 12 policy methods to the matching user abilities', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
        $policy = new CustomerPolicy();

        // Without abilities granted, all checks return false
        expect($policy->viewAny($user))->toBeFalse()
            ->and($policy->view($user, $customer))->toBeFalse()
            ->and($policy->create($user))->toBeFalse()
            ->and($policy->update($user, $customer))->toBeFalse()
            ->and($policy->delete($user, $customer))->toBeFalse()
            ->and($policy->deleteAny($user))->toBeFalse()
            ->and($policy->forceDelete($user, $customer))->toBeFalse()
            ->and($policy->forceDeleteAny($user))->toBeFalse()
            ->and($policy->restore($user, $customer))->toBeFalse()
            ->and($policy->restoreAny($user))->toBeFalse()
            ->and($policy->replicate($user, $customer))->toBeFalse()
            ->and($policy->reorder($user))->toBeFalse();

        // Grant each ability and verify each policy method returns true
        foreach ([
            'view_any_customer',
            'view_customer',
            'create_customer',
            'update_customer',
            'delete_customer',
            'delete_any_customer',
            'force_delete_customer',
            'force_delete_any_customer',
            'restore_customer',
            'restore_any_customer',
            'replicate_customer',
            'reorder_customer',
        ] as $ability) {
            Gate::define($ability, fn (): bool => true);
        }

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->view($user, $customer))->toBeTrue()
            ->and($policy->create($user))->toBeTrue()
            ->and($policy->update($user, $customer))->toBeTrue()
            ->and($policy->delete($user, $customer))->toBeTrue()
            ->and($policy->deleteAny($user))->toBeTrue()
            ->and($policy->forceDelete($user, $customer))->toBeTrue()
            ->and($policy->forceDeleteAny($user))->toBeTrue()
            ->and($policy->restore($user, $customer))->toBeTrue()
            ->and($policy->restoreAny($user))->toBeTrue()
            ->and($policy->replicate($user, $customer))->toBeTrue()
            ->and($policy->reorder($user))->toBeTrue();
    });
});

describe('ClientContactService', function (): void {
    it('demotes existing primary contacts when updateContact promotes another contact to primary', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
        $service = new ClientContactService();

        $existingPrimary = $service->createContact($customer, [
            'first_name' => 'Alice',
            'last_name' => 'Primary',
            'email' => 'alice-primary@example.com',
            'is_primary' => true,
        ]);

        $secondary = $service->createContact($customer, [
            'first_name' => 'Bob',
            'last_name' => 'Secondary',
            'email' => 'bob-secondary@example.com',
            'is_primary' => false,
        ]);

        $promoted = $service->updateContact($secondary, [
            'is_primary' => true,
            'title' => 'VP Engineering',
        ]);

        expect($promoted->is_primary)->toBeTrue()
            ->and($promoted->title)->toBe('VP Engineering')
            ->and($existingPrimary->fresh()->is_primary)->toBeFalse();
    });
});
