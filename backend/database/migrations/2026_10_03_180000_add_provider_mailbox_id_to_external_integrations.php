<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_integrations', function (Blueprint $table) {
            $table->foreignId('provider_mailbox_id')
                ->nullable()
                ->after('email_provider_id')
                ->constrained('provider_mailboxes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('external_integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_mailbox_id');
        });
    }
};
