<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11a: Web Push devices, each person's language and delivery choices, and delivery marks. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            // sha256 of the endpoint: endpoints are long URLs, too long for a unique index.
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 120);
            $table->string('auth', 40);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('mr');
            // {"push": {group: bool}, "email": {group: bool}} — only choices that differ from the defaults.
            $table->json('notification_settings')->nullable();
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            // Set once the delivery run has handled the notification (sent, or nothing to send).
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->index(['pushed_at', 'created_at']);
            $table->index(['emailed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex(['pushed_at', 'created_at']);
            $table->dropIndex(['emailed_at', 'created_at']);
            $table->dropColumn(['pushed_at', 'emailed_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'notification_settings']);
        });
        Schema::dropIfExists('push_subscriptions');
    }
};
