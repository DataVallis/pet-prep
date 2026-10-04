<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared pet (M2-01, ADR-012): every caretaker child signs their own
 * contract. One contract per (pet, child) instead of one per pet. The pet is
 * born at the first signature; later caretakers only store theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_contracts', function (Blueprint $table) {
            $table->dropUnique(['pet_id']);
            $table->unique(['pet_id', 'user_id']);
        });
    }

    public function down(): void
    {
        $shared = DB::table('pet_contracts')->groupBy('pet_id')->havingRaw('count(*) > 1')->pluck('pet_id');
        if ($shared->isNotEmpty()) {
            // Nothing is deleted on purpose: a signed contract is a record.
            throw new RuntimeException('Pets with more than one contract (pet ids: '.$shared->implode(', ').'); cannot restore unique(pet_id).');
        }

        Schema::table('pet_contracts', function (Blueprint $table) {
            $table->dropUnique(['pet_id', 'user_id']);
            $table->unique(['pet_id']);
        });
    }
};
