<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use Liberu\Foundation\Identity\Contracts\InvitationValidator;
use Liberu\Foundation\Identity\Contracts\RegistrationPolicy;
use Liberu\Foundation\Identity\Events\IdentityEvent;
use Liberu\Foundation\Identity\Listeners\EmitAuthenticationEvent;
use Liberu\Foundation\Identity\Support\ConfiguredRegistrationPolicy;
use Liberu\Foundation\Identity\Support\IdentifierNormalizer;
use Liberu\Foundation\Identity\Support\RejectingInvitationValidator;

it('binds registration policy and invitation validator in the container', function () {
    expect(app(RegistrationPolicy::class))->toBeInstanceOf(ConfiguredRegistrationPolicy::class)
        ->and(app(InvitationValidator::class))->toBeInstanceOf(RejectingInvitationValidator::class);
});

it('evaluates configured registration policy modes', function () {
    $open = new ConfiguredRegistrationPolicy('open');
    $invitation = new ConfiguredRegistrationPolicy('invitation');
    $closed = new ConfiguredRegistrationPolicy('closed');

    expect($open->permitsSelfRegistration())->toBeTrue()
        ->and($open->requiresInvitation())->toBeFalse()
        ->and($invitation->permitsSelfRegistration())->toBeTrue()
        ->and($invitation->requiresInvitation())->toBeTrue()
        ->and($closed->permitsSelfRegistration())->toBeFalse()
        ->and($closed->requiresInvitation())->toBeFalse();
});

it('normalizes email addresses and rejects invitations by default', function () {
    $normalizer = new IdentifierNormalizer();
    $validator = new RejectingInvitationValidator();

    expect($normalizer->email('  Ada@Example.TEST  '))->toBe('ada@example.test')
        ->and($validator->valid('ada@example.test', 'secret-token'))->toBeFalse();
});

it('emits identity events for auth lifecycle events and ignores unknown objects', function () {
    Event::fake([IdentityEvent::class]);

    $listener = new EmitAuthenticationEvent();
    $user = new GenericUser(['id' => 7]);

    $listener->handle(new Login('web', $user, false));
    $listener->handle(new Failed('web', $user, ['email' => 'ada@example.test']));
    $listener->handle(new Logout('web', $user));
    $listener->handle(new stdClass());

    Event::assertDispatched(IdentityEvent::class, 3);
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e) => $e->name === 'identity.login.succeeded' && $e->actorId === 7 && $e->context === ['guard' => 'web']);
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e) => $e->name === 'identity.login.failed' && $e->actorId === 7);
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e) => $e->name === 'identity.logout.completed' && $e->actorId === 7);
});
