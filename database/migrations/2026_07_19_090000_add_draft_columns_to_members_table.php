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
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('created_by');
            $table->string('draft_reference_no')->nullable()->after('is_draft');
            $table->unsignedSmallInteger('draft_completion_percentage')->nullable()->after('draft_reference_no');
            $table->unsignedSmallInteger('draft_current_step')->nullable()->default(1)->after('draft_completion_percentage');

            $table->index('is_draft');
        });

        // A draft only needs a name (see MemberRequest) — every other
        // originally-required column must accept null while is_draft=true.
        // Enforced fully again at submit() time via strict validation.
        Schema::table('members', function (Blueprint $table) {
            $table->string('employee_number')->nullable()->change();
            $table->string('sex')->nullable()->change();
            $table->date('birthdate')->nullable()->change();
            $table->string('civil_status')->nullable()->change();
            $table->text('permanent_address')->nullable()->change();
            $table->string('cellphone_number')->nullable()->change();
            $table->foreignId('office_id')->nullable()->change();
            $table->string('position')->nullable()->change();
            $table->date('date_of_regular_appointment')->nullable()->change();
            $table->string('employment_status')->nullable()->change();
            $table->string('membership_type')->nullable()->change();
            $table->date('membership_date')->nullable()->change();
            $table->string('membership_status')->nullable()->change();
            $table->string('retiree_status')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('employee_number')->nullable(false)->change();
            $table->string('sex')->nullable(false)->change();
            $table->date('birthdate')->nullable(false)->change();
            $table->string('civil_status')->nullable(false)->change();
            $table->text('permanent_address')->nullable(false)->change();
            $table->string('cellphone_number')->nullable(false)->change();
            $table->foreignId('office_id')->nullable(false)->change();
            $table->string('position')->nullable(false)->change();
            $table->date('date_of_regular_appointment')->nullable(false)->change();
            $table->string('employment_status')->nullable(false)->change();
            $table->string('membership_type')->nullable(false)->change();
            $table->date('membership_date')->nullable(false)->change();
            $table->string('membership_status')->nullable(false)->change();
            $table->string('retiree_status')->nullable(false)->change();
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['is_draft']);
            $table->dropColumn(['is_draft', 'draft_reference_no', 'draft_completion_percentage', 'draft_current_step']);
        });
    }
};
