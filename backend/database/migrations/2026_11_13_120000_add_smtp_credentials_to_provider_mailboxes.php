<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_mailboxes', function (Blueprint $table) {
            $table->string('smtp_username')->nullable()->after('email');
            $table->text('smtp_password')->nullable()->after('smtp_username');
        });
    }

    public function down(): void
    {
        Schema::table('provider_mailboxes', function (Blueprint $table) {
            $table->dropColumn(['smtp_username', 'smtp_password']);
        });
    }
};
