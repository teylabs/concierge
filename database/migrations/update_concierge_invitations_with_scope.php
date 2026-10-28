<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Support\ConciergeModels;

/**
 * From 0.9: scoped invitations. An invitation may name one record (a morph
 * type and id) in its workspace; accepting it gives the invitee access to that
 * record alone. Both modes get the invitation columns (always null in app-wide
 * mode). Workspace mode also gets the guest flag on memberships and the scope
 * records an accepted invitation leaves behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        $workspaces = Mode::configured() === Mode::Workspaces;

        Schema::table('concierge_invitations', function (Blueprint $table) use ($workspaces) {
            $table->string('scope_type')->nullable();
            $table->string('scope_id')->nullable();

            if ($workspaces) {
                $table->index(['workspace_id', 'scope_type', 'scope_id']);
            }
        });

        if (! $workspaces) {
            return;
        }

        $users = ConciergeModels::userTable();

        Schema::table('concierge_memberships', function (Blueprint $table) {
            // True for a membership that only reaches the records it is scoped to.
            $table->boolean('guest')->default(false);
        });

        Schema::create('concierge_membership_scopes', function (Blueprint $table) use ($users) {
            $table->id();
            $table->foreignId('membership_id')->constrained('concierge_memberships')->restrictOnDelete();
            $table->string('scope_type');
            $table->string('scope_id');
            // True while active and null once revoked, so the unique index below
            // allows one active row per record and any number of revoked ones.
            $table->boolean('active')->nullable();
            $table->foreignId('invitation_id')->nullable()->constrained('concierge_invitations')->nullOnDelete();
            $table->foreignId('invited_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamps();

            $table->unique(['membership_id', 'scope_type', 'scope_id', 'active']);
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        $workspaces = Schema::hasTable('concierge_memberships');

        Schema::dropIfExists('concierge_membership_scopes');

        if ($workspaces) {
            Schema::table('concierge_memberships', function (Blueprint $table) {
                $table->dropColumn('guest');
            });
        }

        Schema::table('concierge_invitations', function (Blueprint $table) use ($workspaces) {
            if ($workspaces) {
                $table->dropIndex(['workspace_id', 'scope_type', 'scope_id']);
            }

            $table->dropColumn(['scope_type', 'scope_id']);
        });
    }
};
