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

        Schema::create('concierge_tenant_invitations', function (Blueprint $table) use ($users, $workspaces) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('email', 254);
            $table->string('pending_key', 254)->nullable()->unique();
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('generation')->default(1);
            $table->timestamp('rotated_at')->nullable();
            $table->string('status', 16);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->foreignId('inviter_id')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained($workspaces)->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concierge_tenant_invitations');
    }
};
