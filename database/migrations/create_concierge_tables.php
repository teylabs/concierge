<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Support\ConciergeModels;

return new class extends Migration
{
    public function up(): void
    {
        $users = ConciergeModels::userTable();
        $workspaces = ConciergeModels::workspaceTable();

        Schema::create('concierge_memberships', function (Blueprint $table) use ($users, $workspaces) {
            $table->id();
            $table->foreignId('workspace_id')->constrained($workspaces)->restrictOnDelete();
            $table->foreignId('user_id')->constrained($users)->restrictOnDelete();
            $table->string('status', 16);
            $table->unsignedBigInteger('invitation_id')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->string('suspension_reason', 500)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
            $table->unique(['workspace_id', 'id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('concierge_ownerships', function (Blueprint $table) use ($workspaces) {
            $table->foreignId('workspace_id')->primary()->constrained($workspaces)->restrictOnDelete();
            $table->unsignedBigInteger('membership_id')->unique();
            $table->foreignId('previous_membership_id')->nullable()->constrained('concierge_memberships')->nullOnDelete();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'membership_id'])
                ->references(['workspace_id', 'id'])
                ->on('concierge_memberships')
                ->restrictOnDelete();
        });

        Schema::create('concierge_invitations', function (Blueprint $table) use ($users, $workspaces) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained($workspaces)->restrictOnDelete();
            $table->string('email', 254);
            $table->string('pending_key', 254)->nullable();
            $table->json('grants');
            $table->char('grants_fingerprint', 64);
            $table->foreignId('inviter_id')->nullable()->constrained($users)->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('generation')->default(1);
            $table->timestamp('rotated_at')->nullable();
            $table->string('status', 16);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->foreignId('accepted_membership_id')->nullable()->constrained('concierge_memberships')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'pending_key']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'email']);
        });

        Schema::table('concierge_memberships', function (Blueprint $table) {
            $table->foreign('invitation_id')->references('id')->on('concierge_invitations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('concierge_memberships', function (Blueprint $table) {
            $table->dropForeign(['invitation_id']);
        });

        Schema::dropIfExists('concierge_invitations');
        Schema::dropIfExists('concierge_ownerships');
        Schema::dropIfExists('concierge_memberships');
    }
};
