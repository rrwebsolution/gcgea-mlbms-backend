<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->string('contribution_type')->default('Monthly Dues')->after('contribution_period');
            $table->index('contribution_type');
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropIndex(['contribution_type']);
            $table->dropColumn('contribution_type');
        });
    }
};
