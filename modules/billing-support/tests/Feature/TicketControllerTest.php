<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCustomField;
use App\Models\User;
use App\Notifications\NewTicketNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

it('lists only own tickets for regular users and all tickets for admins', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));

    $ownTicket = Ticket::factory()->for($user)->create(['title' => 'My Private Issue']);
    $otherTicket = Ticket::factory()->for($other)->create(['title' => 'Someone Else Issue']);

    $this->actingAs($user)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertViewIs('tickets.index')
        ->assertSee($ownTicket->title)
        ->assertDontSee($otherTicket->title);

    $this->actingAs($admin)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertViewIs('tickets.index')
        ->assertSee($ownTicket->title)
        ->assertSee($otherTicket->title);
});

it('renders the ticket creation page with customer subscriptions when present', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($user)
        ->get(route('tickets.create'))
        ->assertOk()
        ->assertViewIs('tickets.create')
        ->assertViewHas('subscriptions', fn ($subs): bool => $subs->contains($subscription));

    $userWithoutCustomer = User::factory()->create();
    $this->actingAs($userWithoutCustomer)
        ->get(route('tickets.create'))
        ->assertOk()
        ->assertViewIs('tickets.create');
});

it('stores a ticket, validates subscription ownership, and notifies admins', function (): void {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $user = User::factory()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $ownedSubscription = Subscription::factory()->create(['customer_id' => $customer->id]);

    $otherUser = User::factory()->create();
    $otherCustomer = Customer::factory()->create(['user_id' => $otherUser->id]);
    $foreignSubscription = Subscription::factory()->create(['customer_id' => $otherCustomer->id]);

    $optionalField = TicketCustomField::factory()->create(['is_required' => false, 'is_active' => true]);

    // Attempting to attach someone else's subscription returns 404.
    $this->actingAs($user)
        ->post(route('tickets.store'), [
            'title' => 'Hijack Attempt',
            'description' => 'Should fail',
            'priority' => 'medium',
            'subscription_id' => $foreignSubscription->id,
        ])
        ->assertNotFound();

    // Valid creation with owned subscription and custom field.
    $response = $this->actingAs($user)
        ->post(route('tickets.store'), [
            'title' => 'DNS Propagation Delay',
            'description' => 'Records have not propagated yet.',
            'priority' => 'high',
            'subscription_id' => $ownedSubscription->id,
            'domain_name' => 'example.test',
            'custom_fields' => [$optionalField->id => 'ns1.example.test'],
        ]);

    $ticket = Ticket::query()->where('title', 'DNS Propagation Delay')->firstOrFail();

    $response->assertRedirect(route('tickets.show', $ticket))
        ->assertSessionHas('success', 'Ticket created successfully.');

    expect($ticket->subscription_id)->toBe($ownedSubscription->id)
        ->and($ticket->domain_name)->toBe('example.test');

    Notification::assertSentTo([$admin, $superAdmin], NewTicketNotification::class);
});

it('shows a ticket to its owner and admins, and forbids other users', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $other = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));

    $ticket = Ticket::factory()->for($owner)->create(['title' => 'Billing Question']);

    $this->actingAs($owner)
        ->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertViewIs('tickets.show')
        ->assertSee('Billing Question');

    $this->actingAs($admin)
        ->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertViewIs('tickets.show');

    $this->actingAs($other)
        ->get(route('tickets.show', $ticket))
        ->assertForbidden();
});

it('allows admins to update ticket status and assign staff, and forbids non-admins', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $staff = User::factory()->create();
    $ticket = Ticket::factory()->for($owner)->create(['status' => 'open', 'assigned_to' => null]);

    $this->actingAs($owner)
        ->put(route('tickets.update', $ticket), ['status' => 'closed'])
        ->assertForbidden();

    $this->actingAs($admin)
        ->from(route('tickets.show', $ticket))
        ->put(route('tickets.update', $ticket), ['status' => 'in_progress'])
        ->assertRedirect(route('tickets.show', $ticket))
        ->assertSessionHas('success', 'Ticket status updated.');

    expect($ticket->fresh()->status)->toBe('in_progress');

    $this->actingAs($owner)
        ->post(route('tickets.assign', $ticket), ['assigned_to' => $staff->id])
        ->assertForbidden();

    $this->actingAs($admin)
        ->from(route('tickets.show', $ticket))
        ->post(route('tickets.assign', $ticket), ['assigned_to' => $staff->id])
        ->assertRedirect(route('tickets.show', $ticket))
        ->assertSessionHas('success', 'Ticket assignment updated.');

    expect($ticket->fresh()->assigned_to)->toBe($staff->id);

    $this->actingAs($admin)
        ->from(route('tickets.show', $ticket))
        ->post(route('tickets.assign', $ticket), ['assigned_to' => null])
        ->assertRedirect(route('tickets.show', $ticket));

    expect($ticket->fresh()->assigned_to)->toBeNull();
});

it('creates a project from a ticket or surfaces an error when no customer is resolvable', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $ticket = Ticket::factory()->for($owner)->create(['title' => 'Website Migration']);

    // Non-admin is forbidden.
    $this->actingAs($owner)
        ->post(route('tickets.project.create', $ticket))
        ->assertForbidden();

    // Without a customer linked or selected, RuntimeException is converted into a form error.
    $this->actingAs($admin)
        ->from(route('tickets.show', $ticket))
        ->post(route('tickets.project.create', $ticket))
        ->assertRedirect(route('tickets.show', $ticket))
        ->assertSessionHasErrors('customer_id');

    // With an explicit customer_id, a project is created and linked.
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'team_id' => $owner->current_team_id,
    ]);

    $this->actingAs($admin)
        ->from(route('tickets.show', $ticket))
        ->post(route('tickets.project.create', $ticket), ['customer_id' => $customer->id])
        ->assertRedirect(route('tickets.show', $ticket))
        ->assertSessionHas('success');

    $ticket->refresh();
    expect($ticket->project_id)->not->toBeNull()
        ->and(Project::find($ticket->project_id))->not->toBeNull();
});

it('allows the ticket owner to download an attachment', function (): void {
    Storage::fake('local');

    $owner = User::factory()->create();
    $ticket = Ticket::factory()->for($owner)->create();
    $path = UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')
        ->store('ticket-attachments', 'local');

    $attachment = TicketAttachment::factory()->create([
        'attachable_type' => Ticket::class,
        'attachable_id' => $ticket->id,
        'uploaded_by' => $owner->id,
        'path' => $path,
        'original_name' => 'invoice.pdf',
    ]);

    $this->actingAs($owner)
        ->get(route('tickets.attachments.download', $attachment))
        ->assertOk()
        ->assertDownload('invoice.pdf');
});

it('builds the NewTicketNotification mail representation', function (): void {
    $ticket = Ticket::factory()->create([
        'title' => 'Server Offline',
        'priority' => 'high',
    ]);
    $notifiable = User::factory()->create();

    $notification = new NewTicketNotification($ticket);

    expect($notification->via($notifiable))->toBe(['mail']);

    $mail = $notification->toMail($notifiable);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe('New Support Ticket Created')
        ->and($mail->greeting)->toBe('Hello!')
        ->and($mail->actionText)->toBe('View Ticket')
        ->and($mail->actionUrl)->toBe(url('/tickets/'.$ticket->id))
        ->and($mail->introLines)->toContain('Title: Server Offline')
        ->and($mail->introLines)->toContain('Priority: High');
});
