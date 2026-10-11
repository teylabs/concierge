<?php

namespace Workbench\App\Kit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Tey\Concierge\Concerns\ActsAsWorkspace;
use Tey\Concierge\Contracts\Workspace;

/**
 * The starter kit's App\Models\Team (laravel new --teams), plus the two lines
 * concierge's install adds: the Workspace contract and ActsAsWorkspace.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 */
class Team extends Model implements Workspace
{
    use ActsAsWorkspace, SoftDeletes;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Team $team) {
            $team->slug = $team->slug ?: Str::slug($team->name);
        });
    }

    public function owner(): ?Model
    {
        return $this->members()->wherePivot('role', TeamRole::Owner->value)->first();
    }

    /** @return BelongsToMany<User, $this, Membership, 'pivot'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<TeamInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    protected function casts(): array
    {
        return ['is_personal' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
