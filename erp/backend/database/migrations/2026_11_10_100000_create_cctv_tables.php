<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cctv_dvrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            // How many cameras the recorder takes.
            $table->unsignedSmallInteger('channel_capacity')->nullable();
            $table->decimal('storage_tb', 6, 2)->nullable();
            $table->date('installed_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('cctv_cameras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('dvr_id')->constrained('cctv_dvrs')->restrictOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            // dome | bullet | ptz | other
            $table->string('camera_type', 10)->default('dome');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['dvr_id', 'name']);
        });

        // A day's check of a recorder: storage, footage kept, backup, and each camera.
        Schema::create('cctv_checkups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('dvr_id')->constrained('cctv_dvrs')->restrictOnDelete();
            $table->date('checkup_date');
            $table->decimal('storage_used_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('footage_days')->nullable();
            $table->boolean('backup_done')->default(false);
            $table->string('backup_location')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('checked_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['dvr_id', 'checkup_date']);
        });

        Schema::create('cctv_camera_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkup_id')->constrained('cctv_checkups')->cascadeOnDelete();
            $table->foreignId('camera_id')->constrained('cctv_cameras')->cascadeOnDelete();
            // working | not_working | needs_attention
            $table->string('status', 16)->default('working');
            $table->string('remarks')->nullable();

            $table->unique(['checkup_id', 'camera_id']);
        });
    }

    public function down(): void
    {
        foreach (['cctv_camera_statuses', 'cctv_checkups', 'cctv_cameras', 'cctv_dvrs'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
