<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Partner inquiries in all 15 website languages (David 2026-11-16: everything on the website in every
 * language; pet-prep-website #444 made the partners form 15-lingual). The CHECK used to allow only sl / en.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE partner_inquiries DROP CONSTRAINT IF EXISTS partner_inquiries_locale_check');
        DB::statement("ALTER TABLE partner_inquiries ADD CONSTRAINT partner_inquiries_locale_check CHECK (locale IS NULL OR locale IN ('en', 'sl', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'pl', 'cs', 'hr', 'sv', 'da', 'fi', 'nb'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE partner_inquiries DROP CONSTRAINT IF EXISTS partner_inquiries_locale_check');
        DB::statement("ALTER TABLE partner_inquiries ADD CONSTRAINT partner_inquiries_locale_check CHECK (locale IS NULL OR locale IN ('sl', 'en'))");
    }
};
