<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Integrations\Models\WebhookDelivery;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamCloneService;
use Tests\TestCase;

class TeamCloneServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Team, int, int}
     */
    private function seedSource(): array
    {
        $source = Team::factory()->create();

        $webhook = Webhook::create([
            'team_id' => $source->id,
            'name' => 'Template Hook',
            'url' => 'https://hooks.example.com/template',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);

        $delivery = WebhookDelivery::create([
            'team_id' => $source->id,
            'webhook_id' => $webhook->id,
            'event' => 'contact.created',
            'success' => true,
            'status_code' => 200,
        ]);

        return [$source, $webhook->id, $delivery->id];
    }

    public function test_clones_config_and_remaps_foreign_keys(): void
    {
        [$source, $sourceWebhookId] = $this->seedSource();
        $owner = User::factory()->create();

        $service = new TeamCloneService(
            [Webhook::class, WebhookDelivery::class],
            [WebhookDelivery::class => ['webhook_id' => Webhook::class]],
        );

        $new = $service->clone($source, 'Cloned Team', $owner);

        $this->assertSame('Cloned Team', $new->name);
        $this->assertSame($owner->id, $new->user_id);
        $this->assertFalse((bool) $new->personal_team);
        $this->assertNotSame($source->id, $new->id);

        $newWebhook = DB::table('webhooks')->where('team_id', $new->id)->first();
        $newDelivery = DB::table('crm_webhook_deliveries')->where('team_id', $new->id)->first();

        $this->assertNotNull($newWebhook);
        $this->assertNotNull($newDelivery);
        $this->assertNotSame($sourceWebhookId, (int) $newWebhook->id);
        $this->assertSame((int) $newWebhook->id, (int) $newDelivery->webhook_id);
    }

    public function test_default_clone_skips_missing_crm_models_and_does_not_clone_transactional_data(): void
    {
        [$source] = $this->seedSource();
        $owner = User::factory()->create();

        $new = (new TeamCloneService())->clone($source, 'No Data', $owner);

        $this->assertSame('No Data', $new->name);
        $this->assertSame(0, DB::table('webhooks')->where('team_id', $new->id)->count());
    }

    public function test_source_team_is_untouched(): void
    {
        [$source, $webhookId, $deliveryId] = $this->seedSource();
        $owner = User::factory()->create();

        $service = new TeamCloneService(
            [Webhook::class, WebhookDelivery::class],
            [WebhookDelivery::class => ['webhook_id' => Webhook::class]],
        );

        $service->clone($source, 'Clone', $owner);

        $this->assertSame(1, DB::table('webhooks')->where('team_id', $source->id)->count());
        $this->assertSame($webhookId, (int) DB::table('crm_webhook_deliveries')->where('id', $deliveryId)->first()->webhook_id);
    }
}
