<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Integrations\Models\WebhookDelivery;
use Liberu\Foundation\Organizations\Exceptions\TeamNotEmptyException;
use Liberu\Foundation\Organizations\Exceptions\TeamRestoreException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamRestoreService;
use Tests\TestCase;
use Throwable;
use ZipArchive;

class TeamRestoreServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Back up a team and return its completed TeamBackup row. */
    private function backupOf(Team $team): TeamBackup
    {
        $result = (new TeamBackupService())->backup($team, 'local');

        return TeamBackup::factory()->create([
            'team_id' => $team->id,
            'disk' => 'local',
            'path' => $result['path'],
            'size_bytes' => $result['size'],
            'status' => 'completed',
        ]);
    }

    public function test_round_trips_rows_with_original_ids_and_fks(): void
    {
        Storage::fake('local');
        $team = Team::factory()->create();
        $webhook = Webhook::create([
            'team_id' => $team->id,
            'name' => 'RoundTrip Hook',
            'url' => 'https://hooks.example.com/roundtrip',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'team_id' => $team->id,
            'webhook_id' => $webhook->id,
            'event' => 'contact.created',
            'success' => true,
            'status_code' => 200,
        ]);

        $backup = $this->backupOf($team);

        WebhookDelivery::withoutGlobalScope('tenant')->where('team_id', $team->id)->delete();
        Webhook::withoutGlobalScope('tenant')->where('team_id', $team->id)->delete();
        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);

        $restored = (new TeamRestoreService())->restore($backup);

        $this->assertDatabaseHas('webhooks', ['id' => $webhook->id, 'name' => 'RoundTrip Hook', 'team_id' => $team->id]);
        $this->assertDatabaseHas('crm_webhook_deliveries', ['id' => $delivery->id, 'webhook_id' => $webhook->id]);
        $this->assertSame(1, $restored['Webhook']);
        $this->assertSame(1, $restored['WebhookDelivery']);
    }

    public function test_refuses_to_restore_over_a_non_empty_team(): void
    {
        Storage::fake('local');
        $team = Team::factory()->create();
        Webhook::create([
            'team_id' => $team->id,
            'name' => 'Existing Hook',
            'url' => 'https://hooks.example.com/existing',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);
        $backup = $this->backupOf($team);

        // team still populated -> must refuse
        $this->expectException(TeamNotEmptyException::class);
        (new TeamRestoreService())->restore($backup);
    }

    public function test_rejects_a_non_completed_backup(): void
    {
        $team = Team::factory()->create();
        $backup = TeamBackup::factory()->create(['team_id' => $team->id, 'status' => 'pending']);

        $this->expectException(TeamRestoreException::class);
        (new TeamRestoreService())->restore($backup);
    }

    public function test_rejects_when_the_backup_file_is_missing(): void
    {
        Storage::fake('local');
        $team = Team::factory()->create();
        $backup = TeamBackup::factory()->create([
            'team_id' => $team->id,
            'disk' => 'local',
            'path' => 'backups/gone.zip',
            'status' => 'completed',
        ]);

        $this->expectException(TeamRestoreException::class);
        (new TeamRestoreService())->restore($backup);
    }

    public function test_restore_is_atomic_on_failure(): void
    {
        Storage::fake('local');
        $team = Team::factory()->create();
        $webhook = Webhook::create([
            'team_id' => $team->id,
            'name' => 'Atomic Hook',
            'url' => 'https://hooks.example.com/atomic',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);
        $backup = $this->backupOf($team);

        // Corrupt a later model's entry (WebhookDelivery sorts after Webhook) with an
        // unknown column so its insert throws mid-restore.
        $full = Storage::disk('local')->path((string) $backup->path);
        $zip = new ZipArchive();
        $zip->open($full);
        $zip->addFromString('models/WebhookDelivery.json', (string) json_encode([['team_id' => $team->id, 'bogus_col' => 1]]));
        $zip->close();

        Webhook::withoutGlobalScope('tenant')->where('team_id', $team->id)->delete();

        try {
            (new TeamRestoreService())->restore($backup);
            $this->fail('expected the restore to throw');
        } catch (Throwable) {
            // expected
        }

        // Webhook inserted before WebhookDelivery failed -> transaction rolled back -> gone.
        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
        $this->assertSame(0, WebhookDelivery::withoutGlobalScope('tenant')->where('team_id', $team->id)->count());
    }
}
