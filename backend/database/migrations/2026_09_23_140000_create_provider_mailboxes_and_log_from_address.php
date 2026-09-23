<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_provider_id')->constrained('email_providers')->cascadeOnDelete();
            $table->string('email');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('daily_quota')->default(10000);
            $table->timestamps();

            $table->unique(['email_provider_id', 'email']);
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('from_address')->nullable()->after('to');
            $table->index(['from_address', 'status', 'created_at'], 'email_logs_from_status_created_index');
        });

        $now = now();
        DB::table('email_providers')
            ->whereNotNull('from_address')
            ->where('from_address', '!=', '')
            ->orderBy('id')
            ->get(['id', 'from_address'])
            ->each(function ($row) use ($now): void {
                DB::table('provider_mailboxes')->insert([
                    'email_provider_id' => $row->id,
                    'email' => $row->from_address,
                    'is_active' => true,
                    'daily_quota' => 10000,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex('email_logs_from_status_created_index');
            $table->dropColumn('from_address');
        });

        Schema::dropIfExists('provider_mailboxes');
    }
};
