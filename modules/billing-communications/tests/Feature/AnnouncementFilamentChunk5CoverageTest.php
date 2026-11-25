<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($user->currentTeam);
});

test('admin can list, search, and filter announcements', function (): void {
    $announcement = Announcement::factory()->published()->create([
        'title' => 'Platform Upgrade Notice',
        'type' => 'announcement',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay(),
    ]);

    $statusNotice = Announcement::factory()->create([
        'title' => 'Core Router Maintenance',
        'type' => 'network_status',
        'is_published' => false,
    ]);

    Livewire::test(ListAnnouncements::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$announcement, $statusNotice])
        ->searchTable('Platform Upgrade')
        ->assertCanSeeTableRecords([$announcement])
        ->assertCanNotSeeTableRecords([$statusNotice])
        ->searchTable('')
        ->filterTable('type', 'network_status')
        ->assertCanSeeTableRecords([$statusNotice])
        ->assertCanNotSeeTableRecords([$announcement]);
});

test('admin can edit and delete an announcement', function (): void {
    $announcement = Announcement::factory()->create([
        'title' => 'Initial Announcement',
        'body' => 'Original body text.',
        'type' => 'announcement',
        'is_published' => false,
    ]);

    Livewire::test(EditAnnouncement::class, ['record' => $announcement->getRouteKey()])
        ->assertSuccessful()
        ->fillForm([
            'title' => 'Updated Announcement',
            'body' => 'Updated body text.',
            'type' => 'network_status',
            'is_published' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('announcements', [
        'id' => $announcement->id,
        'title' => 'Updated Announcement',
        'type' => 'network_status',
        'is_published' => true,
    ]);

    Livewire::test(EditAnnouncement::class, ['record' => $announcement->getRouteKey()])
        ->callAction(DeleteAction::class);

    $this->assertDatabaseMissing('announcements', [
        'id' => $announcement->id,
    ]);
});
