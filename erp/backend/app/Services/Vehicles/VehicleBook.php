<?php

namespace App\Services\Vehicles;

use App\Models\Department;
use App\Models\RenewalItem;
use App\Models\Vehicle;
use App\Models\VehicleFuelLog;
use App\Models\VehicleService;
use App\Models\VehicleTrip;
use App\Services\Finance\BudgetLedger;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Who sees and logs for which vehicle, the vehicle's documents as renewal
 * items, and the budget charges of fuel and services (erp/docs/VEHICLES.md).
 */
final class VehicleBook
{
    /** Labels of the renewal items created for a vehicle's documents. */
    public const RENEWAL_LABELS = [
        'permit' => 'Permit', 'puc' => 'PUC certificate', 'insurance' => 'Insurance', 'fitness' => 'Fitness certificate',
        'road_tax' => 'Road tax', 'green_tax' => 'Green tax', 'mv_tax' => 'MV tax',
    ];

    public const ENTRY_MODELS = ['trip' => VehicleTrip::class, 'fuel' => VehicleFuelLog::class, 'service' => VehicleService::class];

    public function __construct(private readonly BudgetLedger $ledger) {}

    /** Vehicles of the departments in the viewer's scope, plus the ones they drive. @return Builder<Vehicle> */
    public function visible(): Builder
    {
        $ctx = AccessContext::current();
        $ids = ScopeDepartments::ids($ctx->can('vehicles.view') ? $ctx->scope('vehicles.view') : null);
        $me = $ctx->user()->id;

        return Vehicle::query()->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('department_id', $ids ?: [0])->orWhere('driver_user_id', $me)));
    }

    public function canManage(Vehicle $vehicle): bool
    {
        return ScopeDepartments::reaches(AccessContext::current()->scope('vehicles.manage'), $vehicle->department_id);
    }

    /** The driver logs for their own vehicle; others need the log (or manage) permission for its department. */
    public function canLog(Vehicle $vehicle): bool
    {
        $ctx = AccessContext::current();

        return $vehicle->driver_user_id === $ctx->user()->id
            || ScopeDepartments::reaches($ctx->scope('vehicles.log'), $vehicle->department_id)
            || $this->canManage($vehicle);
    }

    /**
     * One renewal item per document date: created, moved to the new date, or
     * removed when the date is cleared (as the old app did).
     *
     * @param  array<string, string|null>  $dates  field => Y-m-d
     */
    public function syncRenewals(Vehicle $vehicle, array $dates, int $userId): void
    {
        foreach (Vehicle::RENEWAL_FIELDS as $field) {
            if (! array_key_exists($field, $dates)) {
                continue;
            }
            $item = RenewalItem::query()->where('vehicle_id', $vehicle->id)->where('renewal_field', $field)->first();
            $date = $dates[$field];
            if (! $date) {
                $item?->delete();

                continue;
            }
            $attributes = [
                'institution_id' => $vehicle->institution_id, 'department_id' => $vehicle->department_id, 'item_type' => 'Vehicle renewal',
                'regulatory_body' => 'RTO/Transport', 'item_name' => "Vehicle {$vehicle->vehicle_number} — ".self::RENEWAL_LABELS[$field], 'reference_no' => $vehicle->vehicle_number,
                'expiry_date' => $date,
            ];
            if ($item) {
                $item->fill($attributes)->save();
            } else {
                RenewalItem::create($attributes + ['vehicle_id' => $vehicle->id, 'renewal_field' => $field, 'created_by' => $userId]);
            }
        }
    }

    /** The odometer only moves forward. */
    public function bumpOdometer(Vehicle $vehicle, float $reading): void
    {
        Vehicle::query()->whereKey($vehicle->id)->where('current_odometer', '<', $reading)->update(['current_odometer' => $reading]);
    }

    /** Fuel and service costs are department spends, in the financial year of the entry's date. */
    public function charge(Vehicle $vehicle, Model $entry, ?int $userId): void
    {
        $department = Department::query()->findOrFail($vehicle->department_id);
        if ($entry instanceof VehicleFuelLog) {
            $this->ledger->record($department, (float) $entry->cost, 'fuel', 'vehicle_fuel', $entry->id,
                "{$vehicle->vehicle_number} — {$entry->liters} L", $entry->fill_date->toDateString(), $userId);
        } elseif ($entry instanceof VehicleService) {
            $this->ledger->record($department, (float) $entry->cost, 'maintenance', 'vehicle_service', $entry->id,
                "{$vehicle->vehicle_number} — {$entry->type}", $entry->service_date->toDateString(), $userId);
        }
    }

    public function uncharge(Model $entry): void
    {
        if ($entry instanceof VehicleFuelLog) {
            $this->ledger->reverse('vehicle_fuel', $entry->id);
        } elseif ($entry instanceof VehicleService) {
            $this->ledger->reverse('vehicle_service', $entry->id);
        }
    }

    /** Moving a vehicle to another department moves its open charges and documents with it. */
    public function moveDepartment(Vehicle $vehicle): void
    {
        RenewalItem::query()->where('vehicle_id', $vehicle->id)->update(['department_id' => $vehicle->department_id, 'institution_id' => $vehicle->institution_id]);
        foreach ($vehicle->fuelLogs()->get() as $fuel) {
            $this->charge($vehicle, $fuel, null);
        }
        foreach ($vehicle->services()->get() as $service) {
            $this->charge($vehicle, $service, null);
        }
    }
}
