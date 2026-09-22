<?php

use Spatie\Permission\Models\Role;

it('registers the role sync command', function () {
    $this->artisan('concierge:sync-roles')->assertSuccessful();

    expect(Role::query()->whereNull('workspace_id')->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'owner', 'presenter']);
});
