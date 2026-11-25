<?php

declare(strict_types=1);

use App\Contracts\GitPlatformClient;
use App\Enums\GitProvider;
use App\Jobs\SyncGitConnection;
use App\Models\GitConnection;
use App\Models\GitRelease;
use App\Models\GitRepository;
use App\Models\GitSyncRecord;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitPlatformClientFactory;
use App\Services\Git\HttpGitPlatformClient;
use App\Services\GitSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

function gitOperator(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->update(['current_team_id' => $team->id]);
    Sanctum::actingAs($user, ['git:read', 'git:write']);

    return [$user, $team];
}

it('covers all GitIntegrationController endpoints', function (): void {
    Queue::fake();
    [, $team] = gitOperator();

    $connection = GitConnection::factory()->create([
        'team_id' => $team->id,
        'provider' => GitProvider::GitHub,
        'name' => 'Main GitHub',
        'base_url' => 'https://github.example.test/api',
        'access_token' => 'valid-access-token',
    ]);

    // List connections
    $this->getJson('/api/git/connections')
        ->assertOk()
        ->assertJsonPath('data.0.id', $connection->id);

    // Update connection
    $this->patchJson("/api/git/connections/{$connection->id}", [
        'name' => 'Renamed GitHub',
        'is_active' => true,
    ])->assertOk()
        ->assertJsonPath('name', 'Renamed GitHub');

    // Queue sync
    $this->postJson("/api/git/connections/{$connection->id}/sync")
        ->assertAccepted()
        ->assertJsonPath('queued', true);

    Queue::assertPushed(SyncGitConnection::class, fn ($job): bool => $job->connectionId === $connection->id);

    // Create repository and sync record
    $repository = GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'external_id' => 'repo-101',
        'full_name' => 'acme/billing',
        'default_branch' => 'main',
        'web_url' => 'https://github.example.test/acme/billing',
        'is_private' => true,
    ]);

    $record = $repository->syncRecords()->create([
        'record_type' => 'commit',
        'external_id' => 'sha-1',
        'title' => 'Initial commit',
        'author' => 'Ada',
    ]);

    // List repositories & show single repository
    $this->getJson('/api/git/repositories')
        ->assertOk()
        ->assertJsonPath('data.0.id', $repository->id);

    $this->getJson("/api/git/repositories/{$repository->id}")
        ->assertOk()
        ->assertJsonPath('id', $repository->id)
        ->assertJsonPath('sync_records.0.id', $record->id);

    // Create release via GitHub HTTP client
    Http::fake([
        '*' => Http::response([
            'id' => 777,
            'tag_name' => 'v2.0.0',
            'name' => 'Release 2.0',
            'html_url' => 'https://github.example.test/acme/billing/releases/v2.0.0',
        ], 201),
    ]);

    $releaseResponse = $this->postJson("/api/git/repositories/{$repository->id}/releases", [
        'version' => 'v2.0.0',
        'name' => 'Release 2.0',
        'changelog' => '- Added billing features',
    ])->assertCreated()
        ->assertJsonPath('version', 'v2.0.0');

    $releaseId = $releaseResponse->json('id');

    // Track deployment
    $this->postJson("/api/git/releases/{$releaseId}/deployment", [
        'environment' => 'production',
        'status' => 'succeeded',
        'completed' => true,
    ])->assertOk()
        ->assertJsonPath('deployment_environment', 'production')
        ->assertJsonPath('deployment_status', 'succeeded');

    // Destroy connection
    $this->deleteJson("/api/git/connections/{$connection->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('git_connections', ['id' => $connection->id]);
});

it('rejects git webhooks without a secret and accepts valid bitbucket signatures', function (): void {
    Queue::fake();

    $noSecret = GitConnection::factory()->create([
        'provider' => GitProvider::GitHub,
        'webhook_secret' => null,
        'is_active' => true,
    ]);

    $this->postJson("/api/git/webhooks/{$noSecret->id}", ['event' => 'push'])
        ->assertUnauthorized();

    $bitbucket = GitConnection::factory()->create([
        'provider' => GitProvider::Bitbucket,
        'webhook_secret' => 'bitbucket-webhook-secret',
        'is_active' => true,
    ]);

    $payload = json_encode(['push' => ['changes' => []]], JSON_THROW_ON_ERROR);

    $this->call('POST', "/api/git/webhooks/{$bitbucket->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $payload, 'bitbucket-webhook-secret'),
    ], $payload)->assertAccepted();

    Queue::assertPushed(SyncGitConnection::class, fn ($job): bool => $job->connectionId === $bitbucket->id);
});

it('executes SyncGitConnection job and skips pull requests during issue sync', function (): void {
    $connection = GitConnection::factory()->create([
        'provider' => GitProvider::GitHub,
        'base_url' => 'https://github.example.test/api',
        'is_active' => true,
    ]);

    Http::fake(['*' => Http::response([])]);

    (new SyncGitConnection($connection->id))->handle(
        app(GitSyncService::class),
        app(GitPlatformClientFactory::class)
    );

    expect($connection->refresh()->last_synced_at)->not->toBeNull();

    // GitSyncService skips issue records that carry a pull_request payload
    $repository = GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'external_id' => 'repo-pr',
        'full_name' => 'acme/pr-test',
        'default_branch' => 'main',
        'web_url' => 'https://github.example.test/acme/pr-test',
        'is_private' => false,
    ]);

    $prOnlyClient = new class() implements GitPlatformClient
    {
        public function repositories(): array
        {
            return [];
        }

        public function records(GitRepository $repository, string $type): array
        {
            if ($type !== 'issue') {
                return [];
            }

            return [[
                'external_id' => 'pr-42',
                'title' => 'Pull Request disguised as issue',
                'state' => 'open',
                'web_url' => 'https://github.example.test/acme/pr-test/pull/42',
                'payload' => ['pull_request' => ['url' => 'https://github.example.test/pulls/42']],
            ]];
        }

        public function createRelease(GitRepository $repository, string $version, string $name, string $changelog): array
        {
            return [];
        }
    };

    expect(app(GitSyncService::class)->syncRepository($repository, $prOnlyClient))->toBe(0);
    $this->assertDatabaseCount('git_sync_records', 0);
});

it('covers HttpGitPlatformClient record types, release creation, pagination, and error handling', function (): void {
    $providers = [
        GitProvider::GitHub,
        GitProvider::GitLab,
        GitProvider::Bitbucket,
    ];

    foreach ($providers as $provider) {
        $connection = GitConnection::factory()->create([
            'provider' => $provider,
            'base_url' => "https://{$provider->value}.example.test/api",
            'access_token' => 'provider-token-123',
        ]);

        $repository = GitRepository::query()->create([
            'git_connection_id' => $connection->id,
            'external_id' => '1234',
            'full_name' => 'acme/billing',
            'default_branch' => 'main',
            'web_url' => "https://{$provider->value}.example.test/acme/billing",
            'is_private' => true,
        ]);

        Http::fake(function ($request) use ($provider) {
            if ($request->method() === 'POST') {
                return Http::response([
                    'id' => 'rel-1',
                    'tag_name' => 'v1.0.0',
                    'name' => 'v1.0.0',
                    'html_url' => 'https://example.test/releases/v1.0.0',
                ], 201);
            }

            $item = [
                'id' => 'item-1',
                'title' => 'Sample record',
                'state' => 'open',
                'html_url' => 'https://example.test/item/1',
            ];

            return Http::response(
                $provider === GitProvider::Bitbucket ? ['values' => [$item]] : [$item]
            );
        });

        $client = new HttpGitPlatformClient($connection);

        foreach (['issue', 'milestone', 'change_request', 'release'] as $type) {
            $records = $client->records($repository, $type);
            if ($provider === GitProvider::Bitbucket && $type === 'milestone') {
                expect($records)->toBe([]);
            } else {
                expect($records)->toHaveCount(1);
            }
        }

        $release = $client->createRelease($repository, 'v1.0.0', 'First release', 'Notes');
        expect($release['external_id'])->toBe('rel-1');
    }

    // GitHub Link header pagination
    $githubConn = GitConnection::factory()->create([
        'provider' => GitProvider::GitHub,
        'base_url' => 'https://github.example.test/api',
    ]);
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'page=2')) {
            return Http::response([[
                'id' => 2,
                'full_name' => 'acme/second',
                'html_url' => 'https://github.example.test/acme/second',
            ]]);
        }

        return Http::response([[
            'id' => 1,
            'full_name' => 'acme/first',
            'html_url' => 'https://github.example.test/acme/first',
        ]], 200, [
            'Link' => '<https://github.example.test/api/user/repos?per_page=100&page=2>; rel="next"',
        ]);
    });

    $ghRepos = (new HttpGitPlatformClient($githubConn))->repositories();
    expect($ghRepos)->toHaveCount(2);

    // GitLab X-Next-Page pagination (covers withQueryParameter)
    $gitlabConn = GitConnection::factory()->create([
        'provider' => GitProvider::GitLab,
        'base_url' => 'https://gitlab.example.test/api',
    ]);
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'page=2')) {
            return Http::response([[
                'id' => 22,
                'path_with_namespace' => 'acme/gl-second',
                'web_url' => 'https://gitlab.example.test/acme/gl-second',
            ]]);
        }

        return Http::response([[
            'id' => 11,
            'path_with_namespace' => 'acme/gl-first',
            'web_url' => 'https://gitlab.example.test/acme/gl-first',
        ]], 200, [
            'X-Next-Page' => '2',
        ]);
    });

    $glRepos = (new HttpGitPlatformClient($gitlabConn))->repositories();
    expect($glRepos)->toHaveCount(2);

    // Non-array collection throws RuntimeException
    Http::fake([
        '*' => Http::response('"scalar-not-array"', 200, ['Content-Type' => 'application/json']),
    ]);
    expect(fn () => (new HttpGitPlatformClient($githubConn))->repositories())
        ->toThrow(RuntimeException::class, 'Git provider returned an invalid collection.');

    // HTTP failure throws provider operation failed RuntimeException
    Http::fake(['*' => Http::response('Server error', 500)]);
    expect(fn () => (new HttpGitPlatformClient($githubConn))->repositories())
        ->toThrow(RuntimeException::class, 'github operation failed.');
});

it('covers git model relationships', function (): void {
    $connection = GitConnection::factory()->create();
    $repository = GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'external_id' => 'repo-rel',
        'full_name' => 'acme/relations',
        'default_branch' => 'main',
        'web_url' => 'https://github.example.test/acme/relations',
        'is_private' => true,
    ]);
    $syncRecord = GitSyncRecord::query()->create([
        'git_repository_id' => $repository->id,
        'record_type' => 'issue',
        'external_id' => 'issue-1',
        'title' => 'Bug',
    ]);
    $release = GitRelease::query()->create([
        'git_repository_id' => $repository->id,
        'version' => 'v0.1.0',
        'name' => 'Alpha',
    ]);

    expect($connection->repositories()->count())->toBe(1)
        ->and($repository->connection->id)->toBe($connection->id)
        ->and($syncRecord->repository->id)->toBe($repository->id)
        ->and($release->repository->id)->toBe($repository->id);
});
