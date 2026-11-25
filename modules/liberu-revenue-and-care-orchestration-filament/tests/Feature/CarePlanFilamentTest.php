<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\Resources\CarePlanResource;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\Resources\CarePlanResource\Pages\CreateCarePlan;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\Resources\CarePlanResource\Pages\EditCarePlan;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\Resources\CarePlanResource\Pages\ListCarePlans;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\RevenueAndCareOrchestrationFilamentPlugin;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\RevenueAndCareOrchestrationFilamentServiceProvider;
use Liberu\Platform\RevenueAndCareOrchestration\Models\CarePlan;
use Symfony\Component\HttpKernel\Exception\HttpException;

afterEach(function (): void {
    Filament::setTenant(null);
});

it('registers the filament plugin and configures the care plan resource and pages', function (): void {
    expect(class_exists(RevenueAndCareOrchestrationFilamentServiceProvider::class))->toBeTrue();

    $plugin = RevenueAndCareOrchestrationFilamentPlugin::make();
    expect($plugin->getId())->toBe('liberu-revenue-and-care-orchestration-filament');

    $panel = Panel::make()->id('test-care-plan-panel');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($panel->getResources())->toContain(CarePlanResource::class);

    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    CarePlan::query()->create([
        'tenant_id' => (string) $team->getKey(),
        'name' => 'Tenant Plan',
        'status' => 'draft',
    ]);
    CarePlan::query()->create([
        'tenant_id' => (string) $otherUser->currentTeam->getKey(),
        'name' => 'Other Tenant Plan',
        'status' => 'draft',
    ]);

    Filament::setTenant(null);
    expect(fn () => CarePlanResource::getEloquentQuery())->toThrow(HttpException::class);

    $this->actingAs($user);
    Filament::setTenant($team);
    expect(CarePlanResource::getEloquentQuery()->count())->toBe(1);

    $schema = CarePlanResource::form(Schema::make());
    expect($schema->getComponents())->toHaveCount(2);

    $listPage = new ListCarePlans();
    $table = CarePlanResource::table(Table::make($listPage));
    expect($table->getColumns())->toHaveCount(3);

    $pages = CarePlanResource::getPages();
    expect(array_keys($pages))->toBe(['index', 'create', 'edit']);

    $createPage = new CreateCarePlan();
    $mutate = Closure::bind(fn (array $data): array => $this->mutateFormDataBeforeCreate($data), $createPage, CreateCarePlan::class);

    expect($mutate(['name' => 'New Plan', 'status' => 'draft']))
        ->toMatchArray(['name' => 'New Plan', 'status' => 'draft', 'tenant_id' => (string) $team->getKey()]);

    Filament::setTenant(null);
    expect(fn () => $mutate(['name' => 'Blocked']))->toThrow(HttpException::class);

    $editPage = new EditCarePlan();
    $editActions = Closure::bind(fn (): array => $this->getHeaderActions(), $editPage, EditCarePlan::class)();
    $listActions = Closure::bind(fn (): array => $this->getHeaderActions(), $listPage, ListCarePlans::class)();

    expect($editActions)->toHaveCount(1)
        ->and($listActions)->toHaveCount(1);
});
