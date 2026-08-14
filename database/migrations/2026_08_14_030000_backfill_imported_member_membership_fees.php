<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = DB::table('system_settings')->where('section', 'general')->value('value');
        $settings = is_string($settings) ? (json_decode($settings, true) ?: []) : ((array) $settings);
        $amount = (float) ($settings['membershipRegistrationFee'] ?? 100);

        DB::table('members')->whereNotNull('imported_from_batch_id')
            ->orderBy('id')->chunkById(250, function ($members) use ($amount) {
                $now = now();
                $rows = [];
                foreach ($members as $member) {
                    $existing = DB::table('membership_fee_payments')->where('member_id', $member->id)->first();
                    if ($existing) {
                        if ($existing->status !== 'Posted') {
                            DB::table('membership_fee_payments')->where('id', $existing->id)->update([
                                'payment_date' => $existing->payment_date ?: $member->membership_date ?: substr((string) $member->created_at, 0, 10),
                                'payment_method' => 'Imported Membership Record',
                                'received_by' => 'Member Import Backfill',
                                'status' => 'Posted',
                                'updated_at' => $now,
                            ]);
                        }

                        continue;
                    }
                    $rows[] = [
                        'member_id' => $member->id,
                        'reference_number' => 'GCGEA-MF-IMPORT-'.str_pad((string) $member->id, 6, '0', STR_PAD_LEFT),
                        'amount' => $amount,
                        'payment_date' => $member->membership_date ?: substr((string) $member->created_at, 0, 10),
                        'payment_method' => 'Imported Membership Record',
                        'received_by' => 'Member Import Backfill',
                        'status' => 'Posted',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    DB::table('membership_fee_payments')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('membership_fee_payments')->where('payment_method', 'Imported Membership Record')->where('received_by', 'Member Import Backfill')->delete();
    }
};
