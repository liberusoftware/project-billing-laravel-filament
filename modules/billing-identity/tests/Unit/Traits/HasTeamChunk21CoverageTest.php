<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HasTeamChunk21CoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_team_trait_defines_belongs_to_team_relationship(): void
    {
        $team = Team::factory()->create();
        $subscription = Subscription::factory()->create([
            'team_id' => $team->id,
        ]);

        $relation = $subscription->team();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertNotNull($subscription->team);
        $this->assertTrue($subscription->team->is($team));
    }
}
