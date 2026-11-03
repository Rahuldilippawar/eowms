<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11d: which old-app row became which ERP row, and each import / dry run with its report. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('target_table', 64);
            $table->unsignedBigInteger('target_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['source_table', 'source_id']);
            $table->index(['target_table', 'target_id']);
        });

        Schema::create('legacy_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 10); // dry_run | import
            $table->string('status', 10); // done | failed
            $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->string('safety_backup')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_runs');
        Schema::dropIfExists('legacy_id_map');
    }
};
