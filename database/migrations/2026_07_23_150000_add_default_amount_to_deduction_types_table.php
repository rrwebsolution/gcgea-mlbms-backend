<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deduction_types', function (Blueprint $table) {
            $table->decimal('default_amount', 12, 2)->default(200)->after('description');
        });

        DB::table('deduction_types')->where('code', 'pabaon')->update(['default_amount' => 200]);
    }

    public function down(): void
    {
        Schema::table('deduction_types', function (Blueprint $table) {
            $table->dropColumn('default_amount');
        });
    }
};
