<?php

namespace Workbench\App\Kit;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The starter kit's App\Models\Membership: the team_members pivot, role cast to the enum.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property TeamRole $role
 */
class Membership extends Pivot
{
    protected $table = 'team_members';

    public $incrementing = true;

    protected $guarded = [];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['role' => TeamRole::class];
    }
}
