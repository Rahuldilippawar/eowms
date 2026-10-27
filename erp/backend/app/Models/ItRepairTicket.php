<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItRepairTicket extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['pending', 'in_progress', 'resolved'];

    protected $fillable = ['organization_id', 'asset_id', 'lab_id', 'issue_description', 'status', 'resolution_notes', 'reported_by', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(ItAsset::class, 'asset_id');
    }

    public function lab(): BelongsTo
    {
        return $this->belongsTo(ItLab::class, 'lab_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
