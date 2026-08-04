<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('benefit_applications', function (Blueprint $table) {
            $table->decimal('actual_released_amount', 12, 2)->nullable()->after('release_reference_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('benefit_applications', function (Blueprint $table) {
            $table->dropColumn('actual_released_amount');
        });
    }
};
