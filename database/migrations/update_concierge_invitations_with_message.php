<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From 0.8: an optional note from the inviter, shown with the invitation and
 * passed to delivery. Both modes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concierge_invitations', function (Blueprint $table) {
            $table->string('message', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('concierge_invitations', function (Blueprint $table) {
            $table->dropColumn('message');
        });
    }
};
