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
        Schema::create('member_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('source_file_ext'); // xlsx | xls | csv
            $table->string('selected_sheet_name')->nullable(); // null for csv
            $table->json('column_mapping')->nullable();
            $table->json('sheet_meta')->nullable();
            $table->string('status')->default('Uploaded'); // Uploaded -> SheetSelected -> Mapped -> Previewed -> Committed
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->foreignId('committed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('committed_at')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('pending_review_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('legacy_loan_flagged_rows')->default(0);
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_import_batches');
    }
};
