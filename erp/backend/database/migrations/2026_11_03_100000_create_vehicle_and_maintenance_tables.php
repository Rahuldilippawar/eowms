<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('vehicle_number', 50);
            $table->string('vehicle_type', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->date('registration_date')->nullable();
            $table->string('chassis_no', 100)->nullable();
            $table->string('engine_no', 100)->nullable();
            $table->string('vehicle_class', 100)->nullable();
            $table->unsignedSmallInteger('passenger_capacity')->nullable();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            // petrol | diesel | cng | electric | other
            $table->string('fuel_type', 10)->default('diesel');
            $table->decimal('current_odometer', 10, 1)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'vehicle_number']);
            $table->index(['department_id', 'is_active']);
        });

        // Permit, PUC, insurance… of a vehicle are renewal items, so they share the renewals register and reminders.
        Schema::table('renewal_items', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('responsible_user_id')->constrained()->cascadeOnDelete();
            $table->string('renewal_field', 20)->nullable()->after('vehicle_id');
            $table->unique(['vehicle_id', 'renewal_field']);
        });

        Schema::create('vehicle_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('trip_date');
            $table->string('from_location');
            $table->string('to_location');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('start_odometer', 10, 1);
            $table->decimal('end_odometer', 10, 1);
            $table->decimal('distance_km', 10, 1);
            $table->string('purpose')->nullable();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'trip_date']);
        });

        Schema::create('vehicle_fuel_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('fill_date');
            $table->decimal('odometer_reading', 10, 1);
            $table->decimal('liters', 8, 2);
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('fuel_station')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'fill_date']);
        });

        Schema::create('vehicle_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('service_date');
            $table->string('type', 100);
            $table->text('description')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('odometer_reading', 10, 1)->nullable();
            // When the next service is due, by date and / or odometer.
            $table->date('next_due_date')->nullable();
            $table->decimal('next_due_km', 10, 1)->nullable();
            $table->string('vendor')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'service_date']);
        });

        // Whoever logged an entry asks for it to be fixed or removed; a vehicle manager decides.
        Schema::create('vehicle_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            // trip | fuel | service
            $table->string('entry_type', 10);
            $table->unsignedBigInteger('entry_id');
            // edit | delete
            $table->string('request_type', 10);
            $table->text('reason');
            // pending | approved | rejected
            $table->string('status', 10)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        // One reminder per service record and milestone (d7…d0, o1, o8…, km); the next service starts a new set.
        Schema::create('vehicle_service_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_service_id')->constrained()->cascadeOnDelete();
            $table->string('milestone', 10);
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['vehicle_service_id', 'milestone']);
        });

        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // Rooms, halls, labs… anywhere work is done. Residential ones are used by residency later.
        Schema::create('maintenance_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('floor')->default(0);
            $table->string('name');
            $table->string('location_code', 50);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('is_residential')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'location_code']);
        });

        Schema::create('maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('maintenance_locations')->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('item_description');
            // installed | repaired | replaced | inspected
            $table->string('work_type', 10)->default('repaired');
            $table->date('work_date');
            $table->decimal('cost', 12, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['location_id', 'work_date']);
            $table->index(['department_id', 'work_date']);
        });

        // Departments that keep a maintenance register (maintenance, plumbing, electrical, workshop…).
        Schema::table('departments', function (Blueprint $table) {
            $table->boolean('has_maintenance_register')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('has_maintenance_register'));
        Schema::dropIfExists('maintenance_logs');
        Schema::dropIfExists('maintenance_locations');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('vehicle_service_reminders');
        Schema::dropIfExists('vehicle_correction_requests');
        Schema::dropIfExists('vehicle_services');
        Schema::dropIfExists('vehicle_fuel_logs');
        Schema::dropIfExists('vehicle_trips');
        Schema::table('renewal_items', function (Blueprint $table) {
            $table->dropUnique(['vehicle_id', 'renewal_field']);
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn('renewal_field');
        });
        Schema::dropIfExists('vehicles');
    }
};
