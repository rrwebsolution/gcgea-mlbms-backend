<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A simple, audit-logged Board Resolution exception record — not a
     * routed approval stage. Created whenever a loan/reloan's failed
     * eligibility check (e.g. the 6-month minimum) is overridden.
     */
    public function up(): void
    {
        Schema::create('loan_eligibility_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->string('exception_type');
            $table->text('reason');
            $table->string('board_resolution_reference')->nullable();
            $table->string('board_resolution_document_path')->nullable();
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamp('granted_at');
            $table->timestamps();

            $table->index('loan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_eligibility_exceptions');
    }
};
