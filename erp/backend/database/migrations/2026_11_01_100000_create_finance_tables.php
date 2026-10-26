<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a department may spend in a financial year (April–March; the start year identifies it).
        Schema::create('department_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fy_start_year');
            $table->decimal('allocated_amount', 14, 2)->default(0);
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'fy_start_year']);
        });

        // Every spend against a department budget; one row per source record (kept in step with it).
        Schema::create('budget_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fy_start_year');
            $table->decimal('amount', 14, 2);
            // purchase | maintenance | fuel | renewal | other
            $table->string('category', 20);
            // material_purchase | letter | renewal_payment | … (later modules)
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('description')->nullable();
            $table->date('spend_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['department_id', 'fy_start_year']);
        });

        Schema::create('material_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('material_name');
            $table->string('vendor_name');
            $table->string('vendor_contact', 100)->nullable();
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 30)->nullable();
            $table->decimal('rate', 12, 2);
            $table->decimal('base_amount', 14, 2);
            $table->decimal('transport_cost', 12, 2)->default(0);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->decimal('gst_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->date('purchase_date');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'purchase_date']);
            $table->index(['department_id', 'purchase_date']);
        });

        // Money actually paid against a bill (a purchase now, renewals next).
        Schema::create('finance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->decimal('amount', 14, 2);
            $table->date('payment_date');
            // advance | partial | final
            $table->string('payment_type', 10)->default('partial');
            $table->string('payment_mode', 50)->nullable();
            // Monthly bills: the month this payment covers (first of the month).
            $table->date('for_month')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['department_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payments');
        Schema::dropIfExists('material_purchases');
        Schema::dropIfExists('budget_transactions');
        Schema::dropIfExists('department_budgets');
    }
};
