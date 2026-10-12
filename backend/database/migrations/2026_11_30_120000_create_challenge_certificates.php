<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M2-01d — Responsibility Certificate per child (CERTIFICATE_SPEC, David
 * 2026-10-12).
 *
 *  - `challenge_certificates`: ONE row per (pet, child) once that child's
 *    12-week challenge ended (84 program days, or game over before that).
 *    Number / outcome / score / dates are written once by the tick
 *    (ChallengeCertificateService) and never change; child_name / pet_name /
 *    locale / pdf_path / generated_* are written when a parent generates the
 *    PDF (again on every regenerate).
 *    FKs: pet + child CASCADE (account deletion removes the rows; files are
 *    removed by DeleteCertificateFiles), family RESTRICT like `pets` (the
 *    family is deleted last, after its pets and members), generated_by → null.
 *  - `challenge_certificate_numbers`: per-year counter for the certificate
 *    number PP-YYYY-NNNNNN (atomic upsert … RETURNING, never reused).
 *  - push type `challenge_completed` (parents, once per row).
 *  - `pets.certificate_next_check_at` (QA M1): when the tick looks at the pet
 *    again (null = now). The scan orders by it, so pets that cannot finish yet
 *    move to the back and never starve newer ones; a new caretaker row or a
 *    signed contract resets it (PetCaretaker / PetContract hooks).
 */
return new class extends Migration
{
    private const PUSH_TYPES_OLD = "'soft_warning', 'critical_alert', 'walk_reminder', 'parent_intervention_alarm', 'illness_triggered', "
        ."'game_over_virtual_shelter', 'trial_ending', 'payment_required', 'play_reminder', 'litter_reminder'";

    private const PUSH_TYPES_NEW = self::PUSH_TYPES_OLD.", 'challenge_completed'";

    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('certificate_next_check_at')->nullable();
            $table->index('certificate_next_check_at');
        });

        Schema::create('challenge_certificate_numbers', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number');
        });

        Schema::create('challenge_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->foreignId('child_user_id')->constrained('users')->cascadeOnDelete();
            // PP-YYYY-NNNNNN; only a certificate gets one (a report is not a certificate).
            $table->string('number', 20)->nullable()->unique();
            $table->string('outcome', 20);
            $table->string('reason', 20)->nullable();
            $table->unsignedSmallInteger('care_score')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->date('completed_local_date');
            $table->string('species', 10);
            $table->string('breed_type', 50);
            // The pet's name when the challenge ended (null = none).
            $table->string('default_pet_name', 30)->nullable();
            // Parent-editable, written by POST …/generate.
            $table->string('child_name', 30)->nullable();
            $table->string('pet_name', 30)->nullable();
            $table->string('locale', 5)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['pet_id', 'child_user_id']);
            $table->index('child_user_id');
            $table->index('family_id');
        });

        DB::statement("ALTER TABLE challenge_certificates ADD CONSTRAINT challenge_certificates_outcome_check CHECK (outcome IN ('certificate', 'report'))");
        DB::statement("ALTER TABLE challenge_certificates ADD CONSTRAINT challenge_certificates_reason_check CHECK (
            (outcome = 'certificate' AND reason IS NULL AND number IS NOT NULL)
            OR (outcome = 'report' AND reason IN ('low_score', 'game_over') AND number IS NULL))");
        DB::statement("ALTER TABLE challenge_certificates ADD CONSTRAINT challenge_certificates_locale_check CHECK (locale IS NULL OR locale IN ('en', 'sl'))");
        DB::statement('ALTER TABLE challenge_certificates ADD CONSTRAINT challenge_certificates_score_check CHECK (care_score IS NULL OR care_score BETWEEN 0 AND 100)');

        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement('ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('.self::PUSH_TYPES_NEW.'))');
    }

    public function down(): void
    {
        DB::table('push_notifications')->where('type', 'challenge_completed')->delete();
        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement('ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('.self::PUSH_TYPES_OLD.'))');

        Schema::dropIfExists('challenge_certificates');
        Schema::dropIfExists('challenge_certificate_numbers');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropIndex(['certificate_next_check_at']);
            $table->dropColumn('certificate_next_check_at');
        });
    }
};
