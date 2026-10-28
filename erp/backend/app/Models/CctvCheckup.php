<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CctvCheckup extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'dvr_id', 'checkup_date', 'storage_used_percent', 'footage_days', 'backup_done', 'backup_location', 'remarks', 'checked_by'];

    protected function casts(): array
    {
        return ['checkup_date' => 'date:Y-m-d', 'storage_used_percent' => 'decimal:2', 'backup_done' => 'boolean'];
    }

    public function dvr(): BelongsTo
    {
        return $this->belongsTo(CctvDvr::class, 'dvr_id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(CctvCameraStatus::class, 'checkup_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
