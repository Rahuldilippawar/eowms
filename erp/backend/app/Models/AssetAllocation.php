<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAllocation extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'type_id', 'item_id', 'user_id', 'custom', 'purpose', 'allocated_at', 'due_at', 'returned_at', 'returned_to',
        'return_reason', 'transferred_from_id', 'allocated_by'];

    protected function casts(): array
    {
        return ['custom' => 'array', 'allocated_at' => 'datetime', 'due_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AllocationType::class, 'type_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AllocationItem::class, 'item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_to');
    }
}
