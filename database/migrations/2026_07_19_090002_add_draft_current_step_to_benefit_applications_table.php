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
        Schema::table('benefit_applications', function (Blueprint $table) {
            $table->unsignedSmallInteger('draft_current_step')->nullable()->default(1)->after('status');
        });

        // A draft only needs member_id (see BenefitRequest) — everything
        // else was NOT NULL because every real application has it filled by
        // the time store() runs. Drafts saved before Step 2 don't have it yet.
        Schema::table('benefit_applications', function (Blueprint $table) {
            $table->foreignId('benefit_type_id')->nullable()->change();
            $table->decimal('requested_amount', 12, 2)->nullable()->change();
            $table->text('reason')->nullable()->change();
            $table->string('beneficiary_or_recipient')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('benefit_applications', function (Blueprint $table) {
            $table->foreignId('benefit_type_id')->nullable(false)->change();
            $table->decimal('requested_amount', 12, 2)->nullable(false)->change();
            $table->text('reason')->nullable(false)->change();
            $table->string('beneficiary_or_recipient')->nullable(false)->change();
        });

        Schema::table('benefit_applications', function (Blueprint $table) {
            $table->dropColumn('draft_current_step');
        });
    }
};
