<?php

use Illuminate\Database\Eloquent\Model;
use Tey\Concierge\Actions\LeaveWorkspace;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;

it('is a workspace action: app-wide mode, with no workspaces and Spatie teams off, refuses it', function () {
    $user = host_user('member');
    $workspace = new class extends Model implements Workspace
    {
        public function conciergeName(): string
        {
            return 'None';
        }

        public function conciergeAcceptsMembers(): bool
        {
            return true;
        }
    };

    expect(config('permission.teams'))->toBeFalse()
        ->and(fn () => LeaveWorkspace::run($user, $workspace))->toThrow(UnsupportedConfiguration::class, 'needs workspace mode');
});
