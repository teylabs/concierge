<?php

namespace Tey\Concierge\LocalAuth\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Tey\Concierge\Actions\EstablishOwner;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Contracts\MembershipSource;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\LocalAuth\LocalLogin;
use Tey\Concierge\Support\AppScope;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Locks;
use Tey\Concierge\Support\Roles;

final class EstablishFirstLocalOwner
{
    public function __construct(private LocalLogin $localLogin) {}

    public function __invoke(Authenticatable $user): void
    {
        if (! $this->localLogin->isAvailable()
            || config('concierge.local_login.first_owner', true) !== true
            || ! ConciergeServiceProvider::membershipEnabled()) {
            return;
        }

        $model = ConciergeModels::user();

        // A separate local-login guard may use an account outside concierge's roster.
        if (! $user instanceof $model) {
            return;
        }

        if (Mode::configured() === Mode::App) {
            DB::transaction(function () use ($user) {
                // With no owners, locking each candidate's account would not serialize
                // different accounts. The configured owner role is their shared root.
                $role = Roles::resolve([Roles::owner()])[0];
                $role->newQuery()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

                if (app(AppScope::class)->owners() === []) {
                    EstablishOwner::run($user);
                }
            });

            return;
        }

        $workspaceModel = ConciergeModels::workspace();
        $workspace = $workspaceModel::query()->oldest()->orderBy((new $workspaceModel)->getKeyName())->first();

        if (! $workspace || ! app(MembershipSource::class)->managesMembers($workspace)) {
            return;
        }

        DB::transaction(function () use ($workspace, $user) {
            $workspace = Locks::workspace($workspace->getKey());

            if (! Locks::ownership($workspace->getKey())) {
                EstablishWorkspace::run($workspace, $user);
            }
        });
    }
}
