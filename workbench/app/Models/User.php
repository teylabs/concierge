<?php

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Tey\Concierge\Concerns\InteractsWithMemberships;
use Tey\Concierge\Contracts\Member;

class User extends Authenticatable implements Member
{
    use HasRoles, InteractsWithMemberships;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime'];
    }
}
