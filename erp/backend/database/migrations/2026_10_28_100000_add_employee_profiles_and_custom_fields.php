<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Old-app parity for staff records (docs/erp/PARITY.md): HR profile fields,
 * private data in its own table (Aadhaar, bank, statutory numbers, salary),
 * documents, family, achievements, organization-defined custom fields, and
 * the forced password change used for imported or admin-created accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Unguessable reference printed as a QR code on the ID card.
            $table->ulid('public_token')->nullable()->unique()->after('id');
            $table->string('photo_path')->nullable()->after('email');
            $table->string('secondary_designation')->nullable()->after('designation');
            $table->string('employment_type', 30)->nullable();
            $table->string('staff_group', 50)->nullable();
            $table->string('teaching_mode', 30)->nullable();
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->text('local_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('religion', 50)->nullable();
            $table->string('caste', 100)->nullable();
            $table->string('caste_validity_no', 50)->nullable();
            $table->boolean('is_disabled')->default(false);
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_relation', 50)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->date('left_date')->nullable()->after('joining_date');
            $table->json('custom')->nullable();
        });
        DB::table('employees')->whereNull('public_token')->orderBy('id')->each(function ($row) {
            DB::table('employees')->where('id', $row->id)->update(['public_token' => (string) Str::ulid()]);
        });

        // Kept apart from `employees` so ordinary queries and resources never carry these values.
        Schema::create('employee_private', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('aadhaar_number')->nullable();       // encrypted
            $table->string('ration_card', 50)->nullable();
            $table->text('bank_account_no')->nullable();      // encrypted
            $table->string('ifsc_code', 20)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->boolean('pf_applicable')->default(false);
            $table->string('uan_no', 30)->nullable();
            $table->boolean('esic_applicable')->default(false);
            $table->string('esic_no', 30)->nullable();
            $table->boolean('pt_applicable')->default(false);
            $table->decimal('monthly_salary', 12, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->string('title')->nullable();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'document_type']);
        });

        Schema::create('employee_family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('relation', 50);
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('lives_on_campus')->default(false);
            $table->timestamps();
        });

        Schema::create('employee_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('achieved_on')->nullable();
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Fields an organization adds to its own forms (employee, student, daily work entry).
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 30);
            $table->string('key', 60);
            $table->string('label');
            // text | textarea | number | date | select | checkbox
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'entity', 'key']);
        });

        Schema::table('students', fn (Blueprint $table) => $table->json('custom')->nullable());
        Schema::table('work_entries', fn (Blueprint $table) => $table->json('custom')->nullable());
        Schema::table('users', fn (Blueprint $table) => $table->boolean('must_change_password')->default(false)->after('is_active'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('must_change_password'));
        Schema::table('work_entries', fn (Blueprint $table) => $table->dropColumn('custom'));
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn('custom'));
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('employee_achievements');
        Schema::dropIfExists('employee_family_members');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_private');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn([
                'public_token', 'photo_path', 'secondary_designation', 'employment_type', 'staff_group', 'teaching_mode',
                'father_name', 'mother_name', 'gender', 'date_of_birth', 'marital_status', 'blood_group', 'whatsapp',
                'local_address', 'permanent_address', 'religion', 'caste', 'caste_validity_no', 'is_disabled',
                'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone', 'left_date', 'custom',
            ]);
        });
    }
};
