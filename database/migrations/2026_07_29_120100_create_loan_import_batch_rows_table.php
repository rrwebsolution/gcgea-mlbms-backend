<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_import_batch_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('loan_import_batches')->cascadeOnDelete();
            $table->string('sheet');
            $table->unsignedInteger('row_number');
            $table->string('source_name');
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();
            $table->decimal('principal', 14, 2)->default(0);
            $table->decimal('interest', 14, 2)->default(0);
            $table->decimal('principal_balance', 14, 2)->default(0);
            $table->decimal('interest_balance', 14, 2)->default(0);
            $table->string('status');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_import_batch_rows');
    }
};
