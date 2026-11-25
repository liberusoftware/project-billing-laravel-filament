<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\TicketCustomFields\Pages\CreateTicketCustomField;
use App\Filament\Admin\Resources\TicketCustomFields\Pages\EditTicketCustomField;
use App\Filament\Admin\Resources\TicketCustomFields\Pages\ListTicketCustomFields;
use App\Filament\Admin\Resources\TicketDepartments\Pages\EditTicketDepartment;
use App\Filament\Admin\Resources\TicketDepartments\Pages\ListTicketDepartments;
use App\Models\TicketCustomField;
use App\Models\TicketDepartment;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($this->user->currentTeam);
});

it('lists, creates, edits, and deletes ticket custom fields in Filament admin', function (): void {
    $department = TicketDepartment::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'name' => 'Technical Support',
    ]);

    $existing = TicketCustomField::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'department_id' => $department->id,
        'label' => 'Server Region',
        'type' => 'text',
        'is_required' => true,
        'is_active' => true,
    ]);

    Livewire::test(ListTicketCustomFields::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$existing])
        ->assertActionExists('create');

    Livewire::test(CreateTicketCustomField::class)
        ->assertOk()
        ->fillForm([
            'label' => 'Operating System',
            'type' => 'select',
            'department_id' => $department->id,
            'options' => ['Ubuntu', 'Debian'],
            'is_required' => true,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = TicketCustomField::query()->where('label', 'Operating System')->firstOrFail();
    expect($created->type)->toBe('select')
        ->and($created->options)->toBe(['Ubuntu', 'Debian']);

    Livewire::test(EditTicketCustomField::class, ['record' => $created->getKey()])
        ->assertOk()
        ->fillForm([
            'label' => 'Server OS',
            'type' => 'text',
            'is_required' => false,
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction(DeleteAction::class);

    $this->assertDatabaseMissing('ticket_custom_fields', ['id' => $created->id]);
});

it('lists, edits, and deletes ticket departments in Filament admin', function (): void {
    $department = TicketDepartment::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'name' => 'Sales Inquiries',
        'email' => 'sales@example.test',
        'is_active' => true,
    ]);

    Livewire::test(ListTicketDepartments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$department])
        ->assertActionExists('create');

    Livewire::test(EditTicketDepartment::class, ['record' => $department->getKey()])
        ->assertOk()
        ->fillForm([
            'name' => 'Enterprise Sales',
            'email' => 'enterprise@example.test',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($department->fresh()->name)->toBe('Enterprise Sales');

    Livewire::test(EditTicketDepartment::class, ['record' => $department->getKey()])
        ->callAction(DeleteAction::class);

    $this->assertDatabaseMissing('ticket_departments', ['id' => $department->id]);
});
