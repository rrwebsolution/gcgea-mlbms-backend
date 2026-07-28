<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benefit_applications', function (Blueprint $table) {
            $table->string('release_reference_number')->nullable()->unique()->after('release_date');
        });
    }

    public function down(): void
    {
        Schema::table('benefit_applications', function (Blueprint $table) {
            $table->dropColumn('release_reference_number');
        });
    }
};
