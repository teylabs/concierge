<?php

namespace Workbench\App\Kit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The starter kit's App\Models\TeamInvitation. Concierge never reads or writes it.
 */
class TeamInvitation extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return ['role' => TeamRole::class, 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
