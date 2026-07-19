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
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            // Nullable briefly at insert time — the controller derives this from
            // the row's own id right after create() and immediately fills it in.
            $table->string('reference_number')->nullable()->unique();
            $table->foreignId('member_id')->constrained();
            $table->string('contribution_period'); // "YYYY-MM" — a plain string, not a real date.
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_method');
            $table->string('official_receipt_number')->nullable();
            $table->string('payroll_reference')->nullable();
            $table->text('remarks')->nullable();
            $table->string('encoded_by');
            $table->string('status')->default('Posted');
            $table->string('void_reason')->nullable();
            $table->string('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index('contribution_period');
            $table->index('status');
            $table->index('payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contributions');
    }
};
