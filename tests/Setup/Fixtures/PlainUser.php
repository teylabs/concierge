<?php

namespace Tey\Concierge\Tests\Setup\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** A starter kit's user model before concierge's contract is added. */
class PlainUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
