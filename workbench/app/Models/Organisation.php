<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tey\Concierge\Concerns\ActsAsWorkspace;
use Tey\Concierge\Concerns\HasMembers;
use Tey\Concierge\Contracts\Workspace;

/**
 * A host's own organisation model adopted under concierge's own engine (D6).
 *
 * @property int $id
 * @property string $name
 * @property int|null $owner_id
 */
class Organisation extends Model implements Workspace
{
    use ActsAsWorkspace, HasMembers, SoftDeletes;

    protected $guarded = [];
}
