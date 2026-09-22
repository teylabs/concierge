<?php

namespace Tey\Concierge\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ConciergeModels;

trait HasMembers
{
    public function memberships(): HasMany
    {
        return $this->hasMany(ConciergeModels::membership(), 'workspace_id');
    }

    public function ownership(): HasOne
    {
        return $this->hasOne(ConciergeModels::ownership(), 'workspace_id');
    }

    public function owner(): ?Membership
    {
        return $this->ownership?->membership;
    }
}
