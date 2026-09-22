<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Actions\TransferOwnership;
use Tey\Concierge\Concierge;
use Tey\Concierge\Exceptions\WorkspaceAlreadyEstablished;
use Tey\Concierge\Support\SessionRecentAuthentication;

function transfer_denied(string $code): Closure
{
    return fn (AuthorizationException $e) => expect($e->getCode())->toBe($code);
}

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->workspace = host_workspace('Acme', $this->owner);
    $this->admin = host_user('admin');
    $this->adminMembership = join_as($this->workspace, $this->owner, $this->admin, ['admin']);
    confirm_recent_authentication();
});

it('establishes idempotently and refuses a second owner', function () {
    $again = EstablishWorkspace::run($this->workspace, $this->owner);

    expect($again->is($this->owner->membershipIn($this->workspace)))->toBeTrue()
        ->and(fn () => EstablishWorkspace::run($this->workspace, $this->admin))->toThrow(WorkspaceAlreadyEstablished::class);
});

it('transfers ownership and demotes the previous owner', function () {
    $previous = $this->owner->membershipIn($this->workspace);

    TransferOwnership::run($this->owner, $this->adminMembership);

    $ownership = $this->workspace->ownership()->first();

    expect(app(Concierge::class)->ownerOf($this->workspace)->is($this->adminMembership))->toBeTrue()
        ->and($ownership->previous_membership_id)->toBe($previous->id)
        ->and(roles_in($this->adminMembership))->toBe(['owner'])
        ->and(roles_in($previous))->toBe(['admin'])
        ->and(fn () => SuspendMembership::run($this->owner, $this->adminMembership))->toThrow(transfer_denied('owner_protected'));
});

it('requires recent authentication', function () {
    confirm_recent_authentication(false);

    expect(fn () => TransferOwnership::run($this->owner, $this->adminMembership))->toThrow(transfer_denied('recent_auth_required'));
});

it('only lets the current owner transfer', function () {
    $presenter = join_as($this->workspace, $this->owner, host_user('presenter'));

    expect(fn () => TransferOwnership::run($this->admin, $presenter))->toThrow(transfer_denied('not_owner'));
});

it('requires an active verified recipient in the same workspace', function () {
    $unverified = host_user('unverified', verified: false);
    $unverifiedMembership = join_as($this->workspace, $this->owner, tap(host_user('temp'), fn () => null));
    DB::table('concierge_memberships')->where('id', $unverifiedMembership->id)->update(['user_id' => $unverified->id]);

    $other = host_workspace('Other', $otherOwner = host_user('other-owner'));
    $foreign = join_as($other, $otherOwner, host_user('foreigner'));

    SuspendMembership::run($this->owner, $this->adminMembership);

    expect(fn () => TransferOwnership::run($this->owner, $this->adminMembership))->toThrow(transfer_denied('recipient_invalid'))
        ->and(fn () => TransferOwnership::run($this->owner, $unverifiedMembership->fresh()))->toThrow(transfer_denied('recipient_invalid'))
        ->and(fn () => TransferOwnership::run($this->owner, $this->owner->membershipIn($this->workspace)))->toThrow(transfer_denied('recipient_invalid'))
        ->and(fn () => TransferOwnership::run($this->owner, $foreign))->toThrow(transfer_denied('not_owner'));
});

it('lets the schema hold at most one owner per workspace', function () {
    expect(fn () => DB::table('concierge_ownerships')->insert([
        'workspace_id' => $this->workspace->id,
        'membership_id' => $this->adminMembership->id,
    ]))->toThrow(QueryException::class);
});

it('rejects an owner row pointing at another workspace membership', function () {
    $other = host_workspace('Other');

    expect(fn () => DB::table('concierge_ownerships')->insert([
        'workspace_id' => $other->id,
        'membership_id' => $this->adminMembership->id,
    ]))->toThrow(QueryException::class);
});

it('reads recent authentication from the confirmed session of the same user', function () {
    $check = new SessionRecentAuthentication(app('auth'));
    $this->actingAs($this->owner);

    expect($check->confirmed($this->owner))->toBeFalse();

    session()->put('auth.password_confirmed_at', time());

    expect($check->confirmed($this->owner))->toBeTrue()
        ->and($check->confirmed($this->admin))->toBeFalse();

    session()->put('auth.password_confirmed_at', time() - config('auth.password_timeout', 10800) - 1);

    expect($check->confirmed($this->owner))->toBeFalse();
});
