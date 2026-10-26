<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            // The department that runs it (its scope decides who sees the hostel).
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('name');
            // boys | girls | mixed
            $table->string('gender', 10)->default('mixed');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // Wardens and other staff of a hostel: they look after its residents without wider permissions.
        Schema::create('hostel_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30)->default('warden');

            $table->unique(['hostel_id', 'user_id']);
        });

        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $table->string('room_number', 50);
            $table->unsignedTinyInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['hostel_id', 'room_number']);
        });

        // Residents: often pupils of other schools, so a record of their own (optionally linked to a student).
        Schema::create('hostel_residents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('school_name')->nullable();
            $table->string('class_grade', 50)->nullable();
            $table->string('district', 100)->nullable();
            // male | female
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('admission_date')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['hostel_id', 'is_active']);
        });

        Schema::create('hostel_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('hostel_rooms')->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained('hostel_residents')->cascadeOnDelete();
            $table->string('bed_label', 20)->nullable();
            $table->date('allocated_on');
            $table->date('vacated_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'vacated_on']);
            $table->index(['resident_id', 'vacated_on']);
        });

        // Three roll calls a day, as in the old app.
        Schema::create('hostel_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained('hostel_residents')->cascadeOnDelete();
            $table->date('attendance_date');
            // morning | afternoon | evening
            $table->string('session', 10);
            // present | absent | on_leave
            $table->string('status', 10)->default('present');
            $table->string('remark')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['resident_id', 'attendance_date', 'session']);
            $table->index(['hostel_id', 'attendance_date']);
        });

        // Out-passes: who went home or out, until when, and when they came back.
        Schema::create('hostel_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('hostel_residents')->cascadeOnDelete();
            $table->date('leave_date');
            $table->date('expected_return_date')->nullable();
            $table->date('actual_return_date')->nullable();
            $table->string('reason')->nullable();
            $table->string('escort_name')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['resident_id', 'actual_return_date']);
        });

        Schema::create('hostel_health_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->unique()->constrained('hostel_residents')->cascadeOnDelete();
            $table->string('blood_group', 10)->nullable();
            $table->text('allergies')->nullable();
            $table->text('regular_medication')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->text('medical_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('hostel_health_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('hostel_residents')->cascadeOnDelete();
            $table->date('incident_date');
            $table->text('description');
            $table->text('action_taken')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hostel_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('hostel_residents')->cascadeOnDelete();
            $table->string('fee_period', 50);
            $table->decimal('amount_due', 12, 2);
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hostel_fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_id')->constrained('hostel_fees')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            // cash | online | cheque | other
            $table->string('payment_mode', 10)->default('cash');
            $table->date('paid_on');
            $table->string('remarks')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A resident who left: kept for the alumni list and contact.
        Schema::create('hostel_alumni', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->unique()->constrained('hostel_residents')->cascadeOnDelete();
            $table->date('departure_date');
            $table->string('departure_reason')->nullable();
            $table->string('current_status')->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['hostel_alumni', 'hostel_fee_payments', 'hostel_fees', 'hostel_health_incidents', 'hostel_health_profiles', 'hostel_leaves', 'hostel_attendance',
            'hostel_allocations', 'hostel_residents', 'hostel_rooms', 'hostel_staff', 'hostels'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
