<?php

namespace Tey\Concierge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Scope;

/**
 * One record a membership may reach, left behind by an accepted scoped
 * invitation. Active until revoked_at is set; revoked rows are kept.
 *
 * @property int $id
 * @property int $membership_id
 * @property string $scope_type
 * @property string $scope_id
 * @property bool|null $active true while active, null once revoked
 * @property int|null $invitation_id
 * @property int|null $invited_by_id
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $revoked_at
 * @property int|null $revoked_by_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Membership $membership
 */
class MembershipScope extends Model
{
    protected $table = 'concierge_membership_scopes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(ConciergeModels::membership(), 'membership_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function scope(): Scope
    {
        return new Scope($this->scope_type, $this->scope_id);
    }
}
