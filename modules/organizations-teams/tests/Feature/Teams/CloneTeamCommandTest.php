<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamCloneService;
use Tests\TestCase;

class CloneTeamCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_clones_config_into_a_new_team(): void
    {
        $source = Team::factory()->create();
        Webhook::create([
            'team_id' => $source->id,
            'name' => 'Billing Hook',
            'url' => 'https://hooks.example.com/billing',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);
        $owner = User::factory()->create();

        $this->app->instance(TeamCloneService::class, new TeamCloneService([Webhook::class], []));

        $this->artisan('team:clone', [
            'source' => $source->id,
            '--name' => 'Fresh Team',
            '--owner' => $owner->id,
        ])->assertSuccessful();

        $new = Team::where('name', 'Fresh Team')->first();
        $this->assertNotNull($new);
        $this->assertSame($owner->id, $new->user_id);
        $this->assertSame(1, Webhook::withoutGlobalScope('tenant')->where('team_id', $new->id)->count());
    }

    public function test_command_fails_for_unknown_source(): void
    {
        $this->artisan('team:clone', ['source' => 999999])->assertFailed();
    }

    public function test_command_fails_for_unknown_owner(): void
    {
        $source = Team::factory()->create();

        $this->artisan('team:clone', [
            'source' => $source->id,
            '--owner' => 999999,
        ])->assertFailed();
    }
}
