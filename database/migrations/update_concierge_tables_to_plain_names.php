<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * From 0.12: Concierge's tables drop the concierge_ prefix. Each table present
 * under its old name is renamed, rows, keys and foreign keys intact. It refuses
 * before renaming anything when a new name is already taken, so an app's own
 * table is never overwritten. A fresh install creates the old names first, then
 * renames them here. Owner invitations rename in their own migration, since
 * that table exists only with tenant invitations on.
 */
return new class extends Migration
{
    private const RENAMES = [
        'concierge_memberships' => 'memberships',
        'concierge_membership_scopes' => 'membership_scopes',
        'concierge_ownerships' => 'ownerships',
        'concierge_invitations' => 'invitations',
        'concierge_accounts' => 'account_statuses',
    ];

    public function up(): void
    {
        $renames = array_filter(self::RENAMES, fn (string $to, string $from): bool => Schema::hasTable($from), ARRAY_FILTER_USE_BOTH);
        foreach ($renames as $from => $to) {
            if (Schema::hasTable($to)) {
                throw new RuntimeException('The table '.$to.' already exists, so '.$from.' cannot be renamed to it. Rename or drop the app\'s '.$to.' table, then migrate again.');
            }
        }
        foreach ($renames as $from => $to) {
            Schema::rename($from, $to);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::RENAMES, true) as $from => $to) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
