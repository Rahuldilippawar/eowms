<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RoleAssignment extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['user_id', 'role_id', 'organization_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class, 'role_assignment_institution');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'role_assignment_department');
    }
}
