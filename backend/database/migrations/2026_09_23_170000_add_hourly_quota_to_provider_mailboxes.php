<?php

use App\Enums\EmailDriver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hostinger SMTP: 500/hour and 12_000/day hard limits.
     * App defaults: hourly_quota 400, daily_quota 10_000 (under the provider caps).
     */
    public function up(): void
    {
        Schema::table('provider_mailboxes', function (Blueprint $table) {
            $table->unsignedInteger('hourly_quota')->nullable()->after('daily_quota');
        });

        $smtpIds = DB::table('email_providers')
            ->where('driver', EmailDriver::Smtp->value)
            ->pluck('id');

        if ($smtpIds->isEmpty()) {
            return;
        }

        DB::table('provider_mailboxes')
            ->whereIn('email_provider_id', $smtpIds)
            ->update([
                'hourly_quota' => 400,
                'updated_at' => now(),
            ]);

        // Lift the earlier mistaken 500/day default up toward the real daily cap.
        DB::table('provider_mailboxes')
            ->whereIn('email_provider_id', $smtpIds)
            ->where('daily_quota', 500)
            ->update([
                'daily_quota' => 10000,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('provider_mailboxes', function (Blueprint $table) {
            $table->dropColumn('hourly_quota');
        });
    }
};
