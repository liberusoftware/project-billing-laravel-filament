<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\CustomerPortal\Filament\CustomerPortalFilamentPlugin;
use Liberu\Billing\CustomerPortal\Filament\Resources\PortalItemResource;
use Liberu\Billing\CustomerPortal\Filament\Resources\PortalItemResource\Pages\CreatePortalItem;
use Liberu\Billing\CustomerPortal\Filament\Resources\PortalItemResource\Pages\ListPortalItems;
use Liberu\Billing\CustomerPortal\Filament\Resources\PortalRequestResource;
use Liberu\Billing\CustomerPortal\Filament\Resources\PortalRequestResource\Pages\CreatePortalRequest;
use Liberu\Billing\CustomerPortal\Filament\Resources\PortalRequestResource\Pages\ListPortalRequests;
use Liberu\Billing\CustomerPortal\Models\PortalItem;
use Liberu\Billing\CustomerPortal\Models\PortalRequest;
use Livewire\Livewire;

it('manages portal items and requests through Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $team = $actor->ownedTeams()->sole();
    $actor->switchTeam($team);
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.customer-portal.read', 'billing.customer-portal.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($team);
    CustomerPortalFilamentPlugin::make()->boot($panel);

    Livewire::test(CreatePortalItem::class)
        ->fillForm([
            'type' => 'tickets',
            'subject' => 'Filament Portal Item',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = PortalItem::query()->where('subject', 'Filament Portal Item')->sole();

    Livewire::test(ListPortalItems::class)
        ->assertCanSeeTableRecords([$item])
        ->callTableAction('status', $item, data: ['status' => 'completed']);

    expect($item->fresh()->status)->toBe('completed');

    Livewire::test(CreatePortalRequest::class)
        ->fillForm([
            'name' => 'Filament Portal Request',
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = PortalRequest::query()->where('name', 'Filament Portal Request')->sole();

    Livewire::test(ListPortalRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->callTableAction('status', $request, data: ['status' => 'closed']);

    expect($request->fresh()->status)->toBe('closed');
});

it('scopes Filament customer portal queries when no current team is active', function (): void {
    PortalItem::query()->create([
        'team_id' => 999,
        'type' => 'tickets',
        'status' => 'open',
        'subject' => 'Other Team Item',
    ]);
    PortalRequest::query()->create([
        'team_id' => 999,
        'name' => 'Other Team Request',
        'status' => 'active',
    ]);

    $this->actingAs(User::factory()->create(), 'web');

    expect(PortalItemResource::getEloquentQuery()->count())->toBe(0)
        ->and(PortalRequestResource::getEloquentQuery()->count())->toBe(0);
});
