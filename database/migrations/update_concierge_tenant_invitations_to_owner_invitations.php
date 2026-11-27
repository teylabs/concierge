<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * From 0.12: concierge_tenant_invitations becomes owner_invitations (an
 * invitation to found and own a workspace). Registered with tenant invitations
 * on, after the table's own migration, so an app that turns them on later still
 * ends on the new name. Refuses when owner_invitations already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('concierge_tenant_invitations')) {
            return;
        }
        if (Schema::hasTable('owner_invitations')) {
            throw new RuntimeException('The table owner_invitations already exists, so concierge_tenant_invitations cannot be renamed to it. Rename or drop the app\'s owner_invitations table, then migrate again.');
        }
        Schema::rename('concierge_tenant_invitations', 'owner_invitations');
    }

    public function down(): void
    {
        if (Schema::hasTable('owner_invitations') && ! Schema::hasTable('concierge_tenant_invitations')) {
            Schema::rename('owner_invitations', 'concierge_tenant_invitations');
        }
    }
};
