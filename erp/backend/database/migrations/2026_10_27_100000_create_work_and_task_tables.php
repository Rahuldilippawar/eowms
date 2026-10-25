<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // TSK-<id>, set right after insert; unique per organization.
            $table->string('code', 30)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->date('due_date');
            $table->foreignId('assignee_employee_id')->constrained('employees')->restrictOnDelete();
            // Snapshot of the assignee's placement, for scoped lists and reports.
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            // open → in_progress → submitted → completed; submitted → in_progress (reopened); any open state → cancelled
            $table->string('status', 20)->default('open');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status', 'due_date']);
            $table->index(['assignee_employee_id', 'status']);
            $table->index('assigned_by');
        });

        Schema::create('task_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // created | comment | started | submitted | completed | reopened | cancelled | edited
            $table->string('type', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('body', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_id', 'id']);
        });

        Schema::create('work_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('hours', 4, 2)->nullable();
            // completed | in_progress | pending
            $table->string('status', 20)->default('completed');
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_remarks', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
            $table->index(['organization_id', 'work_date']);
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Named user_notifications so it never collides with Laravel's database notification channel.
            // The text is rendered by the app from type + data, in the reader's language.
            $table->string('type', 50);
            $table->json('data')->nullable();
            $table->string('link', 255)->nullable();
            // Set for reminders so a scheduled run never sends the same one twice.
            $table->string('dedupe_key', 120)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'organization_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('work_entries');
        Schema::dropIfExists('task_updates');
        Schema::dropIfExists('tasks');
    }
};
