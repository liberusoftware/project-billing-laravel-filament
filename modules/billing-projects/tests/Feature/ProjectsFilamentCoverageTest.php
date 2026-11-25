<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\Pages\ListProjects;
use App\Filament\Admin\Resources\Projects\RelationManagers\FilesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\InvoicesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\MessagesRelationManager as AdminMessagesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\NotesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Widgets\ProjectStatsWidget;
use App\Filament\Client\Resources\ProjectResource as ClientProjectResource;
use App\Filament\Client\Resources\ProjectResource\Pages\ViewProject as ClientViewProject;
use App\Filament\Client\Resources\ProjectResource\RelationManagers\MessagesRelationManager as ClientMessagesRelationManager;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectMessage;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function actAsAdminProjectUser(): User
{
    $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->withPersonalTeam()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($user->currentTeam);

    return $user;
}

it('renders Admin ListProjects page with table columns, status filter, and CreateAction', function (): void {
    $user = actAsAdminProjectUser();
    $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);

    $openProject = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'customer_id' => $customer->id,
        'name' => 'Alpha Portal',
        'status' => ProjectStatus::Open,
        'due_date' => '2026-09-15',
    ]);
    $completedProject = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'customer_id' => $customer->id,
        'name' => 'Beta Migration',
        'status' => ProjectStatus::Completed,
    ]);

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertActionExists(CreateAction::class)
        ->assertCanSeeTableRecords([$openProject, $completedProject])
        ->filterTable('status', ProjectStatus::Open->value)
        ->assertCanSeeTableRecords([$openProject])
        ->assertCanNotSeeTableRecords([$completedProject]);
});

it('renders Admin FilesRelationManager and creates a file with metadata', function (): void {
    Storage::fake('local');

    $user = actAsAdminProjectUser();
    $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
    $project = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'customer_id' => $customer->id,
    ]);

    $existingFile = ProjectFile::factory()->create([
        'project_id' => $project->id,
        'uploaded_by' => $user->id,
        'original_name' => 'brief.pdf',
        'size' => 2048,
        'customer_visible' => true,
    ]);

    Livewire::test(FilesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$existingFile])
        ->callAction(TestAction::make(CreateAction::getDefaultName())->table(), data: [
            'path' => UploadedFile::fake()->create('spec.pdf', 4, 'application/pdf'),
            'customer_visible' => true,
        ])
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('project_files', [
        'project_id' => $project->id,
        'uploaded_by' => $user->id,
        'original_name' => 'spec.pdf',
        'customer_visible' => true,
    ]);
});

it('renders Admin InvoicesRelationManager across all status badge colors', function (): void {
    $user = actAsAdminProjectUser();
    $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
    $project = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'customer_id' => $customer->id,
    ]);

    $paid = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'project_id' => $project->id,
        'status' => 'paid',
    ]);
    $overdue = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'project_id' => $project->id,
        'status' => 'overdue',
    ]);
    $partiallyPaid = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'project_id' => $project->id,
        'status' => 'partially_paid',
    ]);
    $pending = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'project_id' => $project->id,
        'status' => 'pending',
    ]);

    Livewire::test(InvoicesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$paid, $overdue, $partiallyPaid, $pending]);
});

it('renders Admin MessagesRelationManager and posts a staff message', function (): void {
    $user = actAsAdminProjectUser();
    $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
    $project = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'customer_id' => $customer->id,
    ]);

    $existing = ProjectMessage::factory()->create([
        'project_id' => $project->id,
        'author_id' => $user->id,
        'author_type' => 'staff',
        'body' => 'Initial update',
    ]);

    Livewire::test(AdminMessagesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$existing])
        ->callAction(TestAction::make(CreateAction::getDefaultName())->table(), data: [
            'body' => 'Follow-up from staff',
        ])
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('project_messages', [
        'project_id' => $project->id,
        'author_id' => $user->id,
        'author_type' => 'staff',
        'body' => 'Follow-up from staff',
    ]);
});

it('renders Admin NotesRelationManager and creates a project note', function (): void {
    $user = actAsAdminProjectUser();
    $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
    $project = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'customer_id' => $customer->id,
    ]);

    $existing = ProjectNote::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'body' => 'Existing internal note',
    ]);

    Livewire::test(NotesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$existing])
        ->callAction(TestAction::make(CreateAction::getDefaultName())->table(), data: [
            'body' => 'New internal note',
        ])
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('project_notes', [
        'project_id' => $project->id,
        'user_id' => $user->id,
        'body' => 'New internal note',
    ]);
});

it('renders Admin TasksRelationManager, creates a task, and completes an open task', function (): void {
    $user = actAsAdminProjectUser();
    $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
    $project = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'customer_id' => $customer->id,
    ]);

    $openTask = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Configure DNS',
        'priority' => TaskPriority::High,
        'is_complete' => false,
    ]);
    $doneTask = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Provision server',
        'priority' => TaskPriority::Low,
        'is_complete' => true,
        'completed_at' => now(),
    ]);

    Livewire::test(TasksRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => EditProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$openTask, $doneTask])
        ->callAction(TestAction::make(CreateAction::getDefaultName())->table(), data: [
            'title' => 'Launch site',
            'priority' => TaskPriority::Medium->value,
            'due_date' => '2026-10-01',
        ])
        ->assertHasNoFormErrors()
        ->callAction(TestAction::make('complete')->table($openTask));

    expect($openTask->fresh()->is_complete)->toBeTrue();
    $this->assertDatabaseHas('tasks', [
        'project_id' => $project->id,
        'title' => 'Launch site',
        'priority' => TaskPriority::Medium->value,
    ]);
});

it('renders Admin ProjectStatsWidget with project count and billable split', function (): void {
    $user = actAsAdminProjectUser();
    $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
    $project = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'customer_id' => $customer->id,
        'status' => ProjectStatus::Open,
    ]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'user_id' => $user->id,
        'duration_seconds' => 7200,
        'is_billable' => true,
    ]);
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'user_id' => $user->id,
        'duration_seconds' => 1800,
        'is_billable' => false,
    ]);

    Livewire::test(ProjectStatsWidget::class)
        ->assertSuccessful()
        ->assertSee('Projects')
        ->assertSee('Billable hours')
        ->assertSee('2.0')
        ->assertSee('Non-billable hours')
        ->assertSee('0.5');
});

it('denies creation on Client ProjectResource and allows posting messages in Client MessagesRelationManager', function (): void {
    expect(ClientProjectResource::canCreate())->toBeFalse();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $customer = Customer::factory()->create([
        'team_id' => $team->id,
        'email' => $user->email,
    ]);
    $project = Project::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
    ]);
    $existing = ProjectMessage::factory()->create([
        'project_id' => $project->id,
        'author_id' => $user->id,
        'author_type' => 'customer',
        'body' => 'Existing client message',
    ]);

    $this->actingAs($user);
    $panel = Filament::getPanel('client');
    Filament::setCurrentPanel($panel);
    $panel->boot();

    Livewire::test(ClientMessagesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ClientViewProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$existing])
        ->callAction(TestAction::make(CreateAction::getDefaultName())->table(), data: [
            'body' => 'Question from client portal',
        ])
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('project_messages', [
        'project_id' => $project->id,
        'author_id' => $user->id,
        'author_type' => 'customer',
        'body' => 'Question from client portal',
    ]);
});
