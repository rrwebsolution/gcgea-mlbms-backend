<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year')->unique();
            $table->decimal('estimated_revenue', 14, 2)->default(0);
            $table->string('status')->default('Draft');
            $table->text('notes')->nullable();
            $table->string('prepared_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('annual_budget_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annual_budget_id')->constrained()->cascadeOnDelete();
            $table->string('account_title');
            $table->decimal('proposed_amount', 14, 2)->default(0);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('annual_budgets')->insert([
            'fiscal_year' => 2026,
            'estimated_revenue' => 1254000,
            'status' => 'Draft',
            'notes' => 'Initial proposed annual budget based on the GCGEA 2026 budget worksheet.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $budgetId = DB::table('annual_budgets')->where('fiscal_year', 2026)->value('id');

        $items = [
            ['CERTIFICATIONS, PLAQUES, TOKEN', 10000],
            ['CHARTER DAY EXPENSES', 5000],
            ['CHRISTMAS GIVE AWAYS', 350000],
            ['RICE ASSISTANCE', 201000],
            ['DONATIONS', 10000],
            ['TRANSPORTATION & COMMUNICATION ALLOWANCE', 181200],
            ['GENERAL ASSEMBLY', 250000],
            ['HONORARIUM (TREASURER & BOOKKEEPER)', 72000],
            ['MEALS AND SNACKS', 15000],
            ['MEDICAL ASSISTANCE (BOD)', 30000],
            ['MISCELLANEOUS', 14800],
            ['MS. CHARTER', 30000],
            ['OFFICE EQUIPMENT', 30000],
            ['OFFICE SUPPLIES', 5000],
            ['REPAIR AND MAINTENANCE-IT', 3000],
            ['TRAININGS AND SEMINAR (BOD)', 25000],
            ['TRAININGS AND SEMINAR (MEMBERS)', 10000],
            ['TRAVEL EXPENSE', 10000],
            ['T-SHIRT GCGEA UNIFORM', 2000],
        ];

        DB::table('annual_budget_items')->insert(array_map(
            fn (array $item, int $index) => [
                'annual_budget_id' => $budgetId,
                'account_title' => $item[0],
                'proposed_amount' => $item[1],
                'display_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $items,
            array_keys($items)
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('annual_budget_items');
        Schema::dropIfExists('annual_budgets');
    }
};
