<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff housing uses the maintenance buildings and residential locations (Phase 8a).
        Schema::create('residency_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('filename')->nullable();
            $table->unsignedInteger('buildings_created')->default(0);
            $table->unsignedInteger('rooms_created')->default(0);
            $table->unsignedInteger('occupants_created')->default(0);
            // completed | rolled_back
            $table->string('status', 15)->default('completed');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });
        foreach (['buildings', 'maintenance_locations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('residency_import_batch_id')->nullable()->constrained('residency_import_batches')->nullOnDelete();
            });
        }

        Schema::create('residency_occupants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('maintenance_locations')->restrictOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained('residency_import_batches')->nullOnDelete();
            // staff | family
            $table->string('occupant_type', 10)->default('staff');
            // The staff member's account (null until linked, e.g. after an import).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // A family member lives with this staff occupant.
            $table->foreignId('family_of_id')->nullable()->constrained('residency_occupants')->nullOnDelete();
            $table->string('name');
            $table->string('relation', 100)->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->date('moved_in_date')->nullable();
            // active | vacated
            $table->string('status', 10)->default('active');
            $table->date('vacated_date')->nullable();
            $table->text('vacated_remark')->nullable();
            $table->foreignId('vacated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->index(['organization_id', 'user_id', 'status']);
        });

        Schema::create('residency_room_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('maintenance_locations')->cascadeOnDelete();
            $table->string('item_name');
            // institution | personal
            $table->string('ownership', 12)->default('institution');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('residency_allotment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('preferred_building_id')->nullable()->constrained('buildings')->nullOnDelete();
            $table->unsignedTinyInteger('family_size')->default(1);
            $table->text('reason')->nullable();
            // pending | waitlisted | approved | rejected | cancelled
            $table->string('status', 12)->default('pending');
            $table->foreignId('allotted_location_id')->nullable()->constrained('maintenance_locations')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('residency_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('occupant_id')->constrained('residency_occupants')->cascadeOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('maintenance_locations')->nullOnDelete();
            $table->foreignId('to_location_id')->constrained('maintenance_locations')->restrictOnDelete();
            $table->date('transfer_date');
            $table->string('reason')->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('residency_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('maintenance_locations')->cascadeOnDelete();
            $table->date('inspection_date');
            // excellent | good | fair | poor
            $table->string('condition_rating', 10);
            $table->text('remarks')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A month's rent / electricity / water for a room, split equally among its staff occupants.
        Schema::create('residency_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('maintenance_locations')->restrictOnDelete();
            $table->char('billing_month', 7);
            $table->decimal('room_rent', 12, 2)->default(0);
            $table->decimal('electricity_bill', 12, 2)->default(0);
            $table->decimal('water_bill', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->unsignedTinyInteger('billing_unit_count')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['location_id', 'billing_month']);
        });

        Schema::create('residency_bill_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('residency_bills')->cascadeOnDelete();
            $table->foreignId('occupant_id')->constrained('residency_occupants')->restrictOnDelete();
            $table->decimal('amount_due', 12, 2);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('marked_paid_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('residency_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('maintenance_locations')->restrictOnDelete();
            $table->foreignId('host_occupant_id')->nullable()->constrained('residency_occupants')->nullOnDelete();
            $table->string('guest_name');
            $table->string('guest_contact', 50)->nullable();
            $table->string('relation', 100)->nullable();
            $table->string('purpose')->nullable();
            $table->date('arrival_date');
            $table->date('expected_departure_date')->nullable();
            $table->date('actual_departure_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['residency_guests', 'residency_bill_shares', 'residency_bills', 'residency_inspections', 'residency_transfers', 'residency_allotment_requests',
            'residency_room_items', 'residency_occupants'] as $name) {
            Schema::dropIfExists($name);
        }
        foreach (['buildings', 'maintenance_locations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('residency_import_batch_id'));
        }
        Schema::dropIfExists('residency_import_batches');
    }
};
