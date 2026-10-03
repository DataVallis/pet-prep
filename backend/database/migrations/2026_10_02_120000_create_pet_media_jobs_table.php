<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track every asynchronous fal.ai generation request so that incoming
     * webhooks can be matched to a request we actually made (and to its pet),
     * instead of trusting a pet_id passed in the query string.
     */
    public function up(): void
    {
        Schema::create('pet_media_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('request_id')->unique();
            $table->string('kind');                 // video (image generation is synchronous inside a queued job)
            $table->string('pet_state')->nullable(); // PetStateEnum value the video depicts
            $table->string('status')->default('pending');
            $table->text('result_url')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['pet_id', 'status']);
        });

        DB::statement("ALTER TABLE pet_media_jobs ADD CONSTRAINT pet_media_jobs_kind_check CHECK (kind IN ('video'))");
        DB::statement("ALTER TABLE pet_media_jobs ADD CONSTRAINT pet_media_jobs_status_check CHECK (status IN ('pending', 'completed', 'failed'))");

        Schema::table('pets', function (Blueprint $table) {
            // Lifecycle of the pet's AI reference image (Pet DNA anchor).
            $table->string('media_status')->default('disabled');
        });

        DB::statement("ALTER TABLE pets ADD CONSTRAINT pets_media_status_check CHECK (media_status IN ('disabled', 'pending', 'ready', 'failed'))");

        // Pets that already have a reference image are ready.
        DB::statement("UPDATE pets SET media_status = 'ready' WHERE pet_dna->>'reference_image_url' IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_media_status_check');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('media_status');
        });

        Schema::dropIfExists('pet_media_jobs');
    }
};
