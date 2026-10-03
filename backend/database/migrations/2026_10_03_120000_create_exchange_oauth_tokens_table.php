<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_oauth_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->unique();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->unsignedBigInteger('expires_at')->nullable();
            $table->string('auth_method', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_oauth_tokens');
    }
};
