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
        Schema::create('benefit_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('default_amount', 12, 2)->default(0);
            $table->decimal('maximum_amount', 12, 2)->default(0);
            $table->text('eligibility_requirements')->nullable();
            $table->unsignedInteger('required_membership_months')->default(0);
            $table->string('frequency_limit')->nullable();
            $table->json('required_documents')->nullable();
            $table->boolean('approval_required')->default(true);
            $table->string('status')->default('Active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_types');
    }
};
