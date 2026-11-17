<?php

use App\Support\WebsiteLocale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6-S09 (David 2026-11-17: »Vse maile iz povsod zbiramo v naši bazi v adminu, ki pa naj bodo
 * segmentirani …«): ONE store for every e-mail left on petprep.si.
 *
 *  - email_contacts: one row per e-mail (lower-case, unique) — first / last seen, number of
 *    signups, the latest consent, `unsubscribed_at` and `provider_synced_at` (the marker of the
 *    PLANNED Klaviyo job — nothing writes it yet).
 *  - email_signups: one row per submission (purpose, source, page, section, locale, species,
 *    breed, stage, quiz, UTM, referrer host, consent version, time).
 *
 * No IP, no user agent, no name is stored. Nothing is deleted automatically — a contact (and its
 * signups, ON DELETE CASCADE) goes only when the person asks (admin delete).
 *
 * `summary_leads` (M6-S05 / M6-S06) is COPIED into the new tables and then dropped, so no second
 * copy of the addresses survives a delete request. A lead becomes one contact (`signups_count` =
 * its `visits`) with ONE signup row (purpose `newsletter`, source as stored, `page_path` NULL —
 * the old table never kept it, time = its first visit): earlier visits were never stored as rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('signups_count')->default(1);
            $table->string('consent_version', 16);
            $table->timestamp('consented_at');
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('provider_synced_at')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
        });

        Schema::create('email_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_contact_id')->constrained('email_contacts')->cascadeOnDelete();
            $table->string('purpose', 16);
            $table->string('source', 32);
            $table->string('page_path', 300)->nullable();
            $table->string('section', 40)->nullable();
            $table->string('locale', 2)->nullable();
            $table->string('species', 8)->nullable();
            $table->string('breed', 64)->nullable();
            $table->string('stage', 8)->nullable();
            $table->jsonb('quiz_result')->nullable();
            $table->string('quiz_answers', 20)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('utm_term', 100)->nullable();
            $table->string('referrer_host', 190)->nullable();
            $table->string('consent_version', 16);
            $table->timestamp('created_at');

            $table->index('email_contact_id');
            $table->index('source');
            $table->index('breed');
            $table->index('created_at');
        });

        DB::statement('ALTER TABLE email_contacts ADD CONSTRAINT email_contacts_email_lower_check CHECK (email = lower(email))');
        DB::statement("ALTER TABLE email_signups ADD CONSTRAINT email_signups_purpose_check CHECK (purpose IN ('newsletter', 'early_access'))");
        DB::statement("ALTER TABLE email_signups ADD CONSTRAINT email_signups_source_check CHECK (source ~ '^[a-z0-9_]{1,32}\$')");
        DB::statement("ALTER TABLE email_signups ADD CONSTRAINT email_signups_species_check CHECK (species IS NULL OR species IN ('dog', 'cat'))");
        DB::statement("ALTER TABLE email_signups ADD CONSTRAINT email_signups_stage_check CHECK (stage IS NULL OR stage IN ('adult', 'puppy'))");
        DB::statement("ALTER TABLE email_signups ADD CONSTRAINT email_signups_locale_check CHECK (locale IS NULL OR locale ~ '^[a-z]{2}\$')");
        DB::statement("ALTER TABLE email_signups ADD CONSTRAINT email_signups_quiz_result_check CHECK (quiz_result IS NULL OR (jsonb_typeof(quiz_result) = 'array' AND jsonb_array_length(quiz_result) <= 3))");

        if (Schema::hasTable('summary_leads')) {
            $locales = "'".implode("', '", WebsiteLocale::ALL)."'";

            DB::statement(<<<'SQL'
                INSERT INTO email_contacts (email, first_seen_at, last_seen_at, signups_count, consent_version, consented_at, created_at, updated_at)
                SELECT email, created_at, last_seen_at, visits, consent_version, consented_at, created_at, updated_at
                FROM summary_leads
            SQL);
            // The old breed slug allowed hyphens; the breed type ids of the new contract use underscores.
            DB::statement(<<<SQL
                INSERT INTO email_signups (email_contact_id, purpose, source, page_path, locale, breed, stage, consent_version, created_at)
                SELECT c.id, 'newsletter', s.source, NULL,
                       CASE WHEN s.locale IN ({$locales}) THEN s.locale END,
                       replace(s.breed, '-', '_'), s.stage, s.consent_version, s.created_at
                FROM summary_leads s
                JOIN email_contacts c ON c.email = s.email
            SQL);

            Schema::drop('summary_leads');
        }
    }

    /**
     * Recreates summary_leads from the contacts (one row per contact: the FIRST signup's source,
     * the LATEST signup's breed / stage / locale). Signups of other sources or purposes than the
     * old table knew are not restored.
     */
    public function down(): void
    {
        Schema::create('summary_leads', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            $table->string('consent_version', 16);
            $table->timestamp('consented_at');
            $table->string('source', 32);
            $table->string('locale', 2)->nullable();
            $table->string('breed', 64)->nullable();
            $table->string('stage', 8)->nullable();
            $table->unsignedInteger('visits')->default(1);
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index('last_seen_at');
            $table->index('breed');
        });

        DB::statement("ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_source_check CHECK (source IN ('real_cost', 'breed_quiz'))");
        DB::statement("ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_stage_check CHECK (stage IS NULL OR stage IN ('adult', 'puppy'))");
        DB::statement('ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_email_lower_check CHECK (email = lower(email))');
        DB::statement("ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_locale_check CHECK (locale IS NULL OR locale ~ '^[a-z]{2}\$')");

        DB::statement(<<<'SQL'
            INSERT INTO summary_leads (email, consent_version, consented_at, source, locale, breed, stage, visits, last_seen_at, created_at, updated_at)
            SELECT c.email, c.consent_version, c.consented_at,
                   (SELECT f.source FROM email_signups f WHERE f.email_contact_id = c.id AND f.source IN ('real_cost', 'breed_quiz') ORDER BY f.id ASC LIMIT 1),
                   (SELECT l.locale FROM email_signups l WHERE l.email_contact_id = c.id AND l.locale IS NOT NULL ORDER BY l.id DESC LIMIT 1),
                   (SELECT b.breed FROM email_signups b WHERE b.email_contact_id = c.id AND b.breed IS NOT NULL ORDER BY b.id DESC LIMIT 1),
                   (SELECT t.stage FROM email_signups t WHERE t.email_contact_id = c.id AND t.stage IS NOT NULL ORDER BY t.id DESC LIMIT 1),
                   c.signups_count, c.last_seen_at, c.first_seen_at, c.updated_at
            FROM email_contacts c
            WHERE EXISTS (SELECT 1 FROM email_signups x WHERE x.email_contact_id = c.id AND x.source IN ('real_cost', 'breed_quiz'))
        SQL);

        Schema::dropIfExists('email_signups');
        Schema::dropIfExists('email_contacts');
    }
};
