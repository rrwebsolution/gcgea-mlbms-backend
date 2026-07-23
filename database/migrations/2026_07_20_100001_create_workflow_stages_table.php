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
        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('code');
            $table->string('label');
            $table->string('stage_type');
            $table->string('approver_type');
            $table->foreignId('approver_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->string('required_permission_code')->nullable();
            $table->boolean('is_final')->default(false);
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_stages');
    }
};
