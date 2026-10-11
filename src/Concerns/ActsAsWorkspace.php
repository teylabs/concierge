<?php

namespace Tey\Concierge\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Defaults for the Workspace contract on an existing team or organisation
 * model: the name comes from a `name` column, and the workspace accepts members
 * unless it is soft-deleted. Override either method to change that.
 *
 * Under concierge's own engine add HasMembers as well; a starter kit's Team
 * already has its own memberships() and owner(), so it takes this trait alone.
 */
trait ActsAsWorkspace
{
    public function conciergeName(): string
    {
        return (string) $this->getAttribute('name');
    }

    public function conciergeAcceptsMembers(): bool
    {
        return ! (in_array(SoftDeletes::class, class_uses_recursive($this), true) && $this->trashed());
    }
}
