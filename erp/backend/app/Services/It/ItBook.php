<?php

namespace App\Services\It;

use App\Models\ItAsset;
use App\Models\ItAssetTransfer;
use App\Models\ItLab;
use App\Models\ItRepairTicket;
use App\Models\User;
use App\Services\Notifier;
use App\Support\Access\PermissionHolders;
use App\Support\Numbering\NumberSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** IT asset rules: tags, repair tickets moving the asset's status, transfers between labs. */
final class ItBook
{
    /** @param  array<string, mixed>  $data */
    public function create(ItLab $lab, array $data, int $userId): ItAsset
    {
        return DB::transaction(function () use ($lab, $data, $userId) {
            $prefix = ItAsset::PREFIXES[$data['asset_type']] ?? 'IT';
            $year = now()->format('y');
            $n = NumberSequence::next($lab->organization_id, "it_asset:{$prefix}", $year);

            return ItAsset::create($data + [
                'organization_id' => $lab->organization_id, 'lab_id' => $lab->id, 'asset_tag' => sprintf('%s-%s-%04d', $prefix, $year, $n), 'added_by' => $userId,
            ]);
        });
    }

    /** Anyone can report a problem; a working asset becomes faulty and IT and the lab assistant hear. */
    public function report(ItAsset $asset, string $description, User $by): ItRepairTicket
    {
        if ($asset->status === 'scrapped') {
            throw ValidationException::withMessages(['asset' => [__('it.scrapped')]]);
        }

        return DB::transaction(function () use ($asset, $description, $by) {
            $ticket = ItRepairTicket::create([
                'organization_id' => $asset->organization_id, 'asset_id' => $asset->id, 'lab_id' => $asset->lab_id,
                'issue_description' => $description, 'status' => 'pending', 'reported_by' => $by->id,
            ]);
            if ($asset->status === 'working') {
                $asset->forceFill(['status' => 'faulty'])->save();
            }
            $lab = ItLab::query()->find($asset->lab_id);
            $data = ['tag' => $asset->asset_tag, 'lab' => $lab?->name ?? '', 'issue' => mb_substr($description, 0, 120)];
            $to = array_unique([...PermissionHolders::userIds($asset->organization_id, 'it.manage', true), ...array_filter([$lab?->assistant_user_id])]);
            foreach ($to as $userId) {
                Notifier::user($userId, $asset->organization_id, 'it.ticket_new', $data, "/it/assets/{$asset->id}");
            }

            return $ticket;
        });
    }

    public function start(ItRepairTicket $ticket): void
    {
        if ($ticket->status !== 'pending') {
            throw ValidationException::withMessages(['ticket' => [__('it.ticket_not_pending')]]);
        }
        DB::transaction(function () use ($ticket) {
            $ticket->forceFill(['status' => 'in_progress'])->save();
            ItAsset::query()->whereKey($ticket->asset_id)->whereIn('status', ['working', 'faulty'])->update(['status' => 'under_repair']);
        });
    }

    /** Resolving the last open ticket puts the asset back to working (unless scrapped meanwhile). */
    public function resolve(ItRepairTicket $ticket, ?string $notes, int $userId): void
    {
        if ($ticket->status === 'resolved') {
            throw ValidationException::withMessages(['ticket' => [__('it.ticket_resolved')]]);
        }
        DB::transaction(function () use ($ticket, $notes, $userId) {
            $ticket->forceFill(['status' => 'resolved', 'resolution_notes' => $notes, 'resolved_by' => $userId, 'resolved_at' => now()])->save();
            $open = ItRepairTicket::query()->where('asset_id', $ticket->asset_id)->where('status', '!=', 'resolved')->exists();
            if (! $open) {
                ItAsset::query()->whereKey($ticket->asset_id)->where('status', '!=', 'scrapped')->update(['status' => 'working']);
            }
            $tag = ItAsset::query()->whereKey($ticket->asset_id)->value('asset_tag');
            Notifier::user($ticket->reported_by, $ticket->organization_id, 'it.ticket_resolved', ['tag' => $tag ?? ''], '/it/report');
        });
    }

    public function transfer(ItAsset $asset, ItLab $to, ?string $remarks, int $userId): void
    {
        if ($asset->lab_id === $to->id) {
            throw ValidationException::withMessages(['to_lab_id' => [__('it.same_lab')]]);
        }
        DB::transaction(function () use ($asset, $to, $remarks, $userId) {
            ItAssetTransfer::create(['asset_id' => $asset->id, 'from_lab_id' => $asset->lab_id, 'to_lab_id' => $to->id, 'remarks' => $remarks, 'transferred_by' => $userId]);
            $asset->forceFill(['lab_id' => $to->id])->save();
            ItRepairTicket::query()->where('asset_id', $asset->id)->where('status', '!=', 'resolved')->update(['lab_id' => $to->id]);
        });
    }
}
