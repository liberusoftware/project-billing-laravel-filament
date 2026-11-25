<?php

declare(strict_types=1);

use App\Exceptions\Handler;
use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\EnsureWorkspaceSetup;
use App\Http\Middleware\RequireTwoFactorEnabled;
use App\Http\Middleware\ScreeningDataEncryptor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustHosts;
use App\Models\BulkOperation;
use App\Models\Client;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\Menu;
use App\Models\Team;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\SocialstreamServiceProvider;
use App\Providers\TeamServiceProvider;
use App\Services\BulkOperationService;
use App\View\Components\AppLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('handles stack overflow errors and prevents recursion in Exception Handler', function (): void {
    Log::spy();

    $handler = new Handler(app());
    $handler->register();

    $handler->report(new Error('Maximum call stack size of 8339456 bytes reached'));

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $msg): bool => $msg === 'Recursion or stack overflow detected')
        ->once();

    // Exercise recursive guard branch
    $prop = new ReflectionProperty(Handler::class, 'handlingError');
    $prop->setValue($handler, true);
    $handler->report(new RuntimeException('Nested error'));
    $prop->setValue($handler, false);
});

it('revokes current and all tokens via AuthController', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('device-1');
    $user->createToken('device-2');

    Sanctum::actingAs($user, ['*']);
    $user->withAccessToken($token->accessToken);

    $request = Request::create('/api/tokens/revoke', 'POST');
    $request->setUserResolver(fn () => $user);

    $controller = new AuthController();
    $resp1 = $controller->revokeToken($request);
    expect($resp1->getData(true)['message'])->toBe('Token revoked successfully');

    $resp2 = $controller->revokeAllTokens($request);
    expect($resp2->getData(true)['message'])->toBe('All tokens revoked successfully')
        ->and($user->tokens()->count())->toBe(0);
});

it('enforces workspace setup and temporarily applies team configuration overrides', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create([
        'user_id' => $user->id,
        'setup_completed_at' => null,
        'setup_configuration' => ['stripe_secret' => 'sk_test_override_123'],
    ]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $user->setRelation('currentTeam', $team);

    $middleware = new EnsureWorkspaceSetup();

    // Unfinished setup on non-setup path redirects
    $req1 = Request::create('/app/dashboard', 'GET');
    $req1->setUserResolver(fn () => $user);
    $res1 = $middleware->handle($req1, fn () => new Response('ok'));
    expect($res1->isRedirect())->toBeTrue();

    // Setup path or completed setup applies and restores config overrides
    $originalStripe = config('services.stripe.secret');
    $team->forceFill(['setup_completed_at' => now()])->save();

    $req2 = Request::create('/app/dashboard', 'GET');
    $req2->setUserResolver(fn () => $user);
    $observedDuringRequest = null;
    $res2 = $middleware->handle($req2, function () use (&$observedDuringRequest) {
        $observedDuringRequest = config('services.stripe.secret');

        return new Response('ok');
    });

    expect($res2->getContent())->toBe('ok')
        ->and($observedDuringRequest)->toBe('sk_test_override_123')
        ->and(config('services.stripe.secret'))->toBe($originalStripe);
});

it('requires two-factor authentication when RequireTwoFactorEnabled runs', function (): void {
    $middleware = new RequireTwoFactorEnabled();

    $unauthReq = Request::create('/secure', 'GET');
    $unauthRes = $middleware->handle($unauthReq, fn () => new Response('ok'));
    expect($unauthRes->isRedirect())->toBeTrue();

    $userWith2fa = User::factory()->create(['two_factor_secret' => encrypt('SECRET')]);
    $authReq = Request::create('/secure', 'GET');
    $authReq->setUserResolver(fn () => $userWith2fa);
    $authRes = $middleware->handle($authReq, fn () => new Response('allowed'));
    expect($authRes->getContent())->toBe('allowed');
});

it('encrypts screening fields in JSON responses via ScreeningDataEncryptor', function (): void {
    $middleware = new ScreeningDataEncryptor();
    $req = Request::create('/screening', 'GET');

    $res = $middleware->handle($req, fn () => new Response(json_encode([
        'background_check_status' => 'passed',
        'credit_report_status' => 'good',
        'rental_history_status' => 'clean',
        'other' => 'plain',
    ])));

    $decoded = json_decode((string) $res->getContent(), true);
    expect(Crypt::decryptString($decoded['background_check_status']))->toBe('passed')
        ->and(Crypt::decryptString($decoded['credit_report_status']))->toBe('good')
        ->and(Crypt::decryptString($decoded['rental_history_status']))->toBe('clean')
        ->and($decoded['other'])->toBe('plain');

    $emptyRes = $middleware->handle($req, fn () => new Response(''));
    expect($emptyRes->getContent())->toBe('');
});

it('adds HSTS header for secure requests in SecurityHeaders', function (): void {
    $middleware = new SecurityHeaders();
    $req = Request::create('https://example.test/dashboard', 'GET');
    $res = $middleware->handle($req, fn () => new Response('ok'));

    expect($res->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
});

it('returns application subdomain pattern from TrustHosts', function (): void {
    $middleware = new TrustHosts(app());

    expect($middleware->hosts())->toBeArray()->toHaveCount(1);
});

it('covers BulkOperation relations, failure helpers, and progress percentage', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    $op = BulkOperation::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'type' => 'invoice_generation',
        'parameters' => [],
        'status' => 'pending',
        'total_items' => 0,
        'processed_items' => 0,
        'failed_items' => 0,
    ]);

    expect($op->user->id)->toBe($user->id)
        ->and($op->team->id)->toBe($team->id)
        ->and($op->getProgressPercentage())->toBe(0.0);

    $op->update(['total_items' => 4, 'processed_items' => 3]);
    expect($op->getProgressPercentage())->toBe(75.0);

    $op->incrementFailed();
    $op->markAsFailed('Disk full');
    $op->refresh();

    expect($op->failed_items)->toBe(1)
        ->and($op->status)->toBe('failed')
        ->and($op->error_message)->toBe('Disk full');
});

it('resolves Menu parent and children relationships', function (): void {
    $parent = Menu::create(['name' => 'Parent', 'url' => 'https://example.test', 'order' => 1]);
    $child = Menu::create(['name' => 'Child', 'url' => 'https://example.test/child', 'parent_id' => $parent->id, 'order' => 2]);

    expect($child->parent->id)->toBe($parent->id)
        ->and($parent->children->pluck('id')->all())->toBe([$child->id]);
});

it('covers service providers and AppLayout component', function (): void {
    // AppServiceProvider::configureUrl in production
    $prodApp = Mockery::mock(app())->makePartial();
    $prodApp->shouldReceive('isProduction')->andReturn(true);
    URL::shouldReceive('forceScheme')->with('https')->once();
    $appProvider = new AppServiceProvider($prodApp);
    $method = new ReflectionMethod(AppServiceProvider::class, 'configureUrl');
    $method->invoke($appProvider);

    // BroadcastServiceProvider
    (new BroadcastServiceProvider(app()))->boot();

    // HorizonServiceProvider
    $horizonProvider = new HorizonServiceProvider(app());
    $horizonProvider->boot();
    expect(Gate::allows('viewHorizon', User::factory()->make(['email' => 'nobody@example.test'])))->toBeFalse();

    // SocialstreamServiceProvider
    $socialProvider = new SocialstreamServiceProvider(app());
    $socialProvider->register();
    $socialProvider->boot();

    // TeamServiceProvider
    $teamProvider = new TeamServiceProvider(app());
    $teamProvider->register();
    $teamProvider->boot();

    // AppLayout
    $layout = new AppLayout();
    expect($layout->render()->name())->toBe('layouts.app');
});

it('covers all BulkOperationService export, import, invoice, and email campaign branches', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $service = new BulkOperationService();

    // 1. processBulkInvoiceGeneration: inner exception (duplicate invoice_number) + outer exception (missing parameters)
    $customer = Customer::factory()->create();
    $opInv = $service->bulkGenerateInvoices([$customer->id, $customer->id], [
        'invoice_number' => 'INV-DUP-1',
        'due_date' => now()->addDays(15),
        'total_amount' => 100,
        'status' => 'pending',
    ], $user->id, $team->id);
    $service->processBulkInvoiceGeneration($opInv);
    expect($opInv->fresh()->failed_items)->toBe(1);

    $badInvOp = BulkOperation::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'type' => 'invoice_generation',
        'parameters' => null,
        'status' => 'pending',
        'total_items' => 0,
    ]);
    $service->processBulkInvoiceGeneration($badInvOp);
    expect($badInvOp->fresh()->status)->toBe('failed');

    // 2. processDataExport: with team_id filter + fresh exports directory + outer exception
    $exportsDir = storage_path('app/exports');
    File::deleteDirectory($exportsDir);

    Client::create(['name' => '=SUM(1,2)', 'email' => 'formula@example.test', 'phone' => '123', 'team_id' => $team->id]);
    $exportOp = $service->exportClients(['team_id' => $team->id], $user->id, $team->id);
    expect($exportOp->fresh()->status)->toBe('completed');
    if ($exportOp->result_file) {
        @unlink(storage_path('app/'.$exportOp->result_file));
    }

    $badExportOp = BulkOperation::create([
        'user_id' => $user->id,
        'type' => 'data_export',
        'parameters' => null,
        'status' => 'pending',
    ]);
    $service->processDataExport($badExportOp);
    expect($badExportOp->fresh()->status)->toBe('failed');

    // 3. importClients & processClientImport: valid row + failing row (duplicate email / constraint) + outer exception
    $tmpCsv = tempnam(sys_get_temp_dir(), 'clients_import_');
    file_put_contents($tmpCsv, "Name,Email,Phone\nAlice,alice-import@example.test,555-0100\nBob,bob-import@example.test,555-0101\n");
    $importOp = $service->importClients($tmpCsv, $user->id, $team->id);
    expect($importOp->fresh()->status)->toBe('completed')
        ->and($importOp->fresh()->processed_items)->toBe(2);
    @unlink($tmpCsv);

    // Trigger inner catch during import by using a mock/listener that throws on creating
    $tmpCsv2 = tempnam(sys_get_temp_dir(), 'clients_import_fail_');
    file_put_contents($tmpCsv2, "Name,Email,Phone\nFailUser,fail@example.test,555\n");
    Client::creating(function (): void {
        throw new Exception('Simulated create failure');
    });
    try {
        $importFailOp = $service->importClients($tmpCsv2, $user->id, $team->id);
        expect($importFailOp->fresh()->failed_items)->toBe(1);
    } finally {
        Client::flushEventListeners();
        @unlink($tmpCsv2);
    }

    // Outer catch in processClientImport (missing parameters)
    $badImportOp = BulkOperation::create([
        'user_id' => $user->id,
        'type' => 'client_import',
        'parameters' => null,
        'status' => 'pending',
    ]);
    $service->processClientImport($badImportOp);
    expect($badImportOp->fresh()->status)->toBe('failed');

    // 4. createEmailCampaign & sendEmailCampaign
    $campaign = $service->createEmailCampaign(
        'Q3 Promo',
        'Special Offer',
        '<p>Hello</p>',
        ['team_id' => $team->id, 'status' => 'active'],
        $user->id,
        $team->id
    );
    expect($campaign)->toBeInstanceOf(EmailCampaign::class);
    $service->sendEmailCampaign($campaign);
    expect($campaign->fresh()->status)->toBe('sent');

    // Inner & outer catch in sendEmailCampaign using partial mocks
    $throwingCampaign = Mockery::mock($campaign)->makePartial();
    $throwingCampaign->shouldReceive('markAsSending')->once();
    $throwingCampaign->shouldReceive('update')->once();
    $throwingCampaign->shouldReceive('incrementSent')->andThrow(new Exception('Mail error'));
    $throwingCampaign->shouldReceive('incrementFailed')->atLeast()->once();
    $throwingCampaign->shouldReceive('markAsSent')->once();

    $serviceWithRecipients = new class extends BulkOperationService
    {
        protected function getRecipients(array $filters)
        {
            return collect([(object) ['email' => 'a@example.test']]);
        }
    };
    $serviceWithRecipients->sendEmailCampaign($throwingCampaign);

    $outerThrowingCampaign = Mockery::mock($campaign)->makePartial();
    $outerThrowingCampaign->shouldReceive('markAsSending')->once();
    $outerThrowingCampaign->shouldReceive('update')->andThrow(new Exception('DB error'));
    $serviceWithRecipients->sendEmailCampaign($outerThrowingCampaign);
});
