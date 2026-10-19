<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * System health (David 2026-10-19): one row per distinct error (fingerprint =
 * sha256 of exception class + normalised message + first app file:line),
 * written by App\Services\SystemHealth\ErrorRecorder from the exception
 * handler and error-level log lines. No request bodies, headers, names or
 * e-mails — at most user id + role. `system_error_hours` holds occurrences
 * per hour for the /admin/system-health chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_errors', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('level', 16);
            $table->string('exception_class')->nullable();
            $table->text('message');
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->jsonb('trace')->nullable();
            $table->string('source', 16);
            $table->jsonb('context')->nullable();
            $table->unsignedBigInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE system_errors ADD CONSTRAINT system_errors_source_check CHECK (source IN ('http', 'command', 'job', 'log', 'other'))");

        Schema::create('system_error_hours', function (Blueprint $table) {
            $table->foreignId('system_error_id')->constrained('system_errors')->cascadeOnDelete();
            $table->timestamp('hour');
            $table->unsignedInteger('occurrences')->default(0);
            $table->primary(['system_error_id', 'hour']);
            $table->index('hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_error_hours');
        Schema::dropIfExists('system_errors');
    }
};
