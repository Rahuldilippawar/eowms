<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free counters (file numbers, outward / inward numbers), taken under a row lock.
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('period', 10);
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'key', 'period']);
        });

        Schema::create('letter_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // Generic approval chains; `module` says which records use them (letters now, others later).
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('module', 30)->default('letters');
            $table->string('name', 150);
            $table->foreignId('letter_category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'module', 'name']);
        });

        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('name', 150);
            // origin_department_head | department_head | user
            $table->string('approver_type', 30);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->unique(['approval_workflow_id', 'step_order']);
        });

        Schema::create('letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // The creator's department: outward numbers and scoped views follow it.
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_number', 30);
            $table->string('outward_no', 60)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->foreignId('letter_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('priority', 10)->default('normal');
            // draft → in_progress ⇄ returned → completed
            $table->string('status', 20)->default('draft');
            $table->foreignId('approval_workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('current_step_order')->default(0);
            $table->foreignId('current_holder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('holder_since')->nullable();
            $table->timestamp('holder_seen_at')->nullable();
            // Budget request (deducted from the department budget in the finance phase).
            $table->boolean('is_budget_expense')->default(false);
            $table->string('budget_category', 30)->nullable();
            $table->decimal('requested_amount', 12, 2)->nullable();
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->string('dispatch_mode', 20)->nullable();
            $table->string('dispatch_tracking_no', 100)->nullable();
            $table->date('dispatch_date')->nullable();
            // When the outward number was given (first submission).
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'file_number']);
            $table->unique(['organization_id', 'outward_no']);
            $table->index(['organization_id', 'status']);
            $table->index('current_holder_id');
            $table->index('created_by');
        });

        Schema::create('letter_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained()->cascadeOnDelete();
            // created | submitted | forwarded | returned | remark | reassigned | dispatched | attached | completed
            $table->string('type', 20);
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('step_order')->nullable();
            $table->string('remark', 2000)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['letter_id', 'id']);
            $table->index(['to_user_id', 'letter_id']);
            $table->index(['from_user_id', 'letter_id']);
        });

        Schema::create('letter_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            // Part of what a signature seals.
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // Each department a file reaches gets its own inward number, once.
        Schema::create('letter_inward_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('inward_no', 60);
            $table->unsignedSmallInteger('fy_start_year');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['letter_id', 'department_id']);
            $table->unique(['department_id', 'inward_no']);
        });

        Schema::create('letter_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('signer_name');
            $table->string('signer_designation')->nullable();
            $table->string('action', 20)->default('complete');
            $table->string('method', 20);
            $table->string('verification_code', 16)->unique();
            // SHA-256 of the letter's content, the signer and the previous signature's hash.
            $table->char('content_hash', 64);
            $table->char('previous_hash', 64)->nullable();
            $table->timestamp('signed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_signatures');
        Schema::dropIfExists('letter_inward_numbers');
        Schema::dropIfExists('letter_attachments');
        Schema::dropIfExists('letter_movements');
        Schema::dropIfExists('letters');
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('approval_workflows');
        Schema::dropIfExists('letter_categories');
        Schema::dropIfExists('number_sequences');
    }
};
