<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Tests\TestCase;

class ImportTeamCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_imports_a_zip_into_a_new_team(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $source = Team::factory()->create();
        Webhook::create([
            'team_id' => $source->id,
            'name' => 'CLI Import Hook',
            'url' => 'https://hooks.example.com/cli-import',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);

        $result = (new TeamBackupService())->backup($source, 'local');
        Webhook::withoutGlobalScope('tenant')->where('team_id', $source->id)->delete();
        $path = Storage::disk('local')->path($result['path']);

        $this->artisan('team:import', ['path' => $path, '--name' => 'Imported', '--owner' => $owner->id])
            ->assertSuccessful();

        $new = Team::where('name', 'Imported')->first();
        $this->assertNotNull($new);
        $this->assertSame($owner->id, $new->user_id);
        $this->assertSame(1, Webhook::withoutGlobalScope('tenant')->where('team_id', $new->id)->count());
    }

    public function test_command_fails_for_a_missing_file(): void
    {
        $this->artisan('team:import', ['path' => '/no/such/file.zip'])->assertFailed();
    }

    public function test_command_fails_when_owner_cannot_be_resolved(): void
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('dummy.zip');
        file_put_contents($path, 'placeholder');

        $this->artisan('team:import', ['path' => $path, '--owner' => 999999])->assertFailed();
    }
}
