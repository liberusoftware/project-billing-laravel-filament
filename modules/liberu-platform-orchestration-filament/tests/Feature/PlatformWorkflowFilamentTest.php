<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Platform\PlatformOrchestration\Filament\PlatformOrchestrationFilamentPlugin;
use Liberu\Platform\PlatformOrchestration\Filament\PlatformOrchestrationFilamentServiceProvider;
use Liberu\Platform\PlatformOrchestration\Filament\Resources\PlatformWorkflowResource;
use Liberu\Platform\PlatformOrchestration\Filament\Resources\PlatformWorkflowResource\Pages\CreatePlatformWorkflow;
use Liberu\Platform\PlatformOrchestration\Filament\Resources\PlatformWorkflowResource\Pages\EditPlatformWorkflow;
use Liberu\Platform\PlatformOrchestration\Filament\Resources\PlatformWorkflowResource\Pages\ListPlatformWorkflows;
use Liberu\Platform\PlatformOrchestration\Models\PlatformWorkflow;
use Symfony\Component\HttpKernel\Exception\HttpException;

afterEach(function (): void {
    Filament::setTenant(null);
});

it('registers the filament plugin and configures the platform workflow resource and pages', function (): void {
    expect(class_exists(PlatformOrchestrationFilamentServiceProvider::class))->toBeTrue();

    $plugin = PlatformOrchestrationFilamentPlugin::make();
    expect($plugin->getId())->toBe('liberu-platform-orchestration-filament');

    $panel = Panel::make()->id('test-orchestration-panel');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($panel->getResources())->toContain(PlatformWorkflowResource::class);

    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    PlatformWorkflow::query()->create([
        'tenant_id' => (string) $team->getKey(),
        'name' => 'Tenant Workflow',
        'status' => 'draft',
    ]);
    PlatformWorkflow::query()->create([
        'tenant_id' => (string) $otherUser->currentTeam->getKey(),
        'name' => 'Other Tenant Workflow',
        'status' => 'draft',
    ]);

    Filament::setTenant(null);
    expect(fn () => PlatformWorkflowResource::getEloquentQuery())->toThrow(HttpException::class);

    $this->actingAs($user);
    Filament::setTenant($team);
    expect(PlatformWorkflowResource::getEloquentQuery()->count())->toBe(1);

    $schema = PlatformWorkflowResource::form(Schema::make());
    expect($schema->getComponents())->toHaveCount(2);

    $listPage = new ListPlatformWorkflows();
    $table = PlatformWorkflowResource::table(Table::make($listPage));
    expect($table->getColumns())->toHaveCount(3);

    $pages = PlatformWorkflowResource::getPages();
    expect(array_keys($pages))->toBe(['index', 'create', 'edit']);

    $createPage = new CreatePlatformWorkflow();
    $mutate = Closure::bind(fn (array $data): array => $this->mutateFormDataBeforeCreate($data), $createPage, CreatePlatformWorkflow::class);

    expect($mutate(['name' => 'New Workflow', 'status' => 'draft']))
        ->toMatchArray(['name' => 'New Workflow', 'status' => 'draft', 'tenant_id' => (string) $team->getKey()]);

    Filament::setTenant(null);
    expect(fn () => $mutate(['name' => 'Blocked']))->toThrow(HttpException::class);

    $editPage = new EditPlatformWorkflow();
    $editActions = Closure::bind(fn (): array => $this->getHeaderActions(), $editPage, EditPlatformWorkflow::class)();
    $listActions = Closure::bind(fn (): array => $this->getHeaderActions(), $listPage, ListPlatformWorkflows::class)();

    expect($editActions)->toHaveCount(1)
        ->and($listActions)->toHaveCount(1);
});
