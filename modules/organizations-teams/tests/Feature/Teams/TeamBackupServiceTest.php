<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Integrations\Models\WebhookDelivery;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Tests\TestCase;
use ZipArchive;

class TeamBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private function readEntry(string $path, string $entry): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($path)) === true);
        $contents = $zip->getFromName($entry);
        $zip->close();
        $this->assertNotFalse($contents, "zip entry `{$entry}` is missing");

        return $contents;
    }

    public function test_backup_produces_a_zip_with_model_json_and_manifest(): void
    {
        Storage::fake('local');
        $team = Team::factory()->create();
        Webhook::create([
            'team_id' => $team->id,
            'name' => 'Acme Hook',
            'url' => 'https://hooks.example.com/acme',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);

        $result = (new TeamBackupService())->backup($team, 'local');

        Storage::disk('local')->assertExists($result['path']);
        $this->assertGreaterThan(0, $result['size']);

        $manifest = json_decode($this->readEntry($result['path'], 'manifest.json'), true);
        $this->assertSame($team->id, $manifest['team']['id']);
        $this->assertSame(1, $manifest['models']['Webhook']);

        $this->assertStringContainsString('Acme Hook', $this->readEntry($result['path'], 'models/Webhook.json'));
    }

    public function test_backup_contains_only_the_target_teams_rows(): void
    {
        Storage::fake('local');
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        Webhook::create([
            'team_id' => $teamA->id,
            'name' => 'Mine Hook',
            'url' => 'https://hooks.example.com/mine',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);
        $otherHook = Webhook::create([
            'team_id' => $teamB->id,
            'name' => 'Other Hook',
            'url' => 'https://hooks.example.com/other',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);
        WebhookDelivery::create([
            'team_id' => $teamB->id,
            'webhook_id' => $otherHook->id,
            'event' => 'contact.created',
            'success' => true,
            'status_code' => 200,
        ]);

        $result = (new TeamBackupService())->backup($teamA, 'local');

        $webhooks = $this->readEntry($result['path'], 'models/Webhook.json');
        $this->assertStringContainsString('Mine Hook', $webhooks);
        $this->assertStringNotContainsString('Other Hook', $webhooks);

        $manifest = json_decode($this->readEntry($result['path'], 'manifest.json'), true);
        $this->assertSame(1, $manifest['models']['Webhook']);
        $this->assertSame(0, $manifest['models']['WebhookDelivery']);
    }

    public function test_backup_includes_team_membership_extras(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'sales_rep']);

        $result = (new TeamBackupService())->backup($team, 'local');

        $manifest = json_decode($this->readEntry($result['path'], 'manifest.json'), true);
        $this->assertSame(1, $manifest['extras']['team_user']);
        $this->assertStringContainsString((string) $member->id, $this->readEntry($result['path'], 'extras/team_user.json'));
    }
}
