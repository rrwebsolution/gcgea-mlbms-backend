<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_application_id')->constrained('benefit_applications')->cascadeOnDelete();
            $table->string('requirement_label');
            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('uploaded_by');
            $table->timestamp('uploaded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_documents');
    }
};
