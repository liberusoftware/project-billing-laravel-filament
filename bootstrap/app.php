<?php

use App\Console\Commands\DispatchHostingFulfillments;
use App\Console\Commands\EscalateTickets;
use App\Console\Commands\ImportUsage;
use App\Console\Commands\MakeModuleCommand;
use App\Console\Commands\ModuleAutoloadCommand;
use App\Console\Commands\ModuleCommand;
use App\Console\Commands\ProcessDomainRenewals;
use App\Console\Commands\ProcessInvoiceReminders;
use App\Console\Commands\ProcessSubscriptionChanges;
use App\Console\Commands\ProcessWebhooks;
use App\Console\Commands\PruneAuditLogs;
use App\Console\Commands\SendInvoiceReminders;
use App\Console\Commands\SuspendOverdueServices;
use App\Console\Commands\SyncDomains;
use App\Console\Commands\SyncEnomDomains;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Liberu\Foundation\ApiAccess\Http\Middleware\ReplayIdempotentRequest;
use Liberu\Foundation\Localization\Http\Middleware\SetLocale;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

$basePath = dirname(__DIR__);
$billingPath = $basePath.'/modules/billing-application';

return Application::configure(basePath: $basePath)
    ->withRouting(
        web: $billingPath.'/routes/web.php',
        api: $billingPath.'/routes/api.php',
        commands: $billingPath.'/routes/console.php',
        channels: $billingPath.'/routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        $billingPath.'/src/Console/Commands',
        DispatchHostingFulfillments::class,
        EscalateTickets::class,
        ImportUsage::class,
        MakeModuleCommand::class,
        ModuleAutoloadCommand::class,
        ModuleCommand::class,
        ProcessDomainRenewals::class,
        ProcessInvoiceReminders::class,
        ProcessSubscriptionChanges::class,
        ProcessWebhooks::class,
        PruneAuditLogs::class,
        SendInvoiceReminders::class,
        SuspendOverdueServices::class,
        SyncDomains::class,
        SyncEnomDomains::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', [SetLocale::class, SecurityHeaders::class]);
        $middleware->appendToGroup('api', [SecurityHeaders::class]);
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'idempotency' => ReplayIdempotentRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->create()
    ->useAppPath($billingPath.'/src');
