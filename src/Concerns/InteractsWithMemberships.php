<?php

namespace Tey\Concierge\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ConciergeModels;

trait InteractsWithMemberships
{
    public function memberships(): HasMany
    {
        return $this->hasMany(ConciergeModels::membership(), 'user_id');
    }

    public function membershipIn(Workspace $workspace): ?Membership
    {
        return $this->memberships()->where('workspace_id', $workspace->getKey())->first();
    }

    public function conciergeEmail(): string
    {
        return (string) $this->email;
    }

    public function conciergeHasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function conciergeDisplayName(): string
    {
        return (string) ($this->name ?? $this->email);
    }
}
