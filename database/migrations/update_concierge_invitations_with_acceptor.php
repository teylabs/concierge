<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From 0.9: whether the accepting user's email differed from the invited one
 * (invitations.match_email false). accepted_by_id names that user. Both modes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concierge_invitations', function (Blueprint $table) {
            $table->boolean('accepted_with_other_email')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('concierge_invitations', function (Blueprint $table) {
            $table->dropColumn('accepted_with_other_email');
        });
    }
};
