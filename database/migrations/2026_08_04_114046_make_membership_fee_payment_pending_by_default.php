<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Membership registration was previously auto-posting this row as "Posted"
 * the moment a member registered — no actual payment was ever collected.
 * Registration now creates it as "Unpaid" instead, and it's only marked
 * "Posted" once the Treasurer actually posts it via Treasury > Payments.
 * Raw SQL (not Schema::table()->change()) to avoid the doctrine/dbal
 * dependency for a plain ALTER COLUMN on Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE membership_fee_payments ALTER COLUMN payment_date DROP NOT NULL');
        DB::statement('ALTER TABLE membership_fee_payments ALTER COLUMN received_by DROP NOT NULL');
        DB::statement("ALTER TABLE membership_fee_payments ALTER COLUMN status SET DEFAULT 'Unpaid'");
    }

    public function down(): void
    {
        DB::statement("UPDATE membership_fee_payments SET payment_date = COALESCE(payment_date, created_at::date), received_by = COALESCE(received_by, 'Unknown') WHERE payment_date IS NULL OR received_by IS NULL");
        DB::statement('ALTER TABLE membership_fee_payments ALTER COLUMN payment_date SET NOT NULL');
        DB::statement('ALTER TABLE membership_fee_payments ALTER COLUMN received_by SET NOT NULL');
        DB::statement("ALTER TABLE membership_fee_payments ALTER COLUMN status SET DEFAULT 'Posted'");
    }
};
