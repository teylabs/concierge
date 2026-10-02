<?php

namespace Workbench\App\Models\LocalAuth;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Lives on the `secondary` connection, whose schema differs from the default one.
 */
class SecondaryAccount extends Authenticatable
{
    protected $connection = 'secondary';

    protected $table = 'secondary_accounts';

    protected $guarded = [];
}
