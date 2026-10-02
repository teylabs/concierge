<?php

namespace Workbench\App\Models\LocalAuth;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class SoftAccount extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'soft_accounts';

    protected $guarded = [];
}
