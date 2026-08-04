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
        if (! Schema::hasColumn('beneficiaries', 'priority_order')) {
            Schema::table('beneficiaries', function (Blueprint $table) {
                $table->unsignedTinyInteger('priority_order')->nullable()->after('share_percentage');
            });
        }

        if (! Schema::hasColumn('beneficiaries', 'source')) {
            Schema::table('beneficiaries', function (Blueprint $table) {
                $table->string('source')->default('manual')->after('priority_order');
            });
        }

        // birthdate/relationship are NOT NULL, but the source workbook's
        // "Name of Dependent/s and Beneficiary/ies" columns give only a name
        // — no doctrine/dbal installed, so raw SQL rather than ->change().
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->date('birthdate')->nullable()->change();
            $table->string('relationship')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('relationship')->nullable(false)->change();
            $table->date('birthdate')->nullable(false)->change();
        });

        $columns = array_values(array_filter(
            ['priority_order', 'source'],
            fn (string $column): bool => Schema::hasColumn('beneficiaries', $column),
        ));

        if ($columns !== []) {
            Schema::table('beneficiaries', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
