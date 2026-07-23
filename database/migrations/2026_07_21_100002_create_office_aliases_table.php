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
        Schema::create('office_aliases', function (Blueprint $table) {
            $table->id();
            // Pre-normalized (lowercased, whitespace-collapsed) before insert
            // — no functional/citext index, matching this codebase's other
            // normalize-in-app-code conventions (see PayrollColumnMapper).
            $table->string('alias_text')->unique();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('usage_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('office_aliases');
    }
};
