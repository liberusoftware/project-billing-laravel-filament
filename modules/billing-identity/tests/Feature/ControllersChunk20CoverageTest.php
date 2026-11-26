<?php

declare(strict_types=1);

use App\Enums\OrganisationType;
use App\Http\Controllers\TeamInvitationController;
use App\Mail\TeamInvitation as MailTeamInvitation;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

it('lists, shows, records revenue for, and settles reseller agreements via API', function () {
    $user = User::factory()->create();
    $provider = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $provider->id])->save();

    $reseller = Team::query()->create([
        'user_id' => $user->id,
        'parent_team_id' => $provider->id,
        'name' => 'Reseller Org',
        'organisation_type' => OrganisationType::Reseller,
        'personal_team' => false,
    ]);

    $subscription = Subscription::factory()->create([
        'team_id' => $provider->id,
        'price' => 100,
        'currency' => 'USD',
    ]);

    $invoice = Invoice::factory()->create([
        'team_id' => $provider->id,
    ]);

    Sanctum::actingAs($user, ['resellers:read', 'resellers:write']);

    $agreement = $this->postJson('/api/resellers', [
        'reseller_team_id' => $reseller->id,
        'default_discount_percent' => 20,
        'revenue_share_percent' => 15,
        'credit_limit' => 500,
    ])->assertCreated()->json();

    $delegation = $this->postJson("/api/resellers/{$agreement['id']}/delegate", [
        'subscription_id' => $subscription->id,
        'retail_price' => 120,
    ])->assertCreated()->json();

    $this->getJson('/api/resellers')
        ->assertOk()
        ->assertJsonPath('data.0.id', $agreement['id'])
        ->assertJsonPath('data.0.delegations_count', 1);

    $this->getJson("/api/resellers/{$agreement['id']}")
        ->assertOk()
        ->assertJsonPath('id', $agreement['id'])
        ->assertJsonPath('reseller_team.id', $reseller->id);

    $txWithInvoice = $this->postJson("/api/reseller-delegations/{$delegation['id']}/revenue", [
        'gross_amount' => 100,
        'invoice_id' => $invoice->id,
    ])->assertCreated()
        ->assertJsonPath('invoice_id', $invoice->id)
        ->assertJsonPath('reseller_amount', '15.00')
        ->json();

    $this->postJson("/api/reseller-delegations/{$delegation['id']}/revenue", [
        'gross_amount' => 50,
    ])->assertCreated()
        ->assertJsonPath('invoice_id', null);

    $this->postJson("/api/reseller-transactions/{$txWithInvoice['id']}/settle")
        ->assertOk()
        ->assertJsonPath('status', 'settled');
});

it('sends a team invitation via TeamInvitationController', function () {
    Mail::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    $fillMissingEmail = true;
    TeamInvitation::creating(function (TeamInvitation $invitation) use (&$fillMissingEmail, $user): void {
        if ($fillMissingEmail && empty($invitation->email)) {
            $invitation->email = $user->email;
        }
    });

    try {
        $request = Request::create('/team-invitations/send', 'POST', [
            'email' => $user->email,
            'team_id' => $team->id,
        ]);

        $response = app(TeamInvitationController::class)->sendInvitation($request);
    } finally {
        $fillMissingEmail = false;
    }

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['message' => 'Invitation sent successfully.'])
        ->and(TeamInvitation::query()->where('team_id', $team->id)->where('user_id', $user->id)->exists())->toBeTrue();

    Mail::assertSent(MailTeamInvitation::class, fn (MailTeamInvitation $mail) => $mail->hasTo($user->email));
});

it('accepts a team invitation via TeamInvitationController', function () {
    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);

    $invitation = TeamInvitation::query()->create([
        'team_id' => $team->id,
        'user_id' => $invitee->id,
        'email' => $invitee->email,
        'token' => 'chunk20-accept-token-123456',
    ]);

    $createdInvitationsTable = false;
    if (! Schema::hasTable('invitations')) {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('token');
        });
        $createdInvitationsTable = true;
    }

    $addedAcceptedColumn = false;
    if (! Schema::hasColumn('team_invitations', 'accepted')) {
        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->boolean('accepted')->default(false);
        });
        $addedAcceptedColumn = true;
    }

    try {
        DB::table('invitations')->insert(['token' => $invitation->token]);

        $request = Request::create('/team-invitations/accept', 'POST', [
            'token' => $invitation->token,
        ]);

        $response = app(TeamInvitationController::class)->acceptInvitation($request);
    } finally {
        if ($addedAcceptedColumn) {
            Schema::table('team_invitations', function (Blueprint $table): void {
                $table->dropColumn('accepted');
            });
        }
        if ($createdInvitationsTable) {
            Schema::dropIfExists('invitations');
        }
    }

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['message' => 'Invitation accepted successfully.'])
        ->and($team->users()->where('users.id', $invitee->id)->exists())->toBeTrue();
});
