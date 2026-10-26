<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceLog extends Model
{
    use BelongsToOrganization;

    public const WORK_TYPES = ['installed', 'repaired', 'replaced', 'inspected'];

    protected $fillable = ['organization_id', 'location_id', 'department_id', 'item_description', 'work_type', 'work_date', 'cost', 'remarks', 'performed_by'];

    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'cost' => 'decimal:2'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLocation::class, 'location_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancePayment::class, 'source_id')->where('source_type', 'maintenance_log')->orderBy('payment_date')->orderBy('id');
    }
}
