<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('legacy_import_fingerprint', 64)->nullable()->unique();
            $table->string('legacy_source_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropUnique(['legacy_import_fingerprint']);
            $table->dropColumn(['legacy_import_fingerprint', 'legacy_source_name']);
        });
    }
};
