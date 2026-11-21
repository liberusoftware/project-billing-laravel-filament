<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\CannedResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TicketResponseController extends Controller
{
    public function store(Request $request, Ticket $ticket, CannedResponseService $responses): RedirectResponse
    {
        $this->authorize('respond', $ticket);
        abort_if($ticket->status === 'closed', 403);

        $validated = $request->validate([
            'message' => ['required_without:canned_response_id', 'nullable', 'string', 'max:10000'],
            'canned_response_id' => ['nullable', 'integer'],
        ]);
        $message = $validated['message'] ?? null;

        if (isset($validated['canned_response_id'])) {
            $this->authorize('update', $ticket);
            $response = $responses->getAll($ticket->user?->currentTeam?->getKey())->find($validated['canned_response_id']);
            if ($response === null) {
                throw ValidationException::withMessages(['canned_response_id' => 'The selected canned response is unavailable.']);
            }

            $message ??= $responses->use($response, [
                'client_name' => $ticket->user?->name ?? '',
                'client_email' => $ticket->user?->email ?? '',
                'ticket_id' => (string) $ticket->getKey(),
                'ticket_subject' => $ticket->title,
            ]);
        }

        $ticket->responses()->create([
            'user_id' => $request->user()->getAuthIdentifier(),
            'message' => $message,
        ]);

        return to_route('tickets.show', $ticket)->with('success', 'Reply sent.');
    }
}
