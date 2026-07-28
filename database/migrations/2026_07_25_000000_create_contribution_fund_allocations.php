<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_funds', function (Blueprint $table) {
            $table->id();
            $table->string('fund_name')->unique();
            $table->string('allocation_type', 20);
            $table->decimal('allocation_value', 12, 2);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('contribution_fund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fund_id')->constrained('contribution_funds')->restrictOnDelete();
            $table->string('fund_name_snapshot');
            $table->decimal('allocated_amount', 12, 2);
            $table->timestamps();
            $table->unique(['contribution_id', 'fund_id']);
        });

        $now = now();
        DB::table('contribution_funds')->insert([
            ['fund_name' => 'Mortuary Fund', 'allocation_type' => 'Fixed Amount', 'allocation_value' => 25, 'is_enabled' => true, 'display_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['fund_name' => 'Emergency Fund', 'allocation_type' => 'Fixed Amount', 'allocation_value' => 30, 'is_enabled' => true, 'display_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['fund_name' => 'Operational Fund', 'allocation_type' => 'Fixed Amount', 'allocation_value' => 15, 'is_enabled' => true, 'display_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['fund_name' => 'Retirement Fund', 'allocation_type' => 'Fixed Amount', 'allocation_value' => 15, 'is_enabled' => true, 'display_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['fund_name' => 'Loan Investment Fund', 'allocation_type' => 'Fixed Amount', 'allocation_value' => 15, 'is_enabled' => true, 'display_order' => 5, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $funds = DB::table('contribution_funds')->orderBy('display_order')->get();
        DB::table('contributions')->where('contribution_type', 'Monthly Dues')->where('status', 'Posted')
            ->orderBy('id')->chunkById(200, function ($contributions) use ($funds, $now) {
                $rows = [];
                foreach ($contributions as $contribution) {
                    foreach ($funds as $fund) {
                        $rows[] = [
                            'contribution_id' => $contribution->id,
                            'fund_id' => $fund->id,
                            'fund_name_snapshot' => $fund->fund_name,
                            'allocated_amount' => (float) $fund->allocation_value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if ($rows !== []) DB::table('contribution_fund_allocations')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_fund_allocations');
        Schema::dropIfExists('contribution_funds');
    }
};
