<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

it('returns the total user count as the navigation badge', function (): void {
    expect(UserResource::getNavigationBadge())->toBe('0');

    User::factory()->count(3)->create();

    expect(UserResource::getNavigationBadge())->toBe('3');
});

it('configures the admin user resource form schema', function (): void {
    $schema = UserResource::form(Schema::make());
    $components = $schema->getComponents();

    expect($components)->toHaveCount(1)
        ->and($components[0])->toBeInstanceOf(Tabs::class);
});

it('configures the admin users table columns, actions, sorting, and column closures', function (): void {
    $livewire = Mockery::mock(HasTable::class);
    $table = UserResource::table(Table::make($livewire));

    expect($table->getColumns())->toHaveCount(7)
        ->and($table->getFilters())->toHaveCount(4)
        ->and($table->getRecordActions())->toHaveCount(2)
        ->and($table->getToolbarActions())->toHaveCount(1)
        ->and($table->getDefaultSort(User::query(), 'desc'))->toBe('created_at')
        ->and($table->getDefaultSortDirection())->toBe('desc');

    $verifiedAt = Carbon::parse('2026-01-15 10:00:00');
    $verifiedUser = User::factory()->create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'email_verified_at' => $verifiedAt,
    ]);
    $unverifiedUser = User::factory()->unverified()->create([
        'name' => 'John Unverified',
        'email' => 'john@example.com',
    ]);

    /** @var ImageColumn $photoColumn */
    $photoColumn = $table->getColumn('profile_photo_url');
    $photoColumn->record($verifiedUser);

    expect($photoColumn)->toBeInstanceOf(ImageColumn::class)
        ->and($photoColumn->isCircular())->toBeTrue()
        ->and($photoColumn->getDefaultImageUrl())
        ->toBe('https://ui-avatars.com/api/?name=Jane+Doe&color=7F9CF5&background=EBF4FF');

    /** @var TextColumn $nameColumn */
    $nameColumn = $table->getColumn('name');
    $nameColumn->record($verifiedUser);

    expect($nameColumn)->toBeInstanceOf(TextColumn::class)
        ->and($nameColumn->getDescriptionBelow())->toBe('jane@example.com');

    /** @var TextColumn $rolesColumn */
    $rolesColumn = $table->getColumn('roles.name');
    $rolesColumn->record($verifiedUser);

    expect($rolesColumn)->toBeInstanceOf(TextColumn::class)
        ->and($rolesColumn->formatState('admin'))->toBe('Admin');

    /** @var IconColumn $verifiedColumn */
    $verifiedColumn = $table->getColumn('email_verified_at');
    $verifiedColumn->record($verifiedUser);

    expect($verifiedColumn)->toBeInstanceOf(IconColumn::class)
        ->and($verifiedColumn->getTooltip())->toBe('Verified on Jan 15, 2026');

    $verifiedColumn->record($unverifiedUser);

    expect($verifiedColumn->getTooltip())->toBe('Not verified');
});

it('applies verified, unverified, and recently joined table filters accurately', function (): void {
    $livewire = Mockery::mock(HasTable::class);
    $table = UserResource::table(Table::make($livewire));

    $verifiedRecent = User::factory()->create([
        'email_verified_at' => now()->subDay(),
        'created_at' => now()->subDays(5),
    ]);
    $unverifiedOld = User::factory()->unverified()->create([
        'created_at' => now()->subDays(45),
    ]);

    expect($table->getFilter('roles'))->toBeInstanceOf(SelectFilter::class);

    /** @var Filter $verifiedFilter */
    $verifiedFilter = $table->getFilter('verified');
    $verifiedIds = $verifiedFilter->apply(User::query(), ['isActive' => true])->pluck('id')->all();

    expect($verifiedIds)
        ->toContain($verifiedRecent->id)
        ->not->toContain($unverifiedOld->id);

    /** @var Filter $unverifiedFilter */
    $unverifiedFilter = $table->getFilter('unverified');
    $unverifiedIds = $unverifiedFilter->apply(User::query(), ['isActive' => true])->pluck('id')->all();

    expect($unverifiedIds)
        ->toContain($unverifiedOld->id)
        ->not->toContain($verifiedRecent->id);

    /** @var Filter $recentFilter */
    $recentFilter = $table->getFilter('created_at');
    $recentIds = $recentFilter->apply(User::query(), ['isActive' => true])->pluck('id')->all();

    expect($recentIds)
        ->toContain($verifiedRecent->id)
        ->not->toContain($unverifiedOld->id);
});
