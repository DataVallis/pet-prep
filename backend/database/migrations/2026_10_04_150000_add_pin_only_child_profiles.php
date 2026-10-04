<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M2-02: child profiles without e-mail / password (PIN-only login).
 *
 *  - users.email and users.password become nullable. The unique index on
 *    email stays: PostgreSQL treats NULLs as distinct, so any number of
 *    PIN-only children can exist while non-null e-mails stay unique.
 *  - users.birth_year (optional, the only age datum we keep for a child —
 *    no birthdate, no surname).
 *  - child_login_pins: one-time PINs a parent issues FOR a specific child
 *    (first pairing or re-login on a new device). Only an HMAC of the PIN is
 *    stored. `pet_id` null = new pet (or re-login of an already paired child).
 *
 * Additive: no existing row is rewritten. down() refuses while PIN-only
 * children exist (it would have to invent e-mails / passwords for them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->smallInteger('birth_year')->nullable()->after('name');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_birth_year_range CHECK (birth_year IS NULL OR birth_year BETWEEN 1900 AND 2100)');

        Schema::create('child_login_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->foreignId('child_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Join target (shared pet). A deleted pet takes its open PINs with it.
            $table->foreignId('pet_id')->nullable()->constrained('pets')->cascadeOnDelete();
            // hash_hmac('sha256', pin, APP_KEY) — never the PIN itself.
            $table->string('pin_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['child_user_id', 'consumed_at', 'revoked_at']);
        });

        // At most one OPEN PIN per value (lookup key of POST /api/child/pin-login).
        DB::statement('CREATE UNIQUE INDEX child_login_pins_open_pin_unique ON child_login_pins (pin_hash) WHERE consumed_at IS NULL AND revoked_at IS NULL');
    }

    public function down(): void
    {
        $pinOnly = DB::table('users')->whereNull('email')->orWhereNull('password')->count();
        if ($pinOnly > 0) {
            throw new RuntimeException("Cannot roll back M2-02: {$pinOnly} user(s) have no e-mail or password (PIN-only children). Delete or migrate them first.");
        }

        Schema::dropIfExists('child_login_pins');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_birth_year_range');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('birth_year');
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
