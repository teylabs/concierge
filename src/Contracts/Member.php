<?php

namespace Tey\Concierge\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Implemented by the host's Eloquent user model, which must also use
 * Spatie\Permission\Traits\HasRoles (the methods below).
 */
interface Member
{
    public function getKey();

    public function roles(): BelongsToMany;

    public function syncRoles(...$roles);

    public function syncPermissions(...$permissions);

    public function conciergeEmail(): string;

    public function conciergeHasVerifiedEmail(): bool;

    public function conciergeDisplayName(): string;
}
