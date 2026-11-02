<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11b: fingerprint / face / device-PIN keys (WebAuthn public keys; the secret stays on the device). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('credential_id', 512)->unique();
            $table->text('public_key');
            $table->smallInteger('algorithm');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('name', 100);
            $table->json('transports')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
