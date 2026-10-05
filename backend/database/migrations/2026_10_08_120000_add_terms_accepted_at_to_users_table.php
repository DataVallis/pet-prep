<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2-10a: parent self-registration. `terms_accepted_at` records when the
 * parent accepted the terms of use + privacy policy (required at sign-up).
 * Null for accounts created before M2-10a (seeded / admin-created parents)
 * and for child profiles (children never accept terms themselves).
 *
 * `terms_version` (PR #25) = which version of the legal texts was accepted
 * (config/legal.php; texts still pending). Stored only, returned nowhere.
 *
 * Additive, no existing row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('email_verified_at');
            $table->string('terms_version', 32)->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });
    }
};
