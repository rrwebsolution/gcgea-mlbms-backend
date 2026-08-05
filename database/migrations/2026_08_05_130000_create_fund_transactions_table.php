<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('contribution_funds')->updateOrInsert(
            ['fund_name' => 'Cash Pabaon Fund'],
            ['allocation_type' => 'Fixed Amount', 'allocation_value' => 0, 'description' => 'Funded by posted Cash Pabaon contributions.', 'is_enabled' => true, 'display_order' => 6, 'created_at' => $now, 'updated_at' => $now]
        );

        Schema::create('fund_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_id')->constrained('contribution_funds')->restrictOnDelete();
            $table->string('fund_name_snapshot');
            $table->string('transaction_type')->default('Debit');
            $table->decimal('amount', 12, 2);
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('reference_number')->nullable();
            $table->string('description')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'transaction_type']);
            $table->index(['fund_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_transactions');
        $fundId = DB::table('contribution_funds')->where('fund_name', 'Cash Pabaon Fund')->where('allocation_value', 0)->value('id');
        if ($fundId) {
            DB::table('contribution_fund_allocations')->where('fund_id', $fundId)->delete();
            DB::table('contribution_funds')->where('id', $fundId)->delete();
        }
    }
};
