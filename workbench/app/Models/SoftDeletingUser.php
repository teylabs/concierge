<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A host user model with SoftDeletes, for the deletion tests.
 */
class SoftDeletingUser extends User
{
    use SoftDeletes;

    protected $table = 'users';
}
