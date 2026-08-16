<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_token_families', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->uuid('client_id')->index();
            $table->unsignedBigInteger('oauth_security_version')->nullable();
            $table->boolean('revoked')->default(false)->index();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_token_families');
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
