<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Responsibility contract (PRODUCT_SPEC §3, M1-07): the child signs with a
 * finger once per pet. The drawing is stored as an SVG path string or a small
 * PNG (base64); nothing is sent to third parties. `signed_at` is server time.
 * Re-signing is refused (409) — the first signature is the record.
 *
 * Also adds `signed_contract` to activities_log so the parent timeline shows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->unique()->constrained('pets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('signature_format', 16);
            $table->text('signature');
            $table->timestamp('signed_at');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE pet_contracts ADD CONSTRAINT pet_contracts_signature_format_check CHECK (signature_format IN ('svg_path', 'png'))");

        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement("ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop', 'ignored_warning', 'signed_contract'))");
    }

    public function down(): void
    {
        // Fails (on purpose, nothing is deleted) if signed_contract rows exist.
        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement("ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop', 'ignored_warning'))");

        Schema::dropIfExists('pet_contracts');
    }
};
