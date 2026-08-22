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
        Schema::create('benefit_payments', function (Blueprint $table) {
            $table->id();
            // Nullable briefly at insert time, filled in right after create() from the row's own id.
            $table->string('payment_reference_number')->nullable()->unique();
            $table->foreignId('benefit_application_id')->constrained('benefit_applications');
            $table->foreignId('member_id')->constrained();
            $table->date('payment_date');
            $table->decimal('amount_paid', 12, 2);
            $table->string('received_by')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->default('Posted');
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_payments');
    }
};
