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
            // No FK constraint on purpose — this column exists only to filter
            // the Pending Review queue and for traceability; it must not
            // block deleting an old import batch's bookkeeping rows later.
            $table->unsignedBigInteger('imported_from_batch_id')->nullable()->after('created_by');
            $table->index('imported_from_batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['imported_from_batch_id']);
            $table->dropColumn('imported_from_batch_id');
        });
    }
};
