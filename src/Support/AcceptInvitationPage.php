<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Data\UI\AcceptInvitationLinks;
use Tey\Concierge\Data\UI\AcceptInvitationMetadata;
use Tey\Concierge\Data\UI\AcceptInvitationPageProps;
use Tey\Concierge\Data\UI\WorkspaceSummary;
use Tey\Concierge\Enums\InvitationState;

/**
 * The invitation page, in either mode, from InspectInvitation's result. Each
 * state gets only the links it uses: Accept when ready, sign-in and register
 * for a visitor, Switch account for the wrong account, and Continue once
 * nothing more can be done here. A link the app has no route for is null.
 */
final class AcceptInvitationPage
{
    /**
     * @param  array{accept?: ?string, login?: ?string, register?: ?string, logout?: ?string, switchAccount?: ?string, verify?: ?string, home?: ?string}  $urls
     */
    public function props(?Member $user, InvitationInspection $inspection, array $urls): AcceptInvitationPageProps
    {
        $state = $inspection->state;
        $invitation = $state === InvitationState::Invalid ? null : $inspection->invitation;
        $workspace = $invitation?->isAppWide() === false ? $invitation->workspace : null;
        $url = fn (string $key) => $urls[$key] ?? null;
        $home = $url('home');
        $signedOut = in_array($state, [InvitationState::SignInRequired, InvitationState::RegisterRequired], true);
        $switch = $state === InvitationState::WrongAccount || ($state === InvitationState::Ready && $inspection->otherEmail);

        $links = new AcceptInvitationLinks(
            accept: $state === InvitationState::Ready ? $url('accept') : null,
            login: $signedOut ? $url('login') : null,
            register: $signedOut ? $url('register') : null,
            logout: $state === InvitationState::Unverified ? $url('logout') : null,
            continue: match (true) {
                $state === InvitationState::Unverified => $url('verify') ?? $home,
                $signedOut, $state === InvitationState::Ready, $state === InvitationState::WrongAccount => null,
                default => $home,
            },
            switchAccount: $switch ? $url('switchAccount') : null,
            cancel: $state === InvitationState::WrongAccount ? $home : null,
        );

        return new AcceptInvitationPageProps(
            state: $state->value,
            workspace: $workspace ? new WorkspaceSummary((string) $workspace->getKey(), $workspace->conciergeName()) : null,
            email: $invitation?->email,
            roles: $invitation ? MembersIndex::roleBadges(Roles::sort($invitation->roleNames())) : [],
            metadata: new AcceptInvitationMetadata($links, (string) config('app.name')),
            signedInAs: $user && $switch ? $user->conciergeEmail() : null,
            otherEmail: $state === InvitationState::Ready && $inspection->otherEmail,
        );
    }
}
