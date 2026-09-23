<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_mailboxes', function (Blueprint $table) {
            $table->unsignedInteger('weight')->default(1)->after('daily_quota');
        });
    }

    public function down(): void
    {
        Schema::table('provider_mailboxes', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
