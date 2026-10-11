<?php

namespace Tey\Concierge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Support\ConciergeModels;

/**
 * Concierge's per-user state, in both modes. The users table is the roster;
 * this row is the overlay. A user without one is an active account with no
 * provenance, which is what Concierge::account() returns unsaved.
 *
 * @property int $user_id
 * @property AccountStatus $status
 * @property int|null $invitation_id
 * @property CarbonInterface|null $joined_at
 * @property CarbonInterface|null $deactivated_at
 * @property int|null $deactivated_by_id
 * @property string|null $deactivation_reason
 * @property-read Member&Model $user
 * @property-read Invitation|null $invitation
 */
class Account extends Model
{
    protected $table = 'concierge_accounts';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'joined_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Member&Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ConciergeModels::user(), 'user_id');
    }

    /** @return BelongsTo<Invitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(ConciergeModels::invitation(), 'invitation_id');
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }
}
