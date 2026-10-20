<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\MembersIndex;

it('is a workspace page: app-wide mode, with Spatie teams off, lists users instead and refuses it', function () {
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
        ->and(fn () => app(MembersIndex::class)->props($user, $workspace, Request::create('/members')))->toThrow(UnsupportedConfiguration::class, 'needs workspace mode');
});
