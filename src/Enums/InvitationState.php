<?php

namespace Tey\Concierge\Enums;

enum InvitationState: string
{
    case Ready = 'ready';
    case SignInRequired = 'sign_in_required';
    case RegisterRequired = 'register_required';
    case WrongAccount = 'wrong_account';
    case Unverified = 'unverified';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Invalid = 'invalid';
    case Accepted = 'accepted';
    case AlreadyMember = 'already_member';
    case Suspended = 'suspended';
    case AuthorityLapsed = 'authority_lapsed';
    case WorkspaceUnavailable = 'workspace_unavailable';
    case GrantsChanged = 'grants_changed';
}
