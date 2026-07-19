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
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            // Nullable briefly at insert time — the controller derives this from
            // the row's own id right after create() and immediately fills it in.
            $table->string('member_number')->nullable()->unique();
            $table->string('employee_number');
            $table->string('surname');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('suffix')->nullable();
            $table->string('sex');
            $table->date('birthdate');
            $table->string('civil_status');
            $table->text('permanent_address');
            $table->string('cellphone_number');
            $table->string('email')->nullable();
            $table->string('name_of_spouse')->nullable();
            $table->string('profile_photo_url')->nullable();

            $table->foreignId('office_id')->constrained();
            $table->string('position');
            $table->date('date_of_regular_appointment');
            $table->string('employment_status');

            $table->string('membership_type');
            $table->date('membership_date');
            $table->string('membership_status');
            $table->string('retiree_status');
            $table->text('remarks')->nullable();

            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->text('archived_reason')->nullable();

            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
