<?php

declare(strict_types=1);

use App\Listeners\EmailTracker;
use App\Models\CallDetailRecord;
use App\Models\CallRateRule;
use App\Models\Customer;
use App\Models\DidNumber;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignStat;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Models\VoipAccount;
use App\Models\VoipFraudAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Carbon;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class);

test('voip models expose expected relationships', function (): void {
    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $subscription = Subscription::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
    ]);

    $account = VoipAccount::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'subscription_id' => $subscription->id,
    ]);

    $rateRule = CallRateRule::query()->create([
        'team_id' => $team->id,
        'name' => 'US Rate',
        'destination_prefix' => '+1',
        'rate_per_minute' => 0.05,
        'billing_increment_seconds' => 60,
        'currency' => 'USD',
    ]);

    $did = DidNumber::query()->create([
        'team_id' => $team->id,
        'voip_account_id' => $account->id,
        'number' => '+15559990001',
        'country_code' => 'US',
        'monthly_price' => 2.00,
        'currency' => 'USD',
        'status' => 'assigned',
    ]);

    $cdr = CallDetailRecord::query()->create([
        'team_id' => $team->id,
        'voip_account_id' => $account->id,
        'call_rate_rule_id' => $rateRule->id,
        'external_id' => 'rel-call-1',
        'source' => '+15559990001',
        'destination' => '+15559990002',
        'started_at' => now()->subMinute(),
        'duration_seconds' => 60,
        'billable_seconds' => 60,
        'rated_cost' => 0.05,
        'currency' => 'USD',
    ]);

    $alert = VoipFraudAlert::query()->create([
        'team_id' => $team->id,
        'voip_account_id' => $account->id,
        'call_detail_record_id' => $cdr->id,
        'rule' => 'high_call_cost',
        'severity' => 'high',
        'message' => 'Cost threshold exceeded',
        'context' => ['rated_cost' => '0.0500'],
    ]);

    expect($account->subscription->id)->toBe($subscription->id)
        ->and($did->voipAccount->id)->toBe($account->id)
        ->and($cdr->voipAccount->id)->toBe($account->id)
        ->and($cdr->rateRule->id)->toBe($rateRule->id)
        ->and($alert->voipAccount->id)->toBe($account->id)
        ->and($alert->callDetailRecord->id)->toBe($cdr->id);
});

test('email campaign casts, relationships, status transitions, counters, and progress percentage work', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $lead = Lead::query()->create([
        'team_id' => $team->id,
        'name' => 'Jane Lead',
        'email' => 'jane@example.test',
    ]);

    $campaign = EmailCampaign::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'name' => 'Spring Promo',
        'subject' => 'Save 20%',
        'content' => '<p>Hello!</p>',
        'recipient_filters' => ['status' => 'active'],
        'status' => 'draft',
        'total_recipients' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
        'scheduled_at' => now()->addHour(),
    ]);

    expect($campaign->recipient_filters)->toBe(['status' => 'active'])
        ->and($campaign->scheduled_at)->toBeInstanceOf(Carbon::class)
        ->and($campaign->team->id)->toBe($team->id)
        ->and($campaign->creator->id)->toBe($user->id)
        ->and($campaign->getProgressPercentage())->toBe(0.0);

    $campaign->markAsSending();
    $campaign->refresh();
    expect($campaign->status)->toBe('sending')
        ->and($campaign->started_sending_at)->toBeInstanceOf(Carbon::class);

    $campaign->update(['total_recipients' => 3]);
    $campaign->incrementSent();
    $campaign->incrementFailed();
    $campaign->refresh();

    expect($campaign->sent_count)->toBe(1)
        ->and($campaign->failed_count)->toBe(1)
        ->and($campaign->getProgressPercentage())->toBe(66.67);

    $campaign->markAsSent();
    $campaign->refresh();
    expect($campaign->status)->toBe('sent')
        ->and($campaign->completed_at)->toBeInstanceOf(Carbon::class);

    $stat = $campaign->emailStats()->create([
        'lead_id' => $lead->id,
        'sent_at' => now(),
    ]);

    expect($campaign->emailStats)->toHaveCount(1)
        ->and($stat->emailCampaign->id)->toBe($campaign->id)
        ->and($stat->lead->id)->toBe($lead->id);
});

test('email template getTemplate prioritizes team template over default and falls back to default', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $default = EmailTemplate::query()->create([
        'name' => 'Default Invoice',
        'type' => 'invoice_generated',
        'subject' => 'Default Subject',
        'body' => 'Default Body',
        'team_id' => null,
        'is_default' => true,
    ]);

    expect(EmailTemplate::getTemplate('invoice_generated', $team->id)?->id)->toBe($default->id)
        ->and(EmailTemplate::getTemplate('nonexistent_type', $team->id))->toBeNull();

    $teamTemplate = EmailTemplate::query()->create([
        'name' => 'Team Invoice',
        'type' => 'invoice_generated',
        'subject' => 'Team Subject',
        'body' => 'Team Body',
        'team_id' => $team->id,
        'is_default' => false,
    ]);

    expect(EmailTemplate::getTemplate('invoice_generated', $team->id)?->id)->toBe($teamTemplate->id)
        ->and(EmailTemplate::getTemplate('invoice_generated', $otherTeam->id)?->id)->toBe($default->id);
});

test('email tracker records campaign stat when campaign and lead headers match', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $lead = Lead::query()->create([
        'team_id' => $team->id,
        'name' => 'Tracked Lead',
        'email' => 'tracked@example.test',
    ]);
    $campaign = EmailCampaign::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'name' => 'Tracked Campaign',
        'subject' => 'Hello',
        'content' => '<p>Body</p>',
    ]);

    $headers = new class($campaign->id, $lead->id)
    {
        public function __construct(
            private readonly int $campaignId,
            private readonly int $leadId,
        ) {}

        public function get(string $name): ?int
        {
            return match ($name) {
                'X-Campaign-ID' => $this->campaignId,
                'X-Lead-ID' => $this->leadId,
                default => null,
            };
        }
    };

    $originalMessage = new class($headers)
    {
        public function __construct(private readonly object $headers) {}

        public function getHeaders(): object
        {
            return $this->headers;
        }
    };

    $sentMessage = Mockery::mock(SentMessage::class);
    $sentMessage->shouldReceive('getOriginalMessage')->once()->andReturn($originalMessage);

    (new EmailTracker())->handle(new MessageSent($sentMessage));

    $this->assertDatabaseHas('email_campaign_stats', [
        'email_campaign_id' => $campaign->id,
        'lead_id' => $lead->id,
    ]);
});

test('email tracker ignores messages without campaign and lead headers', function (): void {
    $email = (new Email())
        ->from('sender@example.test')
        ->to('recipient@example.test')
        ->subject('Plain email')
        ->text('No tracking headers.');

    $symfonySent = new SymfonySentMessage(
        $email,
        new Envelope(new Address('sender@example.test'), [new Address('recipient@example.test')])
    );

    (new EmailTracker())->handle(new MessageSent(new SentMessage($symfonySent)));

    expect(EmailCampaignStat::query()->count())->toBe(0);
});
