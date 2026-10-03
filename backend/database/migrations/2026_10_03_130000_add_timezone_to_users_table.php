<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1-03 family timezone.
 *
 * `users.timezone` is an IANA name (validated in the app with the `timezone`
 * rule). The family timezone is the parent's; a child uses its parent's
 * (User::familyTimezone()). All wall-clock rules — quiet hours, local
 * midnight, dashboard days — are evaluated in it; timestamps stay UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Europe/Ljubljana')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
