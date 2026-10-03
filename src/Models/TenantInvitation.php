<?php

namespace Tey\Concierge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Support\ConciergeModels;

/**
 * A platform-level invitation: accepting it provisions the invitee's OWN workspace.
 *
 * @property int $id
 * @property string $public_id
 * @property string $email
 * @property string|null $pending_key
 * @property string $token_hash
 * @property int $generation
 * @property CarbonInterface|null $rotated_at
 * @property InvitationStatus $status
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $last_sent_at
 * @property int $send_count
 * @property int|null $inviter_id
 * @property CarbonInterface|null $accepted_at
 * @property int|null $accepted_by_id
 * @property int|null $workspace_id
 * @property CarbonInterface|null $revoked_at
 * @property int|null $revoked_by_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read (Member&Model)|null $inviter
 * @property-read (Workspace&Model)|null $workspace
 */
class TenantInvitation extends Model
{
    protected $table = 'concierge_tenant_invitations';

    protected $guarded = [];

    protected $hidden = ['token_hash', 'pending_key'];

    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'generation' => 'integer',
            'send_count' => 'integer',
            'rotated_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TenantInvitation $invitation) {
            $invitation->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public static function hashToken(string $token): string
    {
        return Invitation::hashToken($token);
    }

    /** @return BelongsTo<Member&Model, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(ConciergeModels::user(), 'inviter_id');
    }

    /** @return BelongsTo<Workspace&Model, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ConciergeModels::workspace(), 'workspace_id');
    }

    /** Pending and still inside its expiry window. */
    public function scopeOutstanding(Builder $query): void
    {
        $query->where('status', InvitationStatus::Pending)->where('expires_at', '>', now());
    }

    /** Pending but past expiry, or explicitly marked expired. */
    public function scopeLapsed(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('status', InvitationStatus::Expired)
            ->orWhere(fn (Builder $q) => $q->where('status', InvitationStatus::Pending)->where('expires_at', '<=', now())));
    }

    public function scopeAccepted(Builder $query): void
    {
        $query->where('status', InvitationStatus::Accepted);
    }

    public function scopeRevoked(Builder $query): void
    {
        $query->where('status', InvitationStatus::Revoked);
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->status === InvitationStatus::Expired
            || ($this->isPending() && $this->expires_at->isPast());
    }
}
