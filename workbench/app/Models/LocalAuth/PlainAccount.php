<?php

namespace Workbench\App\Models\LocalAuth;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A bare authenticatable with no Concierge contracts, traits or roles. Table `plain_accounts`
 * is created by the test that uses it.
 */
class PlainAccount extends Authenticatable
{
    protected $table = 'plain_accounts';

    protected $guarded = [];
}
