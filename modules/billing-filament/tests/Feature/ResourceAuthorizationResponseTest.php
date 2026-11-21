<?php

use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Client\Resources\InvoiceResource;
use App\Filament\Client\Resources\LicenseResource;
use App\Filament\Client\Resources\ProjectResource;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate as AuthorizationGate;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

it('forbids client-side creation even when a generic policy permits it', function (string $resource) {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Gate::before(fn () => true);

    expect(fn () => $resource::getCreateAuthorizationResponse()->authorize())->toThrow(AuthorizationException::class);
})->with([InvoiceResource::class, LicenseResource::class, ProjectResource::class]);

it('rejects unauthenticated invoice access despite an allowing policy', function () {
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Gate::before(fn () => true);

    expect(InvoiceResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue();
});

it('enforces invoice ownership in native authorization responses', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Gate::before(fn () => true);
    $own = new Invoice()->setRelation('customer', new Customer()->forceFill(['user_id' => $user->id]));
    $foreign = new Invoice()->setRelation('customer', new Customer()->forceFill(['user_id' => $user->id + 1]));

    expect(InvoiceResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(InvoiceResource::getViewAuthorizationResponse($own)->allowed())->toBeTrue()
        ->and(InvoiceResource::getViewAuthorizationResponse($foreign)->denied())->toBeTrue();

    $gate = new AuthorizationGate($this->app, fn () => $user);
    $gate->before(fn () => false);
    Gate::swap($gate);

    expect(InvoiceResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue()
        ->and(InvoiceResource::getViewAuthorizationResponse($own)->denied())->toBeTrue();
});

it('blocks audit-log server access for a non-super-admin even if a policy permits it', function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Gate::before(fn () => true);

    expect(AuditLogResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue();
});

it('retains parent-policy denials for a super-admin reading audit logs', function () {
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => getPermissionsTeamId()]);
    $user->assignRole($role);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Gate::before(fn () => true);

    expect(AuditLogResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue();
    $gate = new AuthorizationGate($this->app, fn () => $user);
    $gate->before(fn () => false);
    Gate::swap($gate);

    expect(AuditLogResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue();
});
