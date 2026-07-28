<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Deduction Import previously only accepted .xlsx/.xls and always
 * saved the upload as "original.xlsx" regardless of what was actually
 * uploaded, so the parser could load it without knowing the real format.
 * Accepting CSV too means the stored file needs to keep its real extension,
 * and the parser needs to know it to pick the right PhpSpreadsheet reader
 * (mirrors member_import_batches.source_file_ext).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_import_batches', function (Blueprint $table) {
            $table->string('source_file_ext', 10)->default('xlsx')->after('storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_import_batches', function (Blueprint $table) {
            $table->dropColumn('source_file_ext');
        });
    }
};
