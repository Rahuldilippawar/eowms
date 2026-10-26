<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work requests (tickets): staff raise them in the app, anyone through the public form.
        Schema::create('work_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('request_code', 30);
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            // The department that does the work.
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            // general | it | maintenance | housekeeping | other
            $table->string('category', 20)->default('general');
            $table->string('sub_category', 30)->nullable();
            $table->string('title')->nullable();
            $table->string('location')->nullable();
            // low | normal | high | urgent — decides the SLA
            $table->string('priority', 10)->default('normal');
            $table->timestamp('due_at')->nullable();
            // staff | public
            $table->string('source', 10)->default('staff');
            $table->string('requester_name');
            $table->string('requester_contact', 100)->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requesting_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('work_description');
            $table->decimal('cost_incurred', 12, 2)->nullable();
            $table->text('completion_remarks')->nullable();
            // pending | in_progress | completed | closed | cancelled
            $table->string('status', 15)->default('pending');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('escalated_at')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'request_code']);
            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['department_id', 'status']);
            $table->index(['requested_by_user_id', 'status']);
        });

        Schema::create('work_request_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_request_id')->constrained()->cascadeOnDelete();
            // created | status_changed | assigned | reopened | closed | cancelled | escalated
            $table->string('action_type', 20);
            $table->string('old_status', 15)->nullable();
            $table->string('new_status', 15)->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('work_request_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('comment');
            $table->timestamp('created_at')->useCurrent();
        });

        // Who on the staff has opened the request (the public tracker shows that it was seen).
        Schema::create('work_request_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_viewed_at')->useCurrent();
            $table->timestamp('last_viewed_at')->useCurrent();

            $table->unique(['work_request_id', 'user_id']);
        });

        Schema::create('grievances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Null for an anonymous grievance: only a keyed hash of the person is kept.
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('submitter_hash', 64)->nullable()->index();
            $table->boolean('is_anonymous')->default(false);
            $table->string('category', 100)->nullable();
            $table->text('description');
            // open | in_progress | resolved | closed
            $table->string('status', 15)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->date('sla_deadline')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('grievance_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grievance_id')->constrained()->cascadeOnDelete();
            // Null on an anonymous submitter's comment.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // submitter | handler
            $table->string('author_role', 10);
            $table->text('comment');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_comments');
        Schema::dropIfExists('grievances');
        Schema::dropIfExists('work_request_views');
        Schema::dropIfExists('work_request_comments');
        Schema::dropIfExists('work_request_actions');
        Schema::dropIfExists('work_requests');
    }
};
