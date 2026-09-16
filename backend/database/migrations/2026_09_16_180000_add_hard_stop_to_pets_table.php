<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            // Hard stop flag: when true, the child's app interface is locked
            // by the parent via the emergency hard stop button.
            $table->boolean('is_hard_stopped')->default(false)->after('is_game_over');
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('is_hard_stopped');
        });
    }
};
