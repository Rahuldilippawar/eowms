<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Store: items and a stock ledger (every movement is a row; the balance is their sum).
        Schema::create('store_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // The department that mainly uses it; null = shared.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->string('category', 100)->nullable();
            $table->string('unit', 30);
            $table->decimal('reorder_level', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'item_name']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained('store_items')->restrictOnDelete();
            // receive | issue | adjust
            $table->string('movement_type', 10);
            // Signed: + into the store, - out of it.
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            // Issued to (or received for) a department.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('issued_to')->nullable();
            // goods_receipt_line | null (by hand)
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('movement_date');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['item_id', 'movement_date']);
            $table->index(['source_type', 'source_id']);
        });

        // Material used in a day's work (the old daily-work "materials used").
        Schema::create('work_entry_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('store_items')->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->unique(['work_entry_id', 'item_id']);
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 20)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('purchase_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('reference_no', 100)->nullable();
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            // received | accepted | rejected
            $table->string('status', 10)->default('received');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'department_id', 'status']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('purchase_quotations')->nullOnDelete();
            // PO/26-27/001, given when issued.
            $table->string('po_number', 40)->nullable();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            // draft | issued | partly_received | received | cancelled
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'po_number']);
            $table->index(['organization_id', 'status']);
        });

        // Lines of a quotation or an order.
        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            // purchase_quotation | purchase_order
            $table->string('parent_type', 20);
            $table->unsignedBigInteger('parent_id');
            $table->foreignId('item_id')->nullable()->constrained('store_items')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 30)->nullable();
            $table->decimal('rate', 12, 2);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->decimal('amount', 14, 2);
            $table->decimal('received_quantity', 12, 2)->default(0);

            $table->index(['parent_type', 'parent_id']);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->string('grn_number', 40);
            $table->date('received_date');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'grn_number']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_line_id')->constrained('purchase_lines')->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
        });

        // The vendor's bill against an order; charged to the department budget, paid through finance_payments.
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number', 100);
            $table->date('invoice_date');
            // Goods (with GST) and other charges (transport…).
            $table->decimal('goods_amount', 14, 2);
            $table->decimal('other_charges', 12, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['vendor_id', 'invoice_number']);
        });
    }

    public function down(): void
    {
        foreach (['purchase_invoices', 'goods_receipt_lines', 'goods_receipts', 'purchase_lines', 'purchase_orders', 'purchase_quotations', 'vendors',
            'work_entry_materials', 'stock_movements', 'store_items'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
