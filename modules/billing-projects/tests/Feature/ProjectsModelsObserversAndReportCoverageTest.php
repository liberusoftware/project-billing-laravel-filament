<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectMessage;
use App\Models\Task;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ProjectReportService;
use Filament\Facades\Filament;

it('resolves Project creator and tickets relationships', function (): void {
    $creator = User::factory()->create();
    $project = Project::factory()->create(['created_by' => $creator->id]);
    $ticket = Ticket::factory()->create(['project_id' => $project->id]);

    expect($project->creator)->not->toBeNull()
        ->and($project->creator->is($creator))->toBeTrue()
        ->and($project->tickets)->toHaveCount(1)
        ->and($project->tickets->first()->is($ticket))->toBeTrue();
});

it('resolves ProjectFile uploader and ProjectMessage project and author relationships', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $file = ProjectFile::factory()->create([
        'project_id' => $project->id,
        'uploaded_by' => $user->id,
    ]);

    $message = ProjectMessage::factory()->create([
        'project_id' => $project->id,
        'author_id' => $user->id,
        'author_type' => 'staff',
    ]);

    expect($file->uploader)->not->toBeNull()
        ->and($file->uploader->is($user))->toBeTrue()
        ->and($message->project->is($project))->toBeTrue()
        ->and($message->author->is($user))->toBeTrue();
});

it('resolves Task completed scope, relationships, and TimeEntry user relationship', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $completedTask = Task::factory()->create([
        'project_id' => $project->id,
        'assigned_to' => $user->id,
        'is_complete' => true,
        'completed_at' => now(),
    ]);
    $incompleteTask = Task::factory()->create([
        'project_id' => $project->id,
        'is_complete' => false,
    ]);

    $entry = TimeEntry::factory()->create([
        'task_id' => $completedTask->id,
        'user_id' => $user->id,
    ]);

    $completedIds = Task::query()->completed()->pluck('id')->all();

    expect($completedIds)->toContain($completedTask->id)
        ->and($completedIds)->not->toContain($incompleteTask->id)
        ->and($completedTask->project->is($project))->toBeTrue()
        ->and($completedTask->assignee)->not->toBeNull()
        ->and($completedTask->assignee->is($user))->toBeTrue()
        ->and($completedTask->timeEntries)->toHaveCount(1)
        ->and($completedTask->timeEntries->first()->is($entry))->toBeTrue()
        ->and($entry->user->is($user))->toBeTrue();
});

it('records audit logs when a task or time entry is deleted', function (): void {
    $task = Task::factory()->create();
    $timeEntry = TimeEntry::factory()->create(['task_id' => $task->id]);

    $timeEntryId = $timeEntry->getKey();
    $taskId = $task->getKey();

    $timeEntry->delete();
    $task->delete();

    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => TimeEntry::class,
        'auditable_id' => $timeEntryId,
        'event' => 'time_entry_deleted',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => Task::class,
        'auditable_id' => $taskId,
        'event' => 'task_deleted',
    ]);
});

it('resolves team id from active Filament tenant or fails closed when no tenant is set', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    Project::factory()->create([
        'team_id' => $team->id,
        'status' => ProjectStatus::Open,
    ]);

    $service = new ProjectReportService();

    Filament::setTenant(null);
    expect($service->projectCountByStatus())->toBe([]);

    $this->actingAs($user);
    Filament::setTenant($team);
    expect($service->projectCountByStatus())->toBe([ProjectStatus::Open->value => 1]);
});
