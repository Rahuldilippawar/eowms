<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RenewalItem extends Model
{
    use BelongsToOrganization;

    /** Within this many days of expiry an item is "due soon". */
    public const DUE_SOON_DAYS = 30;

    public const REGULATORY_BODIES = [
        'CBSE', 'State Board', 'AICTE', 'DTE Maharashtra', 'MSBTE', 'University', 'UGC', 'NAAC', 'RTE', 'RTO/Transport', 'Other Government', 'Not Applicable',
    ];

    protected $fillable = [
        'organization_id', 'institution_id', 'department_id', 'responsible_user_id', 'vehicle_id', 'renewal_field', 'item_type', 'regulatory_body', 'item_name', 'reference_no',
        'issued_date', 'expiry_date', 'renewal_cost', 'is_recurring_monthly', 'monthly_amount', 'recurring_start_month', 'vendor_name',
        'vendor_contact', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d', 'recurring_start_month' => 'date:Y-m-d', 'renewal_cost' => 'decimal:2',
            'monthly_amount' => 'decimal:2', 'is_recurring_monthly' => 'boolean', 'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(RenewalHistory::class)->orderByDesc('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RenewalAttachment::class)->orderByDesc('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancePayment::class, 'source_id')->where('source_type', 'renewal')->orderByDesc('payment_date')->orderByDesc('id');
    }

    public function daysLeft(CarbonImmutable $today): int
    {
        // Both as plain dates in one timezone, so the count is whole days.
        return (int) round(CarbonImmutable::parse($today->toDateString())->diffInDays(CarbonImmutable::parse($this->expiry_date->toDateString())));
    }

    /** expired | due_soon | active */
    public static function statusFor(int $daysLeft): string
    {
        return $daysLeft < 0 ? 'expired' : ($daysLeft <= self::DUE_SOON_DAYS ? 'due_soon' : 'active');
    }
}
