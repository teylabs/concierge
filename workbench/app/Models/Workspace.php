<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Tey\Concierge\Concerns\HasMembers;
use Tey\Concierge\Contracts\Workspace as WorkspaceContract;

/**
 * @property int $id
 * @property string $name
 * @property bool $accepts_members
 */
class Workspace extends Model implements WorkspaceContract
{
    use HasMembers;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['accepts_members' => 'boolean'];
    }

    public function conciergeName(): string
    {
        return $this->name;
    }

    public function conciergeAcceptsMembers(): bool
    {
        return $this->accepts_members;
    }
}
