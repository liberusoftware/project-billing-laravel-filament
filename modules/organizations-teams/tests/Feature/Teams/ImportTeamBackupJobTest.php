<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Organizations\Jobs\ImportTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\ImportTeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Tests\TestCase;
use Throwable;

class ImportTeamBackupJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_job_imports_a_stored_zip_and_notifies(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        $source = Team::factory()->create();
        Webhook::create([
            'team_id' => $source->id,
            'name' => 'Import Hook',
            'url' => 'https://hooks.example.com/import',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);

        $result = (new TeamBackupService())->backup($source, 'local');
        Webhook::withoutGlobalScope('tenant')->where('team_id', $source->id)->delete();

        (new ImportTeamBackup('local', $result['path'], 'Imported', $importer->id))
            ->handle(app(ImportTeamBackupService::class));

        $new = Team::where('name', 'Imported')->first();
        $this->assertNotNull($new);
        $this->assertSame(1, Webhook::withoutGlobalScope('tenant')->where('team_id', $new->id)->count());
        $this->assertSame(1, $importer->fresh()->notifications()->count());
    }

    public function test_job_rethrows_on_failure(): void
    {
        Storage::fake('local');
        $importer = User::factory()->create();
        Storage::disk('local')->put('imports/bad.zip', 'not a zip');

        $this->expectException(Throwable::class);
        (new ImportTeamBackup('local', 'imports/bad.zip', 'X', $importer->id))
            ->handle(app(ImportTeamBackupService::class));
    }
}
