<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\BusinessWorkflowReconciliationFilamentPlugin;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\BusinessWorkflowReconciliationFilamentServiceProvider;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\Resources\ReconciliationCaseResource;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\Resources\ReconciliationCaseResource\Pages\CreateReconciliationCase;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\Resources\ReconciliationCaseResource\Pages\EditReconciliationCase;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\Resources\ReconciliationCaseResource\Pages\ListReconciliationCases;
use Liberu\Platform\BusinessWorkflowReconciliation\Models\ReconciliationCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

afterEach(function (): void {
    Filament::setTenant(null);
});

it('registers the filament plugin and configures the reconciliation case resource and pages', function (): void {
    expect(class_exists(BusinessWorkflowReconciliationFilamentServiceProvider::class))->toBeTrue();

    $plugin = BusinessWorkflowReconciliationFilamentPlugin::make();
    expect($plugin->getId())->toBe('liberu-business-workflow-reconciliation-filament');

    $panel = Panel::make()->id('test-reconciliation-panel');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($panel->getResources())->toContain(ReconciliationCaseResource::class);

    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    ReconciliationCase::query()->create([
        'tenant_id' => (string) $team->getKey(),
        'name' => 'Tenant Case',
        'status' => 'draft',
    ]);
    ReconciliationCase::query()->create([
        'tenant_id' => (string) $otherUser->currentTeam->getKey(),
        'name' => 'Other Tenant Case',
        'status' => 'draft',
    ]);

    Filament::setTenant(null);
    expect(fn () => ReconciliationCaseResource::getEloquentQuery())->toThrow(HttpException::class);

    $this->actingAs($user);
    Filament::setTenant($team);
    expect(ReconciliationCaseResource::getEloquentQuery()->count())->toBe(1);

    $schema = ReconciliationCaseResource::form(Schema::make());
    expect($schema->getComponents())->toHaveCount(2);

    $listPage = new ListReconciliationCases();
    $table = ReconciliationCaseResource::table(Table::make($listPage));
    expect($table->getColumns())->toHaveCount(3);

    $pages = ReconciliationCaseResource::getPages();
    expect(array_keys($pages))->toBe(['index', 'create', 'edit']);

    $createPage = new CreateReconciliationCase();
    $mutate = Closure::bind(fn (array $data): array => $this->mutateFormDataBeforeCreate($data), $createPage, CreateReconciliationCase::class);

    expect($mutate(['name' => 'New Case', 'status' => 'draft']))
        ->toMatchArray(['name' => 'New Case', 'status' => 'draft', 'tenant_id' => (string) $team->getKey()]);

    Filament::setTenant(null);
    expect(fn () => $mutate(['name' => 'Blocked']))->toThrow(HttpException::class);

    $editPage = new EditReconciliationCase();
    $editActions = Closure::bind(fn (): array => $this->getHeaderActions(), $editPage, EditReconciliationCase::class)();
    $listActions = Closure::bind(fn (): array => $this->getHeaderActions(), $listPage, ListReconciliationCases::class)();

    expect($editActions)->toHaveCount(1)
        ->and($listActions)->toHaveCount(1);
});
