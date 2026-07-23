<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('application_type')->default('new')->after('status');
            $table->foreignId('previous_loan_id')->nullable()->after('application_type')->constrained('loans')->nullOnDelete();
            $table->foreignId('root_loan_id')->nullable()->after('previous_loan_id')->constrained('loans')->nullOnDelete();
            $table->unsignedSmallInteger('reloan_sequence')->nullable()->after('root_loan_id');

            $table->decimal('current_net_take_home_pay', 12, 2)->nullable()->after('reloan_sequence');
            $table->json('reloan_policy_snapshot')->nullable()->after('eligibility_override_reason');
            $table->decimal('previous_obligation_amount', 12, 2)->nullable()->after('reloan_policy_snapshot');
            $table->string('previous_obligation_settlement_method')->nullable()->after('previous_obligation_amount');
            $table->timestamp('previous_obligation_settled_at')->nullable()->after('previous_obligation_settlement_method');

            $table->index('application_type');
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['application_type']);
            $table->dropIndex(['member_id']);
            $table->dropConstrainedForeignId('previous_loan_id');
            $table->dropConstrainedForeignId('root_loan_id');
            $table->dropColumn([
                'application_type',
                'reloan_sequence',
                'current_net_take_home_pay',
                'reloan_policy_snapshot',
                'previous_obligation_amount',
                'previous_obligation_settlement_method',
                'previous_obligation_settled_at',
            ]);
        });
    }
};
