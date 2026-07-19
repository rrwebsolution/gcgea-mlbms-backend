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
        Schema::create('benefit_applications', function (Blueprint $table) {
            $table->id();
            // Nullable briefly at insert time, filled in right after create() from the row's own id.
            $table->string('application_number')->nullable()->unique();
            $table->date('application_date');
            $table->foreignId('member_id')->constrained();
            $table->foreignId('benefit_type_id')->constrained();

            $table->decimal('requested_amount', 12, 2);
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->text('reason');
            $table->date('incident_date')->nullable();
            $table->string('beneficiary_or_recipient');
            $table->json('requirements')->nullable();

            $table->string('status')->default('Draft');
            $table->json('eligibility')->nullable();
            $table->string('eligibility_result')->nullable();

            $table->date('release_date')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('remarks')->nullable();

            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_applications');
    }
};
