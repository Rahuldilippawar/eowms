<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Old-app parity for attendance and leave (docs/erp/PARITY.md, erp/docs/ATTENDANCE.md). */
return new class extends Migration
{
    public function up(): void
    {
        // institution_id null = a holiday for the whole organization.
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'institution_id', 'holiday_date']);
            $table->index(['organization_id', 'holiday_date']);
        });

        // Per-employee override of the organization's working hours and weekly offs.
        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('work_start', 5)->nullable();
            $table->string('work_end', 5)->nullable();
            $table->unsignedSmallInteger('late_grace_minutes')->nullable();
            $table->decimal('half_day_below_hours', 4, 2)->nullable();
            $table->json('weekly_offs')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Leave days given for a year; without a row the type's days_per_year applies.
        Schema::create('leave_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('days', 5, 1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::table('employees', function (Blueprint $table) {
            // User id on the attendance machine (ZKTeco "PIN").
            $table->string('biometric_pin', 20)->nullable()->after('employee_code');
            $table->unique(['organization_id', 'biometric_pin']);
        });

        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            // Serial numbers are global: one machine belongs to one organization.
            $table->string('serial_number', 50)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('biometric_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->string('pin', 20);
            $table->timestamp('punched_at');
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            // applied | unknown_pin | inactive | duplicate
            $table->string('result', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['device_id', 'pin', 'punched_at']);
            $table->index(['organization_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_punches');
        Schema::dropIfExists('biometric_devices');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'biometric_pin']);
            $table->dropColumn('biometric_pin');
        });
        Schema::dropIfExists('leave_allocations');
        Schema::dropIfExists('employee_shifts');
        Schema::dropIfExists('holidays');
    }
};
