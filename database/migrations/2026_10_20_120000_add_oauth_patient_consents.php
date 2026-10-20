<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_patient_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consent_id')->constrained('oauth_client_consents', indexName: 'oauth_patient_parent_fk')->cascadeOnDelete();
            // Native restores may replace routing ids; consent follows the opaque identity.
            $table->uuid('patient_public_id');
            $table->unsignedBigInteger('client_consent_version');
            $table->unsignedBigInteger('version')->default(1);
            $table->dateTime('granted_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['consent_id', 'patient_public_id'], 'oauth_patient_consent_unique');
        });
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->uuid('patient_public_id')->nullable();
            $table->unsignedBigInteger('patient_consent_version')->nullable();
        });
        Schema::table('oauth_token_families', function (Blueprint $table): void {
            $table->uuid('patient_public_id')->nullable();
        });
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }

    public function down(): void
    {
        Schema::table('oauth_token_families', fn (Blueprint $table) => $table->dropColumn('patient_public_id'));
        Schema::table('oauth_auth_codes', fn (Blueprint $table) => $table->dropColumn(['patient_public_id', 'patient_consent_version']));
        Schema::dropIfExists('oauth_patient_consents');
    }
};
