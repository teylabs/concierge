<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\InviteTenant;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Registration\Registration;
use Tey\Concierge\Registration\RegistrationMode;
use Tey\Concierge\Registration\RegistrationRefusal;
use Tey\Concierge\Rules\MayRegister;

function registration_mode(string $mode, string|array|null $domains = null): void
{
    config()->set('concierge.registration.mode', $mode);
    config()->set('concierge.registration.domains', $domains);
}

function registration_errors(array $data, ?MayRegister $rule = null): array
{
    return Validator::make($data, ['email' => ['required', $rule ?? new MayRegister]])->errors()->get('email');
}

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
});

it('defaults to invitation mode with no domains', function () {
    expect(config('concierge.registration'))->toBe(['mode' => 'invitation', 'domains' => ''])
        ->and(Registration::mode())->toBe(RegistrationMode::Invitation)
        ->and(Registration::domains())->toBe([])
        ->and(Registration::open())->toBeFalse();
});

it('lets anyone register in open mode', function () {
    registration_mode('open');

    expect(Registration::open())->toBeTrue()
        ->and(Registration::decide('anyone@anywhere.test')->allowed)->toBeTrue()
        ->and(Registration::decide('anyone@anywhere.test', 'not-a-token')->allowed)->toBeTrue();
});

it('lets no one register in closed mode, invitation or not', function () {
    registration_mode('closed');
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    $decision = Registration::decide('alice@example.test', $issued->token);

    expect(Registration::closed())->toBeTrue()
        ->and($decision->refused())->toBeTrue()
        ->and($decision->refusal)->toBe(RegistrationRefusal::Closed)
        ->and($decision->message())->toBe('Registration is closed.');
});

it('fails closed on a mode it does not know', function (string $mode) {
    registration_mode($mode);

    expect(Registration::mode())->toBe(RegistrationMode::Closed)
        ->and(Registration::decide('anyone@anywhere.test')->refusal)->toBe(RegistrationRefusal::Closed);
})->with(['anyone', 'open,domains', 'invite']);

it('tolerates case and whitespace in the mode, and closes on an empty or null one', function () {
    registration_mode(' OPEN ');
    expect(Registration::mode())->toBe(RegistrationMode::Open);

    registration_mode('');
    expect(Registration::mode())->toBe(RegistrationMode::Closed);

    config()->set('concierge.registration.mode', null);
    expect(Registration::mode())->toBe(RegistrationMode::Closed);
});

it('requires a valid invitation for the same email in invitation mode', function () {
    $issued = issue($this->owner, $this->workspace, 'Alice@Example.test');

    expect(Registration::decide('alice@example.test')->refusal)->toBe(RegistrationRefusal::InvitationRequired)
        ->and(Registration::decide('alice@example.test', '')->refusal)->toBe(RegistrationRefusal::InvitationRequired)
        ->and(Registration::decide('alice@example.test', $issued->token)->allowed)->toBeTrue()
        ->and(Registration::decide('ALICE@example.TEST', $issued->token)->allowed)->toBeTrue()
        ->and(Registration::decide('mallory@example.test', $issued->token)->refusal)->toBe(RegistrationRefusal::InvitationForAnotherEmail);
});

it('refuses a forged token, and a token that only differs in case', function () {
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    expect(Registration::decide('alice@example.test', str_repeat('A', strlen($issued->token)))->refusal)->toBe(RegistrationRefusal::InvitationInvalid)
        ->and(Registration::decide('alice@example.test', strtoupper($issued->token))->refusal)->toBe(RegistrationRefusal::InvitationInvalid)
        ->and(Registration::decide('alice@example.test', $issued->invitation->token_hash)->refusal)->toBe(RegistrationRefusal::InvitationInvalid);
});

it('refuses an expired, revoked or already accepted invitation', function () {
    $expired = issue($this->owner, $this->workspace, 'alice@example.test');
    $this->travel(8)->days();

    expect(Registration::decide('alice@example.test', $expired->token)->refusal)->toBe(RegistrationRefusal::InvitationExpired);

    $revoked = issue($this->owner, $this->workspace, 'bob@example.test');
    RevokeInvitation::run($this->owner, $revoked->invitation);

    expect(Registration::decide('bob@example.test', $revoked->token)->refusal)->toBe(RegistrationRefusal::InvitationRevoked);

    $accepted = issue($this->owner, $this->workspace, 'carol@example.test');
    AcceptInvitation::run(host_user('carol'), $accepted->token);

    expect(Registration::decide('carol@example.test', $accepted->token)->refusal)->toBe(RegistrationRefusal::InvitationInvalid);
});

it('honours a tenant invitation in invitation mode', function () {
    $issued = InviteTenant::run(host_user('platform'), 'Founder@Startup.test');

    expect(Registration::decide('founder@startup.test', $issued->token)->allowed)->toBeTrue()
        ->and(Registration::decide('other@startup.test', $issued->token)->refusal)->toBe(RegistrationRefusal::InvitationForAnotherEmail);
});

it('compares domains case-insensitively on the part after the last @, without subdomains', function () {
    registration_mode('domains', ' @Acme.com, acme.io ,, ');

    expect(Registration::domains())->toBe(['acme.com', 'acme.io'])
        ->and(Registration::decide('Jane@ACME.COM')->allowed)->toBeTrue()
        ->and(Registration::decide('jane@acme.io')->allowed)->toBeTrue()
        ->and(Registration::decide('jane@mail.acme.com')->refusal)->toBe(RegistrationRefusal::DomainNotAllowed)
        ->and(Registration::decide('jane@acme.com.evil.test')->refusal)->toBe(RegistrationRefusal::DomainNotAllowed)
        ->and(Registration::decide('jane@acme.comm')->refusal)->toBe(RegistrationRefusal::DomainNotAllowed)
        ->and(Registration::decide('acme.com')->refusal)->toBe(RegistrationRefusal::DomainNotAllowed)
        ->and(Registration::decide('jane@acme.com@other.test')->refusal)->toBe(RegistrationRefusal::DomainNotAllowed);
});

it('accepts the domain list as an array too', function () {
    registration_mode('domains', ['Acme.com']);

    expect(Registration::decide('jane@acme.com')->allowed)->toBeTrue();
});

it('lets an invitee from another domain in through a valid invitation in domains mode', function () {
    registration_mode('domains', 'acme.com');
    $issued = issue($this->owner, $this->workspace, 'contractor@other.test');

    expect(Registration::decide('contractor@other.test', $issued->token)->allowed)->toBeTrue()
        ->and(Registration::decide('someone@other.test', $issued->token)->refusal)->toBe(RegistrationRefusal::InvitationForAnotherEmail)
        ->and(Registration::decide('someone@other.test', 'forged')->refusal)->toBe(RegistrationRefusal::InvitationInvalid);
});

it('closes registration in domains mode when no domains are configured, and says what to set', function (string|array|null $domains) {
    Log::spy();
    registration_mode('domains', $domains);

    $decision = Registration::decide('jane@acme.com');

    expect($decision->refusal)->toBe(RegistrationRefusal::Closed);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'CONCIERGE_REGISTRATION_DOMAINS'))->once();
})->with([[null], [''], [' , ,'], [[]], [['', ' @ ']]]);

it('refuses every invitation when the membership feature is off', function () {
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');
    config()->set('concierge.features.membership', false);

    expect(Registration::decide('alice@example.test', $issued->token)->refusal)->toBe(RegistrationRefusal::InvitationInvalid);
});

it('validates the email through the MayRegister rule, reading the token from the data', function () {
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    expect(registration_errors(['email' => 'alice@example.test']))->toBe(['Registration is by invitation. Use the link in your invitation.'])
        ->and(registration_errors(['email' => 'alice@example.test', 'invitation' => 'forged']))->toBe(['That invitation link is not valid. Use the link in your invitation, or ask to be invited again.'])
        ->and(registration_errors(['email' => 'alice@example.test', 'invitation' => $issued->token]))->toBe([])
        ->and(registration_errors(['email' => 'mallory@example.test', 'invitation' => $issued->token]))->toBe(['Register with the email address your invitation was sent to.'])
        ->and(registration_errors(['email' => ['alice@example.test']]))->toBe(['Registration is closed.']);
});

it('reads the token from the current request when the data has none, or from the rule itself', function () {
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    expect(registration_errors(['email' => 'alice@example.test'], new MayRegister($issued->token)))->toBe([]);

    request()->merge(['invitation' => $issued->token]);

    expect(registration_errors(['email' => 'alice@example.test']))->toBe([])
        ->and(registration_errors(['email' => 'alice@example.test', 'invitation' => 'forged']))->not->toBe([]);
});

it('still lets the invitee accept after registering through the gate', function () {
    $issued = issue($this->owner, $this->workspace, 'alice@example.test');

    expect(Registration::decide('alice@example.test', $issued->token)->allowed)->toBeTrue();

    $acceptance = AcceptInvitation::run(host_user('alice'), $issued->token);

    expect($acceptance->outcome)->toBe(AcceptanceOutcome::Joined)
        ->and(Registration::decide('alice@example.test', $issued->token)->refusal)->toBe(RegistrationRefusal::InvitationInvalid);
});
