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
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'full_name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->after('full_name');
            $table->string('contact_number')->nullable()->after('email');
            $table->boolean('require_password_change')->default(false)->after('password');
            $table->text('remarks')->nullable()->after('require_password_change');
            $table->string('status')->default('Active')->after('remarks');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('avatar_url')->nullable()->after('last_login_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username',
                'contact_number',
                'require_password_change',
                'remarks',
                'status',
                'last_login_at',
                'avatar_url',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('full_name', 'name');
        });
    }
};
