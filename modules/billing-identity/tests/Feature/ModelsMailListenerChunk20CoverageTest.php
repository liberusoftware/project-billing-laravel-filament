<?php

declare(strict_types=1);

use App\Enums\OrganisationType;
use App\Listeners\SwitchTeam;
use App\Mail\TeamInvitation as MailTeamInvitation;
use App\Models\ConnectedAccount;
use App\Models\Invoice;
use App\Models\ResellerAgreement;
use App\Models\ResellerRevenueTransaction;
use App\Models\ResellerServiceDelegation;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

it('constructs and handles events in SwitchTeam listener', function () {
    $listener = new SwitchTeam();
    $listener->handle(new stdClass());

    expect($listener)->toBeInstanceOf(SwitchTeam::class);
});

it('builds envelope and content for TeamInvitation mailable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    $mailable = new MailTeamInvitation($user, $team, 'sample-token');
    $envelope = $mailable->envelope();
    $content = $mailable->content();

    expect($mailable->token)->toBe('sample-token')
        ->and($envelope->subject)->toBe('You have been invited to join a team')
        ->and($content->view)->toBe('emails.team-invitation');
});

it('resolves ConnectedAccount user relationship', function () {
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->create(['user_id' => $user->id]);

    expect($account->user)->toBeInstanceOf(User::class)
        ->and($account->user->is($user))->toBeTrue();
});

it('resolves reseller and team hierarchy relationships', function () {
    $user = User::factory()->create();
    $provider = Team::factory()->create([
        'user_id' => $user->id,
        'organisation_type' => OrganisationType::Company,
    ]);
    $reseller = Team::factory()->create([
        'user_id' => $user->id,
        'parent_team_id' => $provider->id,
        'organisation_type' => OrganisationType::Reseller,
    ]);

    $agreement = ResellerAgreement::query()->create([
        'provider_team_id' => $provider->id,
        'reseller_team_id' => $reseller->id,
        'default_discount_percent' => 10,
        'revenue_share_percent' => 20,
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $subscription = Subscription::factory()->create([
        'team_id' => $provider->id,
        'price' => 100,
        'currency' => 'USD',
    ]);

    $delegation = ResellerServiceDelegation::query()->create([
        'reseller_agreement_id' => $agreement->id,
        'subscription_id' => $subscription->id,
        'wholesale_price' => 90,
        'retail_price' => 100,
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $invoice = Invoice::factory()->create(['team_id' => $provider->id]);

    $transaction = ResellerRevenueTransaction::query()->create([
        'reseller_agreement_id' => $agreement->id,
        'reseller_service_delegation_id' => $delegation->id,
        'invoice_id' => $invoice->id,
        'gross_amount' => 100,
        'provider_amount' => 80,
        'reseller_amount' => 20,
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    // Team hierarchy & reseller agreement relations
    expect($reseller->parent->is($provider))->toBeTrue()
        ->and($provider->children->pluck('id')->all())->toContain($reseller->id)
        ->and($provider->resellerAgreements->pluck('id')->all())->toContain($agreement->id)
        ->and($reseller->resellerAgreement->is($agreement))->toBeTrue();

    // ResellerAgreement relations
    expect($agreement->providerTeam->is($provider))->toBeTrue()
        ->and($agreement->resellerTeam->is($reseller))->toBeTrue();

    // ResellerServiceDelegation relations
    expect($delegation->subscription->is($subscription))->toBeTrue()
        ->and($delegation->revenueTransactions->pluck('id')->all())->toContain($transaction->id);

    // ResellerRevenueTransaction relations
    expect($transaction->agreement->is($agreement))->toBeTrue()
        ->and($transaction->delegation->is($delegation))->toBeTrue()
        ->and($transaction->invoice->is($invoice))->toBeTrue();
});

it('auto-generates tokens and resolves relationships on TeamInvitation model', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    $autoTokenInvitation = TeamInvitation::query()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'email' => 'auto-token@example.com',
    ]);

    $explicitTokenInvitation = TeamInvitation::query()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'email' => 'explicit-token@example.com',
        'token' => 'explicit-custom-token-value',
    ]);

    expect($autoTokenInvitation->token)->toBeString()
        ->and(strlen($autoTokenInvitation->token))->toBe(64)
        ->and($explicitTokenInvitation->token)->toBe('explicit-custom-token-value')
        ->and($autoTokenInvitation->team->is($team))->toBeTrue()
        ->and($autoTokenInvitation->user->is($user))->toBeTrue();
});

it('covers User profilePhotoUrl URL branch and relationships', function () {
    $user = User::factory()->create([
        'profile_photo_path' => 'https://cdn.example.com/avatars/user.png',
    ]);

    expect($user->profile_photo_url)->toBe('https://cdn.example.com/avatars/user.png')
        ->and($user->subscription())->toBeInstanceOf(HasOneThrough::class)
        ->and($user->invitations())->toBeInstanceOf(HasMany::class)
        ->and($user->tickets())->toBeInstanceOf(HasMany::class)
        ->and($user->integrations())->toBeInstanceOf(HasMany::class)
        ->and($user->referrer())->toBeInstanceOf(BelongsTo::class);
});
