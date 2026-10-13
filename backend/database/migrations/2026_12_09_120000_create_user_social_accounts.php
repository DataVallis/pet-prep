<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M2-10c — parent sign-in with Google / Apple.
 *
 * One row per provider identity linked to a PetPrep parent: `provider`
 * (google | apple, CHECK mirrors App\Enums\SocialProvider) + `subject` (the
 * provider's stable user id, `sub`), unique together. `email` = the address
 * the provider reported when the link was made (may be an Apple "Hide My
 * Email" relay address; null when the provider gave none). Cascades with the
 * user (account deletion, M2-08).
 *
 * `users.password` is already nullable (M2-02, PIN-only children); a parent
 * created by social sign-in has no password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('subject', 255);
            $table->string('email', 255)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'subject']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE user_social_accounts ADD CONSTRAINT user_social_accounts_provider_check CHECK (provider IN ('google', 'apple'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_accounts');
    }
};
