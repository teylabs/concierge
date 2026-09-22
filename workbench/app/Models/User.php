<?php

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;
use Tey\Concierge\Concerns\InteractsWithMemberships;
use Tey\Concierge\Contracts\Member;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 */
class User extends Authenticatable implements Member
{
    use HasRoles, InteractsWithMemberships;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime'];
    }
}
