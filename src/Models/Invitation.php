<?php

namespace Tey\Concierge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use SensitiveParameter;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Support\ConciergeModels;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $email
 * @property string|null $pending_key
 * @property array{roles: array<string, array{id: int, permissions: list<string>}>} $grants
 * @property string $grants_fingerprint
 * @property int|null $inviter_id
 * @property string $token_hash
 * @property int $generation
 * @property CarbonInterface|null $rotated_at
 * @property InvitationStatus $status
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $last_sent_at
 * @property int $send_count
 * @property CarbonInterface|null $accepted_at
 * @property int|null $accepted_by_id
 * @property int|null $accepted_membership_id
 * @property CarbonInterface|null $revoked_at
 * @property int|null $revoked_by_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read (Member&Model)|null $inviter
 * @property-read Workspace&Model $workspace
 */
class Invitation extends Model
{
    protected $table = 'concierge_invitations';

    protected $guarded = [];

    protected $hidden = ['token_hash', 'pending_key'];

    protected function casts(): array
    {
        return [
            'grants' => 'array',
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
        static::creating(function (Invitation $invitation) {
            $invitation->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public static function hashToken(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(#[SensitiveParameter] string $token): ?static
    {
        return static::query()->where('token_hash', static::hashToken($token))->first();
    }

    /** @return BelongsTo<Workspace&Model, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ConciergeModels::workspace(), 'workspace_id');
    }

    /** @return BelongsTo<Member&Model, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(ConciergeModels::user(), 'inviter_id');
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

    /** @return list<string> */
    public function roleNames(): array
    {
        return array_keys($this->grants['roles'] ?? []);
    }
}
