<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tey\Concierge\Concierge;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Events\UserInvited;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Notifications\InvitationNotification;

beforeEach(function () {
    Notification::fake();
    $this->owner = app_owner();
});

/** @return array<string, mixed> */
function invite(array $options = []): array
{
    $options = ['--json' => true, '--no-interaction' => true, ...$options];

    expect(Artisan::call('concierge:invite', $options))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function invite_refused(array $options = []): array
{
    $options = ['--json' => true, '--no-interaction' => true, ...$options];

    expect(Artisan::call('concierge:invite', $options))->toBe(1);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('invites an email through InviteUser and delivers the invitation', function () {
    Event::fake([UserInvited::class]);
    app(Concierge::class)->invitationUrlUsing(fn ($invitation, $token) => "https://host.test/invitations/{$token}");

    $result = invite(['email' => 'ana@example.com', '--role' => ['presenter'], '--as' => $this->owner->email]);

    expect($result)->toMatchArray(['email' => 'ana@example.com', 'outcome' => 'invited', 'roles' => ['presenter']]);

    $invitation = Invitation::query()->sole();
    expect($invitation->email)->toBe('ana@example.com')
        ->and($invitation->status)->toBe(InvitationStatus::Pending)
        ->and($invitation->inviter_id)->toBe($this->owner->getKey())
        ->and($invitation->workspace_id)->toBeNull()
        ->and($invitation->roleNames())->toBe(['presenter'])
        ->and($result['invitationId'])->toBe($invitation->getKey())
        ->and($result['expiresAt'])->toBe($invitation->expires_at->toIso8601String());

    Event::assertDispatched(UserInvited::class, fn (UserInvited $e) => $e->invitationId === $invitation->getKey() && $e->actorId === $this->owner->getKey());
    Notification::assertSentOnDemand(InvitationNotification::class, fn (InvitationNotification $n) => $n->workspaceName === config('app.name')
        && $n->url !== null
        && str_starts_with($n->url, 'https://host.test/invitations/'));
});

it('grants several roles when --role is repeated', function () {
    invite(['email' => 'ana@example.com', '--role' => ['presenter', 'admin'], '--as' => $this->owner->email]);

    expect(Invitation::query()->sole()->roleNames())->toBe(['admin', 'presenter']);
});

it('normalizes the email and resolves --as case-insensitively', function () {
    invite(['email' => ' Ana@Example.com ', '--role' => ['presenter'], '--as' => strtoupper($this->owner->email)]);

    expect(Invitation::query()->sole()->email)->toBe('ana@example.com');
});

it('reports the invitation without --json', function () {
    expect(Artisan::call('concierge:invite', [
        'email' => 'ana@example.com',
        '--role' => ['presenter'],
        '--as' => $this->owner->email,
        '--no-interaction' => true,
    ]))->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('ana@example.com')
        ->and($output)->toContain('invited')
        ->and($output)->toContain('presenter');
});

it('refuses an invalid email with the action\'s own message', function () {
    expect(invite_refused(['email' => 'not-an-email', '--role' => ['presenter'], '--as' => $this->owner->email]))
        ->toBe(['error' => 'Enter a valid email address.', 'code' => 'email'])
        ->and(Invitation::query()->count())->toBe(0);
});

it('refuses an unknown role and names the configured roles', function () {
    expect(invite_refused(['email' => 'ana@example.com', '--role' => ['superadmin'], '--as' => $this->owner->email]))
        ->toBe([
            'error' => '[superadmin] is not a concierge role; use one of the roles in config/concierge.php (owner, admin, presenter).',
            'code' => 'unknown_role',
        ])
        ->and(Invitation::query()->count())->toBe(0);
});

it('refuses a role the actor may not grant', function () {
    $admin = join_app($this->owner, 'admin', ['admin']);
    $before = Invitation::query()->count();

    expect(invite_refused(['email' => 'ana@example.com', '--role' => ['admin'], '--as' => $admin->email]))
        ->toBe(['error' => 'You cannot grant the [admin] role.', 'code' => 'not_grantable'])
        ->and(Invitation::query()->count())->toBe($before);
});

it('refuses an actor who is not a manager', function () {
    $presenter = join_app($this->owner, 'presenter', ['presenter']);
    $before = Invitation::query()->count();

    expect(invite_refused(['email' => 'ana@example.com', '--role' => ['presenter'], '--as' => $presenter->email]))
        ->toBe(['error' => 'You cannot manage users.', 'code' => 'not_manager'])
        ->and(Invitation::query()->count())->toBe($before);
});

it('refuses a deactivated actor', function () {
    $admin = join_app($this->owner, 'admin', ['admin']);
    Account::query()->whereKey($admin->id)->update(['status' => AccountStatus::Deactivated->value]);

    expect(invite_refused(['email' => 'ana@example.com', '--role' => ['presenter'], '--as' => $admin->email]))
        ->toBe(['error' => 'Your account is not active.', 'code' => 'not_member'])
        ->and(Invitation::query()->count())->toBe(1);
});

it('refuses an email that already has an account', function () {
    host_user('ana');

    expect(invite_refused(['email' => 'ana@example.test', '--role' => ['presenter'], '--as' => $this->owner->email]))
        ->toBe(['error' => 'That person already has an account; change their roles instead.', 'code' => 'email'])
        ->and(Invitation::query()->count())->toBe(0);
});

it('refuses a deactivated account', function () {
    $ana = host_user('ana');
    Account::query()->create(['user_id' => $ana->getKey(), 'status' => AccountStatus::Deactivated]);

    expect(invite_refused(['email' => 'ana@example.test', '--role' => ['presenter'], '--as' => $this->owner->email]))
        ->toBe(['error' => 'That account is deactivated; restore it instead.', 'code' => 'email'])
        ->and(Invitation::query()->count())->toBe(0);
});

it('refuses an email with a pending invitation', function () {
    invite_user($this->owner, 'ana@example.com');

    expect(invite_refused(['email' => 'ana@example.com', '--role' => ['presenter'], '--as' => $this->owner->email]))
        ->toBe(['error' => 'An invitation is already pending for that email.', 'code' => 'email'])
        ->and(Invitation::query()->count())->toBe(1);
});

it('refuses a missing --as and names the option', function () {
    expect(invite_refused(['email' => 'ana@example.com', '--role' => ['presenter']]))
        ->toBe(['error' => 'concierge:invite needs --as=<email> naming the existing user to run the action as.', 'code' => 'missing_actor'])
        ->and(Invitation::query()->count())->toBe(0);
});

it('refuses an unknown --as and names the option', function () {
    expect(invite_refused(['email' => 'ana@example.com', '--role' => ['presenter'], '--as' => 'nobody@example.com']))
        ->toBe(['error' => 'No user found for [nobody@example.com]. The --as user must exist.', 'code' => 'unknown_actor'])
        ->and(Invitation::query()->count())->toBe(0);
});

it('refuses a missing --role and names the option', function () {
    expect(invite_refused(['email' => 'ana@example.com', '--as' => $this->owner->email]))
        ->toBe(['error' => 'concierge:invite needs at least one --role=<role>, e.g. --role=presenter.', 'code' => 'missing_roles'])
        ->and(Invitation::query()->count())->toBe(0);
});

it('reports refusals on the error component without --json', function () {
    expect(Artisan::call('concierge:invite', [
        'email' => 'ana@example.com',
        '--role' => ['presenter'],
        '--as' => 'nobody@example.com',
        '--no-interaction' => true,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('No user found for [nobody@example.com]. The --as user must exist.');
});

it('pins the JSON keys', function () {
    $result = invite(['email' => 'ana@example.com', '--role' => ['presenter'], '--as' => $this->owner->email]);

    expect(array_keys($result))->toBe(['email', 'outcome', 'invitationId', 'roles', 'expiresAt']);

    $invitation = Invitation::query()->sole();
    expect($result['email'])->toBe('ana@example.com')
        ->and($result['outcome'])->toBe('invited')
        ->and($result['invitationId'])->toBe($invitation->getKey())
        ->and($result['roles'])->toBe(['presenter'])
        ->and($result['expiresAt'])->toBe($invitation->expires_at->toIso8601String());

    // The token and accept link never appear in the output.
    expect(Artisan::output())->not->toContain('token')
        ->and(Artisan::output())->not->toContain($invitation->public_id);
});

it('previews without writing, mailing or dispatching events', function () {
    $this->freezeSecond();
    Event::fake([UserInvited::class]);

    $result = invite([
        'email' => 'ana@example.com',
        '--role' => ['presenter'],
        '--as' => $this->owner->email,
        '--dry-run' => true,
    ]);

    expect(array_keys($result))->toBe(['email', 'outcome', 'invitationId', 'roles', 'expiresAt'])
        ->and($result['email'])->toBe('ana@example.com')
        ->and($result['outcome'])->toBe('would_invite')
        ->and($result['invitationId'])->toBeNull()
        ->and($result['roles'])->toBe(['presenter'])
        ->and($result['expiresAt'])->toBe(now()->addDays(7)->toIso8601String())
        ->and(Invitation::query()->count())->toBe(0);
    Event::assertDispatchedTimes(UserInvited::class, 0);
    Notification::assertNothingSent();
});

it('previews with the same JSON shape without --json', function () {
    expect(Artisan::call('concierge:invite', [
        'email' => 'ana@example.com',
        '--role' => ['presenter'],
        '--as' => $this->owner->email,
        '--dry-run' => true,
        '--no-interaction' => true,
    ]))->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('ana@example.com')
        ->and($output)->toContain('would invite')
        ->and(Invitation::query()->count())->toBe(0);
});

it('previews the authorization refusal without writing', function () {
    $admin = join_app($this->owner, 'admin', ['admin']);
    $before = Invitation::query()->count();

    expect(invite_refused([
        'email' => 'ana@example.com',
        '--role' => ['admin'],
        '--as' => $admin->email,
        '--dry-run' => true,
    ]))->toBe(['error' => 'You cannot grant the [admin] role.', 'code' => 'not_grantable'])
        ->and(Invitation::query()->count())->toBe($before);
});

it('previews the refusal for an email that already has an account', function () {
    host_user('ana');

    expect(invite_refused([
        'email' => 'ana@example.test',
        '--role' => ['presenter'],
        '--as' => $this->owner->email,
        '--dry-run' => true,
    ]))->toBe(['error' => 'That person already has an account; change their roles instead.', 'code' => 'email'])
        ->and(Invitation::query()->count())->toBe(0);
});

it('previews the refusal for a deactivated account', function () {
    $ana = host_user('ana');
    Account::query()->create(['user_id' => $ana->getKey(), 'status' => AccountStatus::Deactivated]);

    expect(invite_refused([
        'email' => 'ana@example.test',
        '--role' => ['presenter'],
        '--as' => $this->owner->email,
        '--dry-run' => true,
    ]))->toBe(['error' => 'That account is deactivated; restore it instead.', 'code' => 'email'])
        ->and(Invitation::query()->count())->toBe(0);
});

it('previews the refusal for a pending invitation', function () {
    invite_user($this->owner, 'ana@example.com');

    expect(invite_refused([
        'email' => 'ana@example.com',
        '--role' => ['presenter'],
        '--as' => $this->owner->email,
        '--dry-run' => true,
    ]))->toBe(['error' => 'An invitation is already pending for that email.', 'code' => 'email'])
        ->and(Invitation::query()->count())->toBe(1);
});
