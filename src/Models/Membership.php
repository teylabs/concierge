<?php

namespace Tey\Concierge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Support\ConciergeModels;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property MembershipStatus $status
 * @property int|null $invitation_id
 * @property CarbonInterface|null $joined_at
 * @property CarbonInterface|null $suspended_at
 * @property int|null $suspended_by_id
 * @property string|null $suspension_reason
 * @property CarbonInterface|null $removed_at
 * @property int|null $removed_by_id
 * @property bool $guest true when the membership only reaches the records it is scoped to
 * @property-read Member&Model $user
 * @property-read Workspace&Model $workspace
 * @property-read Invitation|null $invitation
 */
class Membership extends Model
{
    protected $table = 'concierge_memberships';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'suspended_at' => 'datetime',
            'removed_at' => 'datetime',
            'guest' => 'boolean',
        ];
    }

    /** @return BelongsTo<Workspace&Model, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ConciergeModels::workspace(), 'workspace_id');
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

    /**
     * Every scope record, revoked ones included; Concierge::scopes() lists the active ones.
     *
     * @return HasMany<MembershipScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(ConciergeModels::membershipScope(), 'membership_id');
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}
