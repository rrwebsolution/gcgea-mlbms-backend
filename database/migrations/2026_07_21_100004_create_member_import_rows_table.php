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
        Schema::create('member_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('member_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data');

            $table->string('validation_category'); // New | Exact | Probable | Possible | Invalid
            $table->json('validation_reasons')->nullable();
            $table->decimal('duplicate_score', 5, 2)->nullable();
            $table->json('duplicate_candidate_member_ids')->nullable();
            $table->string('resolved_action')->nullable(); // create_new | skip | merge_into:{id}

            $table->unsignedBigInteger('resolved_office_id')->nullable();
            $table->string('unresolved_office_text')->nullable();

            $table->string('row_status')->default('Pending'); // Pending -> Imported | Skipped | Failed
            $table->unsignedBigInteger('created_member_id')->nullable();

            $table->timestamps();

            $table->index(['batch_id', 'validation_category']);
            $table->index(['batch_id', 'row_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_import_rows');
    }
};
