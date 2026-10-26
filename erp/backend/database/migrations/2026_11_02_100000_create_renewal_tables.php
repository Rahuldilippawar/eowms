<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Licences, approvals, insurance, AMCs… anything with an expiry date that must be renewed.
        Schema::create('renewal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('item_type', 100);
            $table->string('regulatory_body', 40)->nullable();
            $table->string('item_name');
            $table->string('reference_no', 100)->nullable();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date');
            $table->decimal('renewal_cost', 12, 2)->nullable();
            // Monthly bills (rent, AMC instalments): one payment due per month from the start month.
            $table->boolean('is_recurring_monthly')->default(false);
            $table->decimal('monthly_amount', 12, 2)->nullable();
            $table->date('recurring_start_month')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('vendor_contact', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'expiry_date']);
            $table->index(['department_id', 'expiry_date']);
        });

        Schema::create('renewal_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('renewal_item_id')->constrained()->cascadeOnDelete();
            $table->date('old_expiry_date');
            $table->date('old_issued_date')->nullable();
            $table->decimal('old_renewal_cost', 12, 2)->nullable();
            $table->string('old_vendor_name')->nullable();
            $table->string('old_reference_no', 100)->nullable();
            $table->date('new_expiry_date');
            $table->foreignId('renewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('renewal_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('renewal_item_id')->constrained()->cascadeOnDelete();
            // The cycle (expiry date) the document belongs to, e.g. that year's certificate.
            $table->date('cycle_expiry_date')->nullable();
            $table->string('title')->nullable();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // One reminder per item, milestone and expiry date (a renewal starts a new set).
        Schema::create('renewal_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('renewal_item_id')->constrained()->cascadeOnDelete();
            $table->string('milestone', 10);
            $table->date('expiry_date');
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['renewal_item_id', 'milestone', 'expiry_date']);
        });

        // Renewal payments belong to the cycle (expiry date) they paid for.
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->date('cycle_expiry_date')->nullable()->after('for_month');
        });
    }

    public function down(): void
    {
        Schema::table('finance_payments', fn (Blueprint $table) => $table->dropColumn('cycle_expiry_date'));
        Schema::dropIfExists('renewal_reminders');
        Schema::dropIfExists('renewal_attachments');
        Schema::dropIfExists('renewal_history');
        Schema::dropIfExists('renewal_items');
    }
};
