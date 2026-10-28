<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_labs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            // The lab assistant keeps this lab's assets up to date.
            $table->foreignId('assistant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('it_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('lab_id')->constrained('it_labs')->restrictOnDelete();
            // PC-26-0001: printed as a label / QR code.
            $table->string('asset_tag', 40);
            // computer | monitor | keyboard | mouse | cpu | ups | smartboard | projector | printer | other
            $table->string('asset_type', 20);
            $table->string('workstation_no', 30)->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_no', 150)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 12, 2)->nullable();
            $table->date('warranty_expiry')->nullable();
            $table->string('os_license', 150)->nullable();
            $table->date('license_expiry')->nullable();
            // working | faulty | under_repair | scrapped
            $table->string('status', 15)->default('working');
            // on | off (smart boards and projectors mostly)
            $table->string('power_status', 3)->default('off');
            $table->timestamp('power_updated_at')->nullable();
            $table->foreignId('power_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'asset_tag']);
            $table->index(['lab_id', 'status']);
        });

        Schema::create('it_asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('it_assets')->cascadeOnDelete();
            $table->foreignId('from_lab_id')->nullable()->constrained('it_labs')->nullOnDelete();
            $table->foreignId('to_lab_id')->constrained('it_labs')->restrictOnDelete();
            $table->string('remarks', 255)->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('it_repair_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('it_assets')->restrictOnDelete();
            $table->foreignId('lab_id')->constrained('it_labs')->restrictOnDelete();
            $table->text('issue_description');
            // pending | in_progress | resolved
            $table->string('status', 12)->default('pending');
            $table->text('resolution_notes')->nullable();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['it_repair_tickets', 'it_asset_transfers', 'it_assets', 'it_labs'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
