<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Teams\TeamResource;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Filament\Admin\Resources\Users\Schemas\UserForm;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

it('evaluates all ViewUser infolist closures including default image url and two-factor formatting branches', function (): void {
    $user = User::factory()->create([
        'name' => 'Jane Example',
        'email' => 'jane-infolist@example.com',
        'two_factor_confirmed_at' => null,
    ]);

    $page = new ViewUser();
    $page->record = $user;

    $schema = $page->infolist(Schema::make($page)->record($user));
    $components = collect($schema->getFlatComponents(withHidden: true));

    /** @var ImageEntry $photoEntry */
    $photoEntry = $components->first(
        static fn (mixed $component): bool => $component instanceof ImageEntry && $component->getName() === 'profile_photo_url',
    );
    /** @var TextEntry $rolesEntry */
    $rolesEntry = $components->first(
        static fn (mixed $component): bool => $component instanceof TextEntry && $component->getName() === 'roles.name',
    );
    /** @var TextEntry $twoFactorEntry */
    $twoFactorEntry = $components->first(
        static fn (mixed $component): bool => $component instanceof TextEntry && $component->getName() === 'two_factor_confirmed_at',
    );

    expect($photoEntry)->toBeInstanceOf(ImageEntry::class)
        ->and($photoEntry->getDefaultImageUrl())
        ->toBe('https://ui-avatars.com/api/?name=Jane+Example&color=7F9CF5&background=EBF4FF')
        ->and($rolesEntry)->toBeInstanceOf(TextEntry::class)
        ->and($rolesEntry->formatState('manager'))->toBe('Manager')
        ->and($twoFactorEntry)->toBeInstanceOf(TextEntry::class)
        ->and($twoFactorEntry->formatState(now()))->toBe('Yes')
        ->and($twoFactorEntry->formatState(null))->toBe('No')
        ->and($twoFactorEntry->getColor(now()))->toBe('success')
        ->and($twoFactorEntry->getColor(null))->toBe('gray');
});

it('evaluates all UserForm password field closures for filled, blank, create, and edit contexts', function (): void {
    $livewire = new EditUser();

    $createSchema = UserForm::configure(Schema::make($livewire)->operation('create'));
    /** @var TextInput $createPassword */
    $createPassword = collect($createSchema->getFlatComponents(withHidden: true))->first(
        static fn (mixed $component): bool => $component instanceof TextInput && $component->getName() === 'password',
    );

    $editSchema = UserForm::configure(Schema::make($livewire)->operation('edit'));
    /** @var TextInput $editPassword */
    $editPassword = collect($editSchema->getFlatComponents(withHidden: true))->first(
        static fn (mixed $component): bool => $component instanceof TextInput && $component->getName() === 'password',
    );

    $hashedDehydrated = $createPassword->getStateToDehydrate('PlainSecret!123');
    $blankDehydrated = $createPassword->getStateToDehydrate('');

    expect($createPassword->isRequired())->toBeTrue()
        ->and($editPassword->isRequired())->toBeFalse()
        ->and($hashedDehydrated)->toHaveKey('password')
        ->and(Hash::check('PlainSecret!123', (string) $hashedDehydrated['password']))->toBeTrue()
        ->and($blankDehydrated)->toBe(['password' => null]);
});

it('configures the admin TeamResource table columns and actions', function (): void {
    $table = TeamResource::table(Table::make(Mockery::mock(HasTable::class)));

    expect($table->getColumns())->toHaveCount(5)
        ->and($table->getRecordActions())->toHaveCount(2)
        ->and($table->getToolbarActions())->toHaveCount(1);
});
