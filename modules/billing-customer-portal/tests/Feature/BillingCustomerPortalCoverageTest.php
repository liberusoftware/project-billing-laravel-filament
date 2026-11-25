<?php

declare(strict_types=1);

use App\Enums\CustomerStatus;
use App\Http\Controllers\Api\ClientContactController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Client\ServiceManagementController as ClientServiceManagementController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientNoteController;
use App\Http\Controllers\ServiceManagementController;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientNote;
use App\Models\Customer;
use App\Models\HostingServer;
use App\Models\Lead;
use App\Models\Products_Service;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Services\BillingService;
use App\Services\ClientContactService;
use App\Services\DomainService;
use App\Services\HostingService;
use App\Services\ServiceProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

it('covers Client, Customer, and Lead model relations and Customer::transitionTo same status', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);

    $client = Client::create([
        'team_id' => $team->id,
        'name' => 'Acme Client',
        'email' => 'acme-client@example.com',
        'status' => 'active',
    ]);
    expect($client->notes()->get())->toHaveCount(0);

    $customer = Customer::factory()->create([
        'team_id' => $team->id,
        'lifecycle_status' => CustomerStatus::Active,
    ]);
    $customer->forceFill(['user_id' => $user->id])->save();

    expect($customer->transitionTo(CustomerStatus::Active))->toBeTrue()
        ->and($customer->credits()->get())->toHaveCount(0)
        ->and($customer->quotes()->get())->toHaveCount(0)
        ->and($customer->subscriptions()->get())->toHaveCount(0)
        ->and($customer->ispServices()->get())->toHaveCount(0)
        ->and($customer->voipAccounts()->get())->toHaveCount(0)
        ->and($customer->user)->toBeInstanceOf(User::class);

    $lead = Lead::create([
        'team_id' => $team->id,
        'name' => 'Test Lead',
        'email' => 'lead@example.com',
        'status' => 'new',
    ]);
    expect($lead->team->id)->toBe($team->id);
});

it('covers CustomerPolicy methods', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $policy = new CustomerPolicy;

    expect($policy->viewAny($user))->toBeBool()
        ->and($policy->view($user, $customer))->toBeBool()
        ->and($policy->create($user))->toBeBool()
        ->and($policy->update($user, $customer))->toBeBool()
        ->and($policy->delete($user, $customer))->toBeBool()
        ->and($policy->deleteAny($user))->toBeBool()
        ->and($policy->forceDelete($user, $customer))->toBeBool()
        ->and($policy->forceDeleteAny($user))->toBeBool()
        ->and($policy->restore($user, $customer))->toBeBool()
        ->and($policy->restoreAny($user))->toBeBool()
        ->and($policy->replicate($user, $customer))->toBeBool()
        ->and($policy->reorder($user))->toBeBool();
});

it('covers ClientContactService::updateContact promoting a contact to primary', function (): void {
    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $service = app(ClientContactService::class);

    $first = $service->createContact($customer, [
        'first_name' => 'Alice',
        'last_name' => 'One',
        'email' => 'alice@example.com',
        'is_primary' => true,
    ]);
    $second = $service->createContact($customer, [
        'first_name' => 'Bob',
        'last_name' => 'Two',
        'email' => 'bob@example.com',
        'is_primary' => false,
    ]);

    $updated = $service->updateContact($second, ['is_primary' => true, 'title' => 'CTO']);
    expect($updated->is_primary)->toBeTrue()
        ->and($first->fresh()->is_primary)->toBeFalse();
});

it('covers Api ClientContactController full CRUD, makePrimary, and error branches', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $otherCustomer = Customer::factory()->create(['team_id' => $team->id]);

    $controller = app(ClientContactController::class);

    $makeReq = function (string $method, array $data = []) use ($user): Request {
        $req = Request::create('/api/customers/1/contacts', $method, $data);
        $req->setUserResolver(fn () => $user);

        return $req;
    };

    // store primary
    $storePrimaryRes = $controller->store($makeReq('POST', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'is_primary' => true,
    ]), $customer);
    expect($storePrimaryRes->getStatusCode())->toBe(201);
    $primary = ClientContact::findOrFail($storePrimaryRes->getData(true)['data']['id']);

    // store secondary
    $storeSecRes = $controller->store($makeReq('POST', [
        'first_name' => 'John',
        'last_name' => 'Smith',
        'email' => 'john@example.com',
        'is_primary' => false,
    ]), $customer);
    expect($storeSecRes->getStatusCode())->toBe(201);
    $secondary = ClientContact::findOrFail($storeSecRes->getData(true)['data']['id']);

    // index
    $indexRes = $controller->index($makeReq('GET'), $customer);
    expect($indexRes->getStatusCode())->toBe(200)
        ->and($indexRes->getData(true)['data'])->toHaveCount(2);

    // show success + mismatch 404
    expect($controller->show($makeReq('GET'), $customer, $primary)->getStatusCode())->toBe(200);
    expect(fn () => $controller->show($makeReq('GET'), $otherCustomer, $primary))->toThrow(HttpException::class);

    // update success + mismatch 404
    $updateRes = $controller->update($makeReq('PUT', ['first_name' => 'Janet']), $customer, $secondary);
    expect($updateRes->getStatusCode())->toBe(200)
        ->and($updateRes->getData(true)['data']['first_name'])->toBe('Janet');
    expect(fn () => $controller->update($makeReq('PUT', ['first_name' => 'X']), $otherCustomer, $secondary))->toThrow(HttpException::class);

    // makePrimary success + mismatch 404
    $mpRes = $controller->makePrimary($makeReq('POST'), $customer, $secondary);
    expect($mpRes->getStatusCode())->toBe(200)
        ->and($mpRes->getData(true)['message'])->toBe('Contact set as primary.');
    expect(fn () => $controller->makePrimary($makeReq('POST'), $otherCustomer, $secondary))->toThrow(HttpException::class);

    // destroy primary -> 422
    $failDelRes = $controller->destroy($makeReq('DELETE'), $customer, $secondary->fresh());
    expect($failDelRes->getStatusCode())->toBe(422);

    // destroy non-primary -> 204
    $okDelRes = $controller->destroy($makeReq('DELETE'), $customer, $primary->fresh());
    expect($okDelRes->getStatusCode())->toBe(204);

    // destroy mismatch -> 404
    expect(fn () => $controller->destroy($makeReq('DELETE'), $otherCustomer, $secondary))->toThrow(HttpException::class);
});

it('covers Api CustomerController status change, invoices, and subscriptions', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $customer = Customer::factory()->create([
        'team_id' => $team->id,
        'lifecycle_status' => CustomerStatus::Lead,
    ]);

    Gate::before(fn () => true);
    $controller = app(CustomerController::class);

    $updateReq = Request::create('/api/customers/'.$customer->id, 'PUT', [
        'lifecycle_status' => CustomerStatus::Active->value,
    ]);
    $updateReq->setUserResolver(fn () => $user);

    $updateRes = $controller->update($updateReq, $customer);
    expect($updateRes->getStatusCode())->toBe(200)
        ->and($customer->fresh()->status_changed_at)->not->toBeNull();

    $getReq = Request::create('/api/customers/'.$customer->id.'/invoices', 'GET');
    $getReq->setUserResolver(fn () => $user);

    expect($controller->invoices($getReq, $customer)->getStatusCode())->toBe(200)
        ->and($controller->subscriptions($getReq, $customer)->getStatusCode())->toBe(200);
});

it('covers ClientController full CRUD and search/status filters', function (): void {
    $tmpViews = sys_get_temp_dir().'/billing_client_views_'.uniqid();
    mkdir($tmpViews.'/clients', 0700, true);
    file_put_contents($tmpViews.'/clients/index.blade.php', 'clients-index');
    file_put_contents($tmpViews.'/clients/create.blade.php', 'clients-create');
    file_put_contents($tmpViews.'/clients/edit.blade.php', 'clients-edit');
    View::addLocation($tmpViews);

    try {
        $controller = new ClientController;

        // store
        $storeReq = Request::create('/clients', 'POST', [
            'name' => 'Beta Corp',
            'email' => 'beta@example.com',
            'phone' => '123456',
            'company' => 'Beta Inc',
            'address' => '123 St',
            'notes' => 'VIP',
            'status' => 'active',
        ]);
        $storeRes = $controller->store($storeReq);
        expect($storeRes->getStatusCode())->toBe(302);
        $client = Client::where('email', 'beta@example.com')->firstOrFail();

        // index with search and status
        $indexReq = Request::create('/clients', 'GET', ['search' => 'Beta', 'status' => 'active']);
        $indexView = $controller->index($indexReq);
        expect($indexView->render())->toBe('clients-index');

        // create & edit
        expect($controller->create()->render())->toBe('clients-create')
            ->and($controller->edit($client)->render())->toBe('clients-edit');

        // update
        $updateReq = Request::create('/clients/'.$client->id, 'PUT', [
            'name' => 'Beta Corp Updated',
            'email' => 'beta@example.com',
            'status' => 'inactive',
        ]);
        expect($controller->update($updateReq, $client)->getStatusCode())->toBe(302)
            ->and($client->fresh()->name)->toBe('Beta Corp Updated');

        // destroy
        expect($controller->destroy($client)->getStatusCode())->toBe(302)
            ->and(Client::find($client->id))->toBeNull();
    } finally {
        @unlink($tmpViews.'/clients/index.blade.php');
        @unlink($tmpViews.'/clients/create.blade.php');
        @unlink($tmpViews.'/clients/edit.blade.php');
        @rmdir($tmpViews.'/clients');
        @rmdir($tmpViews);
    }
});

it('covers ClientNoteController content search, dateRange branches, and suggestions', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $client = Client::create([
        'team_id' => $team->id,
        'name' => 'Note Client',
        'email' => 'note-client@example.com',
        'status' => 'active',
    ]);
    ClientNote::create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'content' => 'Follow up on invoice',
    ]);

    $controller = new ClientNoteController;

    foreach (['today', 'week', 'month', 'custom'] as $range) {
        $params = [
            'client_id' => $client->id,
            'content' => 'Follow up',
            'dateRange' => $range,
        ];
        if ($range === 'custom') {
            $params['startDate'] = now()->subDay()->toDateString();
            $params['endDate'] = now()->addDay()->toDateString();
        }
        $req = Request::create('/api/client-notes', 'GET', $params);
        $req->setUserResolver(fn () => $user);

        $res = $controller->index($req);
        expect($res->getStatusCode())->toBe(200);
    }

    $sugReq = Request::create('/api/search-suggestions', 'GET', ['query' => 'Follow']);
    $sugRes = $controller->suggestions($sugReq);
    expect($sugRes->getStatusCode())->toBe(200)
        ->and($sugRes->getData(true))->toHaveCount(1);
});

it('covers ServiceManagementController provisionService and manageService success and failure', function (): void {
    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $subscription = Subscription::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
    ]);

    $mock = Mockery::mock(ServiceProvisioningService::class);
    $mock->shouldReceive('provisionService')->once()->andReturn(['status' => 'provisioned']);
    $mock->shouldReceive('provisionService')->once()->andThrow(new Exception('Provision error'));
    $mock->shouldReceive('manageService')->once()->andReturn(['status' => 'suspended']);
    $mock->shouldReceive('manageService')->once()->andThrow(new Exception('Manage error'));

    $controller = new ServiceManagementController($mock);

    $provReq = Request::create('/provision', 'POST', ['subscription_id' => $subscription->id]);
    expect($controller->provisionService($provReq)->getStatusCode())->toBe(200)
        ->and($controller->provisionService($provReq)->getStatusCode())->toBe(400);

    $manageReq = Request::create('/manage', 'POST', [
        'subscription_id' => $subscription->id,
        'action' => 'suspend',
    ]);
    expect($controller->manageService($manageReq)->getStatusCode())->toBe(200)
        ->and($controller->manageService($manageReq)->getStatusCode())->toBe(400);
});

it('covers Client ServiceManagementController index, show, downgrade, cancel, and ownership 404', function (): void {
    $tmpViews = sys_get_temp_dir().'/billing_client_svc_views_'.uniqid();
    mkdir($tmpViews.'/client/services', 0700, true);
    file_put_contents($tmpViews.'/client/services/index.blade.php', 'client-services-index');
    file_put_contents($tmpViews.'/client/services/show.blade.php', 'client-services-show');
    View::addLocation($tmpViews);

    try {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $otherUser = User::factory()->create(['current_team_id' => $team->id]);

        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $customer->forceFill(['user_id' => $user->id])->save();

        $server = HostingServer::factory()->create(['team_id' => $team->id]);

        $higherPlan = Products_Service::factory()->create([
            'team_id' => $team->id,
            'hosting_server_id' => $server->id,
            'type' => 'hosting',
            'base_price' => 50.00,
        ]);
        $lowerPlan = Products_Service::factory()->create([
            'team_id' => $team->id,
            'hosting_server_id' => $server->id,
            'type' => 'hosting',
            'base_price' => 20.00,
        ]);

        $subscription = Subscription::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'product_service_id' => $higherPlan->id,
            'status' => 'active',
            'start_date' => now()->subDays(5),
            'end_date' => now()->addDays(25),
            'scheduled_change' => null,
        ]);

        $controller = new ClientServiceManagementController(
            Mockery::mock(BillingService::class),
            Mockery::mock(HostingService::class),
            Mockery::mock(DomainService::class),
        );

        // Unauthorized user -> NotFoundHttpException
        $this->actingAs($otherUser);
        expect(fn () => $controller->show($subscription))->toThrow(NotFoundHttpException::class);

        // Authorized user -> index, show, downgrade, cancel
        $this->actingAs($user);
        expect($controller->index()->render())->toBe('client-services-index')
            ->and($controller->show($subscription)->render())->toBe('client-services-show');

        $downgradeReq = Request::create('/client/services/'.$subscription->id.'/downgrade', 'POST', [
            'new_service_id' => $lowerPlan->id,
        ]);
        $downgradeRes = $controller->downgrade($downgradeReq, $subscription);
        expect($downgradeRes->getStatusCode())->toBe(302)
            ->and($subscription->fresh()->scheduled_change['type'])->toBe('downgrade');

        // Clear scheduled_change so cancel can proceed
        $subscription->update(['scheduled_change' => null]);
        $cancelRes = $controller->cancel($subscription->fresh());
        expect($cancelRes->getStatusCode())->toBe(302)
            ->and($subscription->fresh()->scheduled_change['type'])->toBe('cancel');
    } finally {
        @unlink($tmpViews.'/client/services/index.blade.php');
        @unlink($tmpViews.'/client/services/show.blade.php');
        @rmdir($tmpViews.'/client/services');
        @rmdir($tmpViews);
    }
});
