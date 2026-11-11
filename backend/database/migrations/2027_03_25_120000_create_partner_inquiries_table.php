<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6-S03 (WEBSITE_SALES_SPEC §5.2): partner inquiries from the petprep.si
 * partners page. Business contact data only (company, contact person, e-mail,
 * message) — no IP, no child or family data. Deleted after 12 months by
 * `partner-inquiries:prune`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('company', 160);
            $table->string('contact_name', 120);
            $table->string('email', 190);
            $table->string('type', 32);
            $table->text('message');
            $table->string('locale', 2)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        DB::statement("ALTER TABLE partner_inquiries ADD CONSTRAINT partner_inquiries_type_check CHECK (type IN ('series', 'sponsored_content', 'insurer', 'directory', 'shelter', 'other'))");
        DB::statement("ALTER TABLE partner_inquiries ADD CONSTRAINT partner_inquiries_locale_check CHECK (locale IS NULL OR locale IN ('sl', 'en'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_inquiries');
    }
};
