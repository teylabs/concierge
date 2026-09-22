<?php

namespace Tey\Concierge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Support\ConciergeModels;

/**
 * @property int $workspace_id
 * @property int $membership_id
 * @property int|null $previous_membership_id
 * @property Carbon|null $transferred_at
 * @property-read Workspace&Model $workspace
 * @property-read Membership $membership
 */
class Ownership extends Model
{
    protected $table = 'concierge_ownerships';

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace&Model, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ConciergeModels::workspace(), 'workspace_id');
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(ConciergeModels::membership(), 'membership_id');
    }
}
