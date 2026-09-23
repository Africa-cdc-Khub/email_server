<?php

use App\Enums\EmailDriver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Apply Hostinger's typical SMTP daily cap (500) to SMTP mailboxes
     * that still have the previous generic default of 10_000.
     */
    public function up(): void
    {
        $smtpProviderIds = DB::table('email_providers')
            ->where('driver', EmailDriver::Smtp->value)
            ->pluck('id');

        if ($smtpProviderIds->isEmpty()) {
            return;
        }

        DB::table('provider_mailboxes')
            ->whereIn('email_provider_id', $smtpProviderIds)
            ->where('daily_quota', 10000)
            ->update(['daily_quota' => 500, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $smtpProviderIds = DB::table('email_providers')
            ->where('driver', EmailDriver::Smtp->value)
            ->pluck('id');

        if ($smtpProviderIds->isEmpty()) {
            return;
        }

        DB::table('provider_mailboxes')
            ->whereIn('email_provider_id', $smtpProviderIds)
            ->where('daily_quota', 500)
            ->update(['daily_quota' => 10000, 'updated_at' => now()]);
    }
};
