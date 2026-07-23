<?php

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
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority_order')->nullable()->after('share_percentage');
            $table->string('source')->default('manual')->after('priority_order');
        });

        // birthdate/relationship are NOT NULL, but the source workbook's
        // "Name of Dependent/s and Beneficiary/ies" columns give only a name
        // — no doctrine/dbal installed, so raw SQL rather than ->change().
        DB::statement('ALTER TABLE beneficiaries ALTER COLUMN birthdate DROP NOT NULL');
        DB::statement('ALTER TABLE beneficiaries ALTER COLUMN relationship DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE beneficiaries ALTER COLUMN relationship SET NOT NULL');
        DB::statement('ALTER TABLE beneficiaries ALTER COLUMN birthdate SET NOT NULL');

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['priority_order', 'source']);
        });
    }
};
