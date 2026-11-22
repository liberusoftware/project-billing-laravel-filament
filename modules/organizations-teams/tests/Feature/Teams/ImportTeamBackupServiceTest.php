<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Integrations\Models\WebhookDelivery;
use Liberu\Foundation\Organizations\Exceptions\TeamImportException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\ImportTeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Tests\TestCase;
use ZipArchive;

class ImportTeamBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private function backupOf(Team $source): string
    {
        $result = (new TeamBackupService())->backup($source, 'local');

        return Storage::disk('local')->path($result['path']);
    }

    public function test_imports_a_backup_into_a_new_team_remapping_fks_and_users(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        $assignee = User::factory()->create();

        $source = Team::factory()->create();
        $webhook = Webhook::create([
            'team_id' => $source->id,
            'user_id' => $assignee->id,
            'name' => 'CrossEnv Hook',
            'url' => 'https://hooks.example.com/cross-env',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);
        WebhookDelivery::create([
            'team_id' => $source->id,
            'webhook_id' => $webhook->id,
            'event' => 'contact.created',
            'success' => true,
            'status_code' => 200,
        ]);

        $localZip = $this->backupOf($source);

        $new = (new ImportTeamBackupService())->import($localZip, 'Imported Team', $importer);

        $this->assertNotSame($source->id, $new->id);
        $this->assertSame($importer->id, $new->user_id);

        $newWebhook = DB::table('webhooks')->where('team_id', $new->id)->first();
        $newDelivery = DB::table('crm_webhook_deliveries')->where('team_id', $new->id)->first();
        $this->assertNotNull($newWebhook);
        $this->assertNotNull($newDelivery);
        $this->assertSame('CrossEnv Hook', $newWebhook->name);

        // New PK, cross-model FK rewired to the imported webhook.
        $this->assertNotSame($webhook->id, (int) $newWebhook->id);
        $this->assertSame((int) $newWebhook->id, (int) $newDelivery->webhook_id);

        // User reference resolves to the importer, not the source-env user.
        $this->assertSame($importer->id, (int) $newWebhook->user_id);
    }

    public function test_import_is_atomic_and_leaves_no_orphan_team_on_failure(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        $source = Team::factory()->create();
        Webhook::create([
            'team_id' => $source->id,
            'name' => 'Rollback Hook',
            'url' => 'https://hooks.example.com/rollback',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);

        // Corrupt one row so the insert fails after the team transaction starts.
        $result = (new TeamBackupService())->backup($source, 'local');
        $zip = new ZipArchive();
        $zip->open(Storage::disk('local')->path($result['path']));
        $webhooks = json_decode((string) $zip->getFromName('models/Webhook.json'), true, flags: JSON_THROW_ON_ERROR);
        $webhooks[0]['invalid_column'] = 'forces rollback';
        $zip->deleteName('models/Webhook.json');
        $zip->addFromString('models/Webhook.json', json_encode($webhooks, JSON_THROW_ON_ERROR));
        $zip->close();
        $teamsBefore = DB::table('teams')->count();

        try {
            (new ImportTeamBackupService())->import(Storage::disk('local')->path($result['path']), 'X', $importer);
            $this->fail('expected a failure');
        } catch (\Throwable) {
            // expected
        }

        // Team was created inside the transaction -> rolled back, no orphan.
        $this->assertSame($teamsBefore, DB::table('teams')->count());
    }

    public function test_rejects_a_bad_archive(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('bad.zip', 'not a zip');
        $importer = User::factory()->create();

        $this->expectException(TeamImportException::class);
        (new ImportTeamBackupService())->import(Storage::disk('local')->path('bad.zip'), 'X', $importer);
    }

    public function test_rejects_wrong_format_version(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        $path = Storage::disk('local')->path('v2.zip');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode(['format_version' => 99, 'team' => ['id' => 1, 'name' => 'x']]));
        $zip->close();

        $this->expectException(TeamImportException::class);
        (new ImportTeamBackupService())->import($path, 'X', $importer);
    }
}
