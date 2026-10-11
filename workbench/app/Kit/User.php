<?php

namespace Workbench\App\Kit;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Tey\Concierge\Concerns\InteractsWithMemberships;
use Tey\Concierge\Contracts\Member;

/**
 * The starter kit's App\Models\User with HasTeams, plus what concierge's install adds:
 * the Member contract, HasRoles and InteractsWithMemberships.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property int|null $current_team_id
 */
class User extends Authenticatable implements Member
{
    // Spatie's HasRoles has a teams() of its own (empty with Spatie teams off); the kit's wins.
    use HasRoles, HasTeams, InteractsWithMemberships, Notifiable {
        HasTeams::teams insteadof HasRoles;
    }

    protected $table = 'users';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime'];
    }
}
