<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('legacy_loan_identity', 64)->nullable()->index();
        });

        // The original fingerprint represented name + start + principal +
        // interest, which is now the stable identity across monthly imports.
        DB::table('loans')
            ->whereNotNull('legacy_import_fingerprint')
            ->update(['legacy_loan_identity' => DB::raw('legacy_import_fingerprint')]);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['legacy_loan_identity']);
            $table->dropColumn('legacy_loan_identity');
        });
    }
};
