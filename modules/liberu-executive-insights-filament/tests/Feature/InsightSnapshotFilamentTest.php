<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Platform\ExecutiveInsights\Filament\ExecutiveInsightsFilamentPlugin;
use Liberu\Platform\ExecutiveInsights\Filament\ExecutiveInsightsFilamentServiceProvider;
use Liberu\Platform\ExecutiveInsights\Filament\Resources\InsightSnapshotResource;
use Liberu\Platform\ExecutiveInsights\Filament\Resources\InsightSnapshotResource\Pages\CreateInsightSnapshot;
use Liberu\Platform\ExecutiveInsights\Filament\Resources\InsightSnapshotResource\Pages\EditInsightSnapshot;
use Liberu\Platform\ExecutiveInsights\Filament\Resources\InsightSnapshotResource\Pages\ListInsightSnapshots;
use Liberu\Platform\ExecutiveInsights\Models\InsightSnapshot;
use Symfony\Component\HttpKernel\Exception\HttpException;

afterEach(function (): void {
    Filament::setTenant(null);
});

it('registers the filament plugin and configures the insight snapshot resource and pages', function (): void {
    expect(class_exists(ExecutiveInsightsFilamentServiceProvider::class))->toBeTrue();

    $plugin = ExecutiveInsightsFilamentPlugin::make();
    expect($plugin->getId())->toBe('liberu-executive-insights-filament');

    $panel = Panel::make()->id('test-insights-panel');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($panel->getResources())->toContain(InsightSnapshotResource::class);

    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    InsightSnapshot::query()->create([
        'tenant_id' => (string) $team->getKey(),
        'name' => 'Tenant Snapshot',
        'status' => 'draft',
    ]);
    InsightSnapshot::query()->create([
        'tenant_id' => (string) $otherUser->currentTeam->getKey(),
        'name' => 'Other Tenant Snapshot',
        'status' => 'draft',
    ]);

    Filament::setTenant(null);
    expect(fn () => InsightSnapshotResource::getEloquentQuery())->toThrow(HttpException::class);

    $this->actingAs($user);
    Filament::setTenant($team);
    expect(InsightSnapshotResource::getEloquentQuery()->count())->toBe(1);

    $schema = InsightSnapshotResource::form(Schema::make());
    expect($schema->getComponents())->toHaveCount(2);

    $listPage = new ListInsightSnapshots();
    $table = InsightSnapshotResource::table(Table::make($listPage));
    expect($table->getColumns())->toHaveCount(3);

    $pages = InsightSnapshotResource::getPages();
    expect(array_keys($pages))->toBe(['index', 'create', 'edit']);

    $createPage = new CreateInsightSnapshot();
    $mutate = Closure::bind(fn (array $data): array => $this->mutateFormDataBeforeCreate($data), $createPage, CreateInsightSnapshot::class);

    expect($mutate(['name' => 'New Snapshot', 'status' => 'draft']))
        ->toMatchArray(['name' => 'New Snapshot', 'status' => 'draft', 'tenant_id' => (string) $team->getKey()]);

    Filament::setTenant(null);
    expect(fn () => $mutate(['name' => 'Blocked']))->toThrow(HttpException::class);

    $editPage = new EditInsightSnapshot();
    $editActions = Closure::bind(fn (): array => $this->getHeaderActions(), $editPage, EditInsightSnapshot::class)();
    $listActions = Closure::bind(fn (): array => $this->getHeaderActions(), $listPage, ListInsightSnapshots::class)();

    expect($editActions)->toHaveCount(1)
        ->and($listActions)->toHaveCount(1);
});
