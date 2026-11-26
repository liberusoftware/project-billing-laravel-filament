<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyInboundEmailSignature;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCustomField;
use App\Models\TicketDepartment;
use App\Models\TicketEscalationRule;
use App\Models\TicketResponse;
use App\Models\User;
use App\Services\InboundEmailService;
use App\Services\TicketEscalationService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

it('runs the tickets:escalate artisan command and reports escalated count', function (): void {
    Ticket::factory()->create([
        'status' => 'open',
        'priority' => 'low',
        'created_at' => now()->subHours(4),
    ]);

    TicketEscalationRule::factory()->create([
        'minutes_without_response' => 60,
        'action' => 'raise_priority',
        'is_active' => true,
    ]);

    $this->artisan('tickets:escalate')
        ->expectsOutput('Escalated 1 tickets')
        ->assertSuccessful();
});

it('handles inbound email API requests for both new tickets and ticket responses', function (): void {
    app('router')->aliasMiddleware('inbound-email.verify', VerifyInboundEmailSignature::class);
    config()->set('services.inbound_email.secret', 'webhook-secret');

    $sender = User::factory()->create(['email' => 'client@example.test']);
    $department = TicketDepartment::factory()->create([
        'email' => 'support@example.test',
        'is_active' => true,
    ]);

    $newTicketBody = json_encode([
        'from' => 'client@example.test',
        'to' => 'support@example.test',
        'subject' => 'Help with invoice',
        'body' => 'Please resend my invoice.',
    ], JSON_THROW_ON_ERROR);

    $createResponse = $this->call(
        'POST',
        '/api/inbound-email',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $newTicketBody, 'webhook-secret'),
        ],
        $newTicketBody,
    );

    $createResponse->assertCreated()
        ->assertJsonPath('type', 'ticket');

    $ticketId = $createResponse->json('ticket_id');
    expect($ticketId)->not->toBeNull()
        ->and($createResponse->json('id'))->toBe($ticketId);

    $replyBody = json_encode([
        'from' => 'client@example.test',
        'to' => 'support@example.test',
        'subject' => 'Follow up',
        'body' => 'Here is the invoice number.',
        'ticket_id' => $ticketId,
    ], JSON_THROW_ON_ERROR);

    $replyResponse = $this->call(
        'POST',
        '/api/inbound-email',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $replyBody, 'webhook-secret'),
        ],
        $replyBody,
    );

    $replyResponse->assertCreated()
        ->assertJsonPath('type', 'response')
        ->assertJsonPath('ticket_id', $ticketId);

    expect($department->tickets()->whereKey($ticketId)->exists())->toBeTrue();
});

it('resolves inbound email sender via customer email, falls back to active department, and handles empty sender', function (): void {
    $user = User::factory()->create(['email' => 'account@example.test']);
    Customer::factory()->create([
        'user_id' => $user->id,
        'email' => 'billing-contact@example.test',
    ]);
    $fallbackDepartment = TicketDepartment::factory()->create([
        'email' => 'general@example.test',
        'is_active' => true,
    ]);

    $service = app(InboundEmailService::class);

    // Sender email matches Customer (not User), and `to` does not match any department email -> falls back to active department.
    $ticket = $service->handle([
        'from' => 'billing-contact@example.test',
        'to' => 'unknown-dept@example.test',
        'subject' => '',
        'body' => 'Need help.',
    ]);

    expect($ticket)->toBeInstanceOf(Ticket::class)
        ->and($ticket->user_id)->toBe($user->id)
        ->and($ticket->department_id)->toBe($fallbackDepartment->id)
        ->and($ticket->title)->toBe('Inbound email');

    // Empty `from` returns null from resolveSender; tickets.user_id is non-nullable so insert fails.
    expect(fn () => $service->handle([
        'from' => '',
        'to' => '',
        'subject' => 'Anonymous',
        'body' => 'No sender.',
    ]))->toThrow(QueryException::class);
});

it('handles notify escalation, high-priority ceiling, and no-op reassignments', function (): void {
    Log::spy();

    $target = User::factory()->create();
    $ticket = Ticket::factory()->create([
        'status' => 'open',
        'priority' => 'high',
        'assigned_to' => $target->id,
        'created_at' => now()->subHours(5),
    ]);

    // Already at high priority -> raisePriority returns false.
    TicketEscalationRule::factory()->create([
        'minutes_without_response' => 60,
        'action' => 'raise_priority',
        'is_active' => true,
    ]);

    // Target user is null -> reassign returns false.
    TicketEscalationRule::factory()->create([
        'minutes_without_response' => 60,
        'action' => 'reassign',
        'target_user_id' => null,
        'is_active' => true,
    ]);

    // Already assigned to target user -> reassign returns false.
    TicketEscalationRule::factory()->create([
        'minutes_without_response' => 60,
        'action' => 'reassign',
        'target_user_id' => $target->id,
        'is_active' => true,
    ]);

    // Notify logs info and returns true.
    TicketEscalationRule::factory()->create([
        'minutes_without_response' => 60,
        'action' => 'notify',
        'is_active' => true,
    ]);

    $escalated = app(TicketEscalationService::class)->escalate();

    expect($escalated)->toBe(1);
    Log::shouldHaveReceived('info')
        ->with('Ticket escalation: notify', ['ticket_id' => $ticket->id])
        ->once();
});

it('exposes all relationships and owningTicket branches across support models', function (): void {
    if (! Schema::hasColumn('tickets', 'subscription_id')) {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('subscription_id')->nullable();
        });
    }

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    $customer = Customer::factory()->create(['user_id' => $user->id, 'team_id' => $team->id]);
    $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);
    $project = Project::create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'name' => 'Support Project',
        'description' => 'Linked project',
    ]);
    $department = TicketDepartment::factory()->create(['team_id' => $team->id]);

    $ticket = Ticket::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'subscription_id' => $subscription->id,
        'department_id' => $department->id,
    ]);

    $response = TicketResponse::create([
        'ticket_id' => $ticket->id,
        'user_id' => $user->id,
        'message' => 'Checking into this.',
    ]);

    $responseAttachment = TicketAttachment::factory()->create([
        'attachable_type' => TicketResponse::class,
        'attachable_id' => $response->id,
        'uploaded_by' => $user->id,
    ]);

    $orphanAttachment = TicketAttachment::factory()->create([
        'attachable_type' => User::class,
        'attachable_id' => $user->id,
        'uploaded_by' => $user->id,
    ]);

    $customField = TicketCustomField::factory()->create([
        'team_id' => $team->id,
        'department_id' => $department->id,
    ]);

    $escalationRule = TicketEscalationRule::factory()->create([
        'team_id' => $team->id,
        'department_id' => $department->id,
        'target_user_id' => $user->id,
    ]);

    expect($ticket->project->is($project))->toBeTrue()
        ->and($ticket->subscription->is($subscription))->toBeTrue()
        ->and($response->ticket->is($ticket))->toBeTrue()
        ->and($response->user->is($user))->toBeTrue()
        ->and($responseAttachment->uploader->is($user))->toBeTrue()
        ->and($responseAttachment->owningTicket()->is($ticket))->toBeTrue()
        ->and($customField->team->is($team))->toBeTrue()
        ->and($customField->department->is($department))->toBeTrue()
        ->and($escalationRule->department->is($department))->toBeTrue()
        ->and($escalationRule->targetUser->is($user))->toBeTrue();

    expect(fn () => $orphanAttachment->owningTicket())
        ->toThrow(RuntimeException::class, 'Attachment has no owning ticket.');
});
