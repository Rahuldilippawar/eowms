<?php

namespace App\Services\Residency;

use App\Models\MaintenanceLocation;
use App\Models\ResidencyBill;
use App\Models\ResidencyBillShare;
use App\Models\ResidencyOccupant;
use App\Models\ResidencyTransfer;
use App\Services\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff housing rules (erp/docs/RESIDENCY.md): who lives where, moving a
 * household between rooms, and splitting a room's monthly bill.
 */
final class ResidencyDesk
{
    /** A residential room of the organization that is in use. */
    public function room(int $id): MaintenanceLocation
    {
        return MaintenanceLocation::query()->where('is_residential', true)->where('is_active', true)->findOrFail($id);
    }

    /** A staff member lives in one place at a time. */
    public function assertNotHousedElsewhere(int $userId, ?int $exceptOccupantId = null): void
    {
        $housed = ResidencyOccupant::query()->where('user_id', $userId)->where('occupant_type', 'staff')->where('status', 'active')
            ->when($exceptOccupantId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($housed) {
            throw ValidationException::withMessages(['user_id' => [__('residency.already_housed')]]);
        }
    }

    public function assertRoomHasSpace(MaintenanceLocation $room, int $incoming): void
    {
        if ($room->capacity === null) {
            return;
        }
        $living = ResidencyOccupant::query()->where('location_id', $room->id)->where('status', 'active')->count();
        if ($living + $incoming > $room->capacity) {
            throw ValidationException::withMessages(['location_id' => [__('residency.room_full', ['room' => $room->name, 'capacity' => $room->capacity])]]);
        }
    }

    /** @param array<string, mixed> $attributes */
    public function moveIn(MaintenanceLocation $room, array $attributes, ?int $byUserId): ResidencyOccupant
    {
        return ResidencyOccupant::create($attributes + [
            'organization_id' => $room->organization_id, 'location_id' => $room->id, 'status' => 'active', 'created_by' => $byUserId,
            'moved_in_date' => $attributes['moved_in_date'] ?? now()->toDateString(),
        ]);
    }

    /** Vacating a staff occupant vacates their family with them. */
    public function vacate(ResidencyOccupant $occupant, string $date, ?string $remark, int $byUserId): void
    {
        DB::transaction(function () use ($occupant, $date, $remark, $byUserId) {
            $ids = [$occupant->id, ...$occupant->family()->where('status', 'active')->pluck('id')->all()];
            ResidencyOccupant::query()->whereIn('id', $ids)->update(['status' => 'vacated', 'vacated_date' => $date, 'vacated_remark' => $remark, 'vacated_by' => $byUserId]);
        });
    }

    /** The household (a staff occupant and their family) moves together. */
    public function transfer(ResidencyOccupant $occupant, MaintenanceLocation $to, string $date, ?string $reason, int $byUserId): void
    {
        if ($occupant->location_id === $to->id) {
            throw ValidationException::withMessages(['to_location_id' => [__('residency.same_room')]]);
        }
        DB::transaction(function () use ($occupant, $to, $date, $reason, $byUserId) {
            $household = ResidencyOccupant::query()->whereKey($occupant->id)->orWhere(fn ($q) => $q->where('family_of_id', $occupant->id)->where('status', 'active'))->get();
            $this->assertRoomHasSpace($to, $household->count());
            foreach ($household as $member) {
                ResidencyTransfer::create(['occupant_id' => $member->id, 'from_location_id' => $member->location_id, 'to_location_id' => $to->id,
                    'transfer_date' => $date, 'reason' => $reason, 'transferred_by' => $byUserId]);
                $member->forceFill(['location_id' => $to->id])->save();
            }
        });
    }

    /**
     * One staff occupant pays the whole bill (with their family); several
     * sharing a room pay equal parts, the last part taking the rounding.
     */
    public function bill(MaintenanceLocation $room, string $month, float $rent, float $electricity, float $water, int $byUserId): ResidencyBill
    {
        $staff = ResidencyOccupant::query()->where('location_id', $room->id)->where('occupant_type', 'staff')->where('status', 'active')->orderBy('id')->get();
        if ($staff->isEmpty()) {
            throw ValidationException::withMessages(['location_id' => [__('residency.no_staff_in_room')]]);
        }
        $total = round($rent + $electricity + $water, 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['room_rent' => [__('residency.bill_empty')]]);
        }
        if (ResidencyBill::query()->where('location_id', $room->id)->where('billing_month', $month)->exists()) {
            throw ValidationException::withMessages(['billing_month' => [__('residency.bill_exists', ['month' => $month])]]);
        }

        return DB::transaction(function () use ($room, $month, $rent, $electricity, $water, $total, $staff, $byUserId) {
            $bill = ResidencyBill::create([
                'organization_id' => $room->organization_id, 'location_id' => $room->id, 'billing_month' => $month, 'room_rent' => $rent,
                'electricity_bill' => $electricity, 'water_bill' => $water, 'total_amount' => $total, 'billing_unit_count' => $staff->count(), 'created_by' => $byUserId,
            ]);
            $part = floor($total / $staff->count() * 100) / 100;
            foreach ($staff->values() as $i => $occupant) {
                $amount = $i === $staff->count() - 1 ? round($total - $part * ($staff->count() - 1), 2) : $part;
                ResidencyBillShare::create(['bill_id' => $bill->id, 'occupant_id' => $occupant->id, 'amount_due' => $amount]);
                Notifier::user($occupant->user_id, $room->organization_id, 'residency.bill', ['month' => $month, 'amount' => number_format($amount, 2), 'room' => $room->name], '/my/residency');
            }

            return $bill;
        });
    }
}
