<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$migration = fn (string $name) => require dirname(__DIR__, 2).'/database/migrations/'.$name.'.php';

it('ends a fresh install on the plain table names', function () {
    foreach (['memberships', 'membership_scopes', 'ownerships', 'invitations', 'account_statuses', 'owner_invitations'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }
    foreach (['concierge_memberships', 'concierge_membership_scopes', 'concierge_ownerships', 'concierge_invitations', 'concierge_accounts', 'concierge_tenant_invitations'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }
});

it('moves an upgraded app\'s rows to the plain names', function () use ($migration) {
    $migration('update_concierge_tables_to_plain_names')->down();
    $migration('update_concierge_tenant_invitations_to_owner_invitations')->down();
    expect(Schema::hasTable('concierge_memberships'))->toBeTrue();
    $user = DB::table('users')->insertGetId(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
    DB::table('concierge_accounts')->insert(['user_id' => $user, 'status' => 'active']);

    $migration('update_concierge_tables_to_plain_names')->up();
    $migration('update_concierge_tenant_invitations_to_owner_invitations')->up();

    expect(DB::table('account_statuses')->where('user_id', $user)->value('status'))->toBe('active')
        ->and(Schema::hasTable('concierge_accounts'))->toBeFalse()
        ->and(Schema::hasTable('owner_invitations'))->toBeTrue();
});

it('refuses, renaming nothing, when a plain name is already the app\'s', function () use ($migration) {
    $migration('update_concierge_tables_to_plain_names')->down();
    Schema::create('invitations', fn ($table) => $table->id());

    expect(fn () => $migration('update_concierge_tables_to_plain_names')->up())->toThrow(RuntimeException::class, 'The table invitations already exists');
    expect(Schema::hasTable('concierge_memberships'))->toBeTrue()
        ->and(Schema::hasTable('memberships'))->toBeFalse();
});

it('refuses to overwrite an app\'s owner_invitations table', function () use ($migration) {
    $migration('update_concierge_tenant_invitations_to_owner_invitations')->down();
    Schema::create('owner_invitations', fn ($table) => $table->id());

    expect(fn () => $migration('update_concierge_tenant_invitations_to_owner_invitations')->up())->toThrow(RuntimeException::class, 'The table owner_invitations already exists');
    expect(Schema::hasTable('concierge_tenant_invitations'))->toBeTrue();
});
