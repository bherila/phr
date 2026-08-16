<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_api_audits', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('request_id')->unique();
            // Deliberately no foreign key: account deletion anonymizes the actor while
            // retaining security evidence. No request URI, parameters, body, response,
            // exception text, IP address, or user agent is ever stored in this table.
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->uuid('oauth_client_id')->nullable()->index();
            $table->char('oauth_token_hash', 64)->nullable()->index();
            $table->string('event', 40);
            $table->string('route_name', 100);
            $table->string('http_method', 10);
            $table->unsignedSmallInteger('response_status');
            $table->unsignedInteger('duration_ms');
            $table->dateTime('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_api_audits');
    }
};
