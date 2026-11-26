<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\CreateNewUserWithTeams;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\Team;
use App\Models\User;
use Exception;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Features;
use Liberu\Foundation\Organizations\Models\Team as FoundationTeam;
use RuntimeException;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Tests\TestCase;
use Throwable;

class FortifyActionsChunk14CoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_new_user_logs_and_rethrows_validation_exception(): void
    {
        Log::spy();

        $action = new CreateNewUser();

        try {
            $action->create([
                'name' => '',
                'email' => 'not-an-email',
                'password' => 'short',
                'password_confirmation' => 'mismatch',
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
            $this->assertArrayHasKey('email', $e->errors());
            $this->assertArrayHasKey('password', $e->errors());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'User creation validation failed'
                && ! array_key_exists('password', $context['input']));
    }

    public function test_create_new_user_handles_query_exception_duplicate_entry(): void
    {
        Log::spy();

        $queryException = new QueryException(
            'sqlite',
            'insert into users (email) values (?)',
            ['dup@example.com'],
            new Exception('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', 23000),
        );

        $action = $this->createNewUserThrowing($queryException);

        try {
            $action->create($this->validRegistrationInput('dup@example.com'));
            $this->fail('Expected Exception was not thrown.');
        } catch (Exception $e) {
            $this->assertSame(
                'A user with this email already exists. Please use a different email address.',
                $e->getMessage(),
            );
            $this->assertSame(23000, $e->getCode());
            $this->assertSame($queryException, $e->getPrevious());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn (string $message): bool => $message === 'Database error during user creation');
    }

    public function test_create_new_user_handles_query_exception_access_denied(): void
    {
        Log::spy();

        $queryException = new QueryException(
            'sqlite',
            'select 1',
            [],
            new Exception('Access denied for user', 1045),
        );

        $action = $this->createNewUserThrowing($queryException);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database access denied. Please contact the administrator.');
        $this->expectExceptionCode(1045);

        $action->create($this->validRegistrationInput('denied@example.com'));
    }

    public function test_create_new_user_handles_query_exception_connection_refused(): void
    {
        Log::spy();

        $queryException = new QueryException(
            'sqlite',
            'select 1',
            [],
            new Exception('Connection refused', 2002),
        );

        $action = $this->createNewUserThrowing($queryException);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unable to connect to the database. Please try again later.');
        $this->expectExceptionCode(2002);

        $action->create($this->validRegistrationInput('conn@example.com'));
    }

    public function test_create_new_user_handles_query_exception_generic_code(): void
    {
        Log::spy();

        $queryException = new QueryException(
            'sqlite',
            'select 1',
            [],
            new Exception('Syntax error', 1064),
        );

        $action = $this->createNewUserThrowing($queryException);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('A database error occurred. Please try again later. Error code: 1064');
        $this->expectExceptionCode(1064);

        $action->create($this->validRegistrationInput('generic@example.com'));
    }

    public function test_create_new_user_handles_role_does_not_exist_exception(): void
    {
        Log::spy();

        $roleException = RoleDoesNotExist::named('invalid_role', 'web');
        $action = $this->createNewUserThrowing($roleException);

        try {
            $action->create([
                ...$this->validRegistrationInput('rolefail@example.com'),
                'role' => 'invalid_role',
            ]);
            $this->fail('Expected Exception was not thrown.');
        } catch (Exception $e) {
            $this->assertSame('Invalid role specified. Please choose a valid role.', $e->getMessage());
            $this->assertSame($roleException, $e->getPrevious());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Invalid role specified during user creation'
                && $context['role'] === 'invalid_role');
    }

    public function test_create_new_user_handles_unexpected_exception(): void
    {
        Log::spy();

        $unexpected = new RuntimeException('Unexpected failure', 500);
        $action = $this->createNewUserThrowing($unexpected);

        try {
            $action->create($this->validRegistrationInput('unexpected@example.com'));
            $this->fail('Expected Exception was not thrown.');
        } catch (Exception $e) {
            $this->assertSame('An unexpected error occurred. Please try again later.', $e->getMessage());
            $this->assertSame(500, $e->getCode());
            $this->assertSame($unexpected, $e->getPrevious());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Unexpected error during user creation'
                && $context['exception_class'] === RuntimeException::class);
    }

    public function test_create_new_user_with_teams_creates_user_and_assigns_teams(): void
    {
        $owner = User::factory()->create();
        $defaultTeam = Team::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Default Org',
            'personal_team' => false,
            'is_default_for_registration' => true,
        ]);

        $action = new CreateNewUserWithTeams();
        $user = $action->create($this->validRegistrationInput('teamuser@example.com'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'teamuser@example.com',
        ]);
        $this->assertTrue(Hash::check('New-Passw0rd!23', $user->password));
        $this->assertTrue($user->fresh()->belongsToTeam($defaultTeam));
        $this->assertCount(1, $user->ownedTeams);
        $this->assertTrue($user->ownedTeams->first()->personal_team);
        $this->assertSame("Test's Team", $user->ownedTeams->first()->name);
    }

    public function test_create_new_user_with_teams_enforces_terms_when_feature_enabled(): void
    {
        $originalFeatures = config('jetstream.features', []);
        config()->set('jetstream.features', [...$originalFeatures, Features::termsAndPrivacyPolicy()]);

        try {
            $action = new CreateNewUserWithTeams();

            try {
                $action->create($this->validRegistrationInput('noterms@example.com'));
                $this->fail('Expected ValidationException when terms are not accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('terms', $e->errors());
            }

            $user = $action->create([
                ...$this->validRegistrationInput('withterms@example.com'),
                'terms' => 'on',
            ]);

            $this->assertSame('withterms@example.com', $user->email);
        } finally {
            config()->set('jetstream.features', $originalFeatures);
        }
    }

    public function test_reset_user_password_logs_and_rethrows_validation_exception(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $action = new ResetUserPassword();

        try {
            $action->reset($user, [
                'password' => 'short',
                'password_confirmation' => 'different',
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Password reset validation failed'
                && $context['user_id'] === $user->id);
    }

    public function test_reset_user_password_logs_and_wraps_unexpected_exception(): void
    {
        Log::spy();

        $baseUser = User::factory()->create();
        $failingUser = $this->makeSaveFailingUser($baseUser);

        $action = new ResetUserPassword();

        try {
            $action->reset($failingUser, [
                'password' => 'New-Passw0rd!23',
                'password_confirmation' => 'New-Passw0rd!23',
            ]);
            $this->fail('Expected Exception was not thrown.');
        } catch (Exception $e) {
            $this->assertSame('Failed to reset password. Please try again later.', $e->getMessage());
            $this->assertSame(500, $e->getCode());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Password reset failed'
                && $context['user_id'] === $baseUser->id);
    }

    public function test_update_user_password_logs_and_wraps_unexpected_exception(): void
    {
        Log::spy();

        $baseUser = User::factory()->create();
        $this->actingAs($baseUser, 'web');

        $failingUser = $this->makeSaveFailingUser($baseUser);

        $action = new UpdateUserPassword();

        try {
            $action->update($failingUser, [
                'current_password' => 'password',
                'password' => 'New-Passw0rd!23',
                'password_confirmation' => 'New-Passw0rd!23',
            ]);
            $this->fail('Expected Exception was not thrown.');
        } catch (Exception $e) {
            $this->assertSame('Failed to update password. Please try again later.', $e->getMessage());
            $this->assertSame(500, $e->getCode());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Password update failed'
                && $context['user_id'] === $baseUser->id);
    }

    public function test_update_user_profile_information_resets_verification_for_must_verify_email_user(): void
    {
        $baseUser = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'original@example.com',
            'email_verified_at' => now(),
        ]);

        $verifiableUser = new class() extends User implements MustVerifyEmail
        {
            protected $table = 'users';

            public bool $verificationNotificationSent = false;

            public function getMorphClass(): string
            {
                return User::class;
            }

            public function sendEmailVerificationNotification(): void
            {
                $this->verificationNotificationSent = true;
            }
        };
        $verifiableUser->setRawAttributes($baseUser->getAttributes(), true);
        $verifiableUser->exists = true;

        $action = new UpdateUserProfileInformation();
        $action->update($verifiableUser, [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

        $this->assertTrue($verifiableUser->verificationNotificationSent);
        $this->assertSame('Updated Name', $baseUser->fresh()->name);
        $this->assertSame('updated@example.com', $baseUser->fresh()->email);
        $this->assertNull($baseUser->fresh()->email_verified_at);
    }

    /**
     * @return array<string, string>
     */
    private function validRegistrationInput(string $email): array
    {
        return [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'New-Passw0rd!23',
            'password_confirmation' => 'New-Passw0rd!23',
        ];
    }

    private function createNewUserThrowing(Throwable $exception): CreateNewUser
    {
        return new class($exception) extends CreateNewUser
        {
            public function __construct(private readonly Throwable $exception) {}

            protected function configureDefaultTeamContext(): FoundationTeam
            {
                throw $this->exception;
            }
        };
    }

    private function makeSaveFailingUser(User $baseUser): User
    {
        $failingUser = new class() extends User
        {
            protected $table = 'users';

            public function save(array $options = []): bool
            {
                throw new RuntimeException('Simulated database write failure', 500);
            }
        };
        $failingUser->setRawAttributes($baseUser->getAttributes(), true);
        $failingUser->exists = true;

        return $failingUser;
    }
}
