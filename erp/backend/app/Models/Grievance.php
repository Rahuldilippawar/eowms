<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grievance extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    public const OPEN = ['open', 'in_progress'];

    /** Days to resolve a grievance (and again after it is reopened). */
    public const SLA_DAYS = 7;

    protected $fillable = [
        'organization_id', 'submitted_by', 'submitter_hash', 'is_anonymous', 'category', 'description', 'status', 'assigned_to', 'resolution',
        'sla_deadline', 'resolved_at', 'escalated_at', 'reopened_count',
    ];

    /** Never serialised: an anonymous grievance must not leak who sent it. */
    protected $hidden = ['submitter_hash'];

    protected function casts(): array
    {
        return ['is_anonymous' => 'boolean', 'sla_deadline' => 'date:Y-m-d', 'resolved_at' => 'datetime', 'escalated_at' => 'datetime', 'reopened_count' => 'integer'];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(GrievanceComment::class)->orderBy('id');
    }

    /** A keyed hash of the person, so their own anonymous grievances can be found without storing who they are. */
    public static function submitterHash(int $userId): string
    {
        return hash_hmac('sha256', 'grievance-submitter:'.$userId, (string) config('app.key'));
    }
}
