<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1-18 — push notifications in the device's language.
 *
 * device_push_tokens.locale: the language of the app install ('en' | 'sl',
 * config/locales.php), written by POST /api/devices from the request's
 * `Accept-Language` when it names a supported language; otherwise the stored
 * value is kept. Null = the install never said → pushes use the default
 * (English).
 *
 * Backfill: every install registered before this migration ran a
 * Slovenian-only app build, so existing rows become 'sl' (their pushes stay
 * exactly as before). The next registration from a new build overwrites it
 * with the app's language. Nullable, no default → old code keeps working.
 * No CHECK constraint: adding a language must stay a config + lang change;
 * the value is validated in PHP (App\Support\RequestLocale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->string('locale', 8)->nullable();
        });

        DB::table('device_push_tokens')->whereNull('locale')->update(['locale' => 'sl']);
    }

    public function down(): void
    {
        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
