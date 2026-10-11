<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Support\ConciergeModels;

/**
 * Per-account state concierge keeps in both modes: provenance (which invitation,
 * when) and, from 0.8, deactivation. The users table is the roster; a user
 * without a row is an active account with no provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        $users = ConciergeModels::userTable();

        Schema::create('concierge_accounts', function (Blueprint $table) use ($users) {
            $table->foreignId('user_id')->primary()->constrained($users)->restrictOnDelete();
            $table->string('status', 16);
            $table->foreignId('invitation_id')->nullable()->constrained('concierge_invitations')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->string('deactivation_reason', 500)->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concierge_accounts');
    }
};
