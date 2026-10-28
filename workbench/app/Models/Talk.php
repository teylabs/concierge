<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A record inside a workspace that a scoped invitation can reach.
 *
 * @property int $id
 * @property int|null $workspace_id
 * @property string $title
 */
class Talk extends Model
{
    protected $guarded = [];
}
