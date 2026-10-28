<?php

namespace Tey\Concierge\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Answers one question: does the user hold an active, unrevoked scope for
 * exactly this record, through an active membership of the record's
 * workspace? It says nothing about workspace roles, so a full member without
 * a scope is not allowed here; combine the two in your policy
 * (`$user->can(...) || $scoped->allows($user, $record)`).
 *
 * The record's workspace is the one passed in, else the record's
 * `workspace_id`, else the current workspace context. With none of them it
 * fails closed.
 */
interface ScopedAccess
{
    public function allows(Member $user, Model $record, ?Workspace $workspace = null): bool;
}
