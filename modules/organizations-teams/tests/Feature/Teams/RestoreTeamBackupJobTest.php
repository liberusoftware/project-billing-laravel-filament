<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamRestoreService;
use Tests\TestCase;
use Throwable;

class RestoreTeamBackupJobTest extends TestCase
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

    public function test_job_restores_and_notifies_the_initiator(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $team = Team::factory()->create();
        $webhook = Webhook::create([
            'team_id' => $team->id,
            'name' => 'Restore Hook',
            'url' => 'https://hooks.example.com/restore',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);

        $result = (new TeamBackupService())->backup($team, 'local');
        $backup = TeamBackup::factory()->create([
            'team_id' => $team->id, 'disk' => 'local', 'path' => $result['path'], 'status' => 'completed',
        ]);
        Webhook::withoutGlobalScope('tenant')->where('team_id', $team->id)->delete();

        (new RestoreTeamBackup($backup->id, $admin->id))->handle(app(TeamRestoreService::class));

        $this->assertDatabaseHas('webhooks', ['id' => $webhook->id]);
        $this->assertSame(1, $admin->fresh()->notifications()->count());
    }

    public function test_job_rethrows_on_failure(): void
    {
        $team = Team::factory()->create();
        $backup = TeamBackup::factory()->create(['team_id' => $team->id, 'status' => 'pending']);

        $this->expectException(Throwable::class);
        (new RestoreTeamBackup($backup->id))->handle(app(TeamRestoreService::class));
    }
}
