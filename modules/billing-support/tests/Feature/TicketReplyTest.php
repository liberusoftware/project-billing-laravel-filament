<?php

declare(strict_types=1);

use App\Models\CannedResponse;
use App\Models\Ticket;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('records a reply from the ticket owner with the authenticated author', function (): void {
    $owner = User::factory()->create();
    $ticket = Ticket::factory()->for($owner)->create(['status' => 'open']);

    $this->actingAs($owner)->post(route('ticket.responses.store', $ticket), [
        'message' => 'Here is the information you requested.',
        'user_id' => 0,
    ])->assertRedirectToRoute('tickets.show', $ticket);

    $this->assertDatabaseHas('ticket_responses', [
        'ticket_id' => $ticket->id,
        'user_id' => $owner->id,
        'message' => 'Here is the information you requested.',
    ]);
});

it('denies replies from other customers and replies to closed tickets', function (): void {
    $owner = User::factory()->create();
    $ticket = Ticket::factory()->for($owner)->create(['status' => 'open']);
    $destination = route('ticket.responses.store', $ticket);

    $this->actingAs(User::factory()->create())->post($destination, ['message' => 'External'])->assertForbidden();

    $ticket->update(['status' => 'closed']);
    $this->actingAs($owner)->post($destination, ['message' => 'Too late'])->assertForbidden();

    $this->assertDatabaseCount('ticket_responses', 0);
});

it('restricts canned replies to staff and renders an available template', function (): void {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $staff->assignRole(Role::findOrCreate('admin', 'web'));
    $ticket = Ticket::factory()->for($owner)->create(['status' => 'open']);
    $template = CannedResponse::query()->create([
        'title' => 'Follow-up',
        'shortcode' => 'follow-up',
        'content' => 'Following up on {{ticket_subject}}.',
        'is_active' => true,
    ]);
    $destination = route('ticket.responses.store', $ticket);

    $this->actingAs($owner)->post($destination, ['canned_response_id' => $template->id])->assertForbidden();
    $this->actingAs($staff)->postJson($destination, ['canned_response_id' => 0])->assertUnprocessable()
        ->assertJsonValidationErrors('canned_response_id');
    $this->actingAs($staff)->post($destination, ['canned_response_id' => $template->id])
        ->assertRedirectToRoute('tickets.show', $ticket);

    $this->assertDatabaseHas('ticket_responses', [
        'ticket_id' => $ticket->id,
        'user_id' => $staff->id,
        'message' => "Following up on {$ticket->title}.",
    ]);
    expect($template->fresh()->usage_count)->toBe(1);
});
