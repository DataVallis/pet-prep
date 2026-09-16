<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add PetPrep-specific columns to the users table
        Schema::table('users', function (Blueprint $table) {
            // role enum: 'parent' or 'child' — default to parent so existing
            // Laravel scaffold users don't break; pairing flow sets child explicitly.
            $table->string('role')->default(UserRole::Parent->value)->after('id');
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete()
                ->after('role');
            $table->string('pairing_pin', 6)->nullable()->after('parent_id');
            $table->timestamp('pin_expires_at')->nullable()->after('pairing_pin');
            $table->string('revenuecat_id')->nullable()->unique()->after('pin_expires_at');
        });

        // Add a DB-level check constraint for the role enum values
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('parent', 'child'))");

        // Indexes for high-frequency query columns (per Phase 7 engineering standards)
        Schema::table('users', function (Blueprint $table) {
            $table->index('parent_id', 'users_parent_id_index');
            $table->index('pairing_pin', 'users_pairing_pin_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_pairing_pin_index');
            $table->dropIndex('users_parent_id_index');
            $table->dropColumn(['role', 'parent_id', 'pairing_pin', 'pin_expires_at', 'revenuecat_id']);
        });
    }
};
