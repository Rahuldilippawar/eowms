<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11c: the About page, TV display links and the first-sign-in tour. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('about_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // deity | sadguru | guide (the old app's sections) or contributor
            $table->string('kind', 20);
            $table->string('title', 150);
            $table->string('subtitle', 200)->nullable();
            $table->text('body')->nullable();
            $table->string('photo_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'kind', 'sort_order']);
        });

        Schema::create('tv_displays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // sha256 of the secret in the screen's link; the secret itself is shown once.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarding_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_seen_at');
        });
        Schema::dropIfExists('tv_displays');
        Schema::dropIfExists('about_entries');
    }
};
