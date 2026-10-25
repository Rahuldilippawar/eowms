<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A field an organization adds to its own forms; values live in the entity's `custom` JSON column. */
class CustomField extends Model
{
    use BelongsToOrganization;

    public const ENTITIES = ['employee', 'student', 'work_entry'];

    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];

    protected $fillable = ['organization_id', 'entity', 'key', 'label', 'type', 'options', 'is_required', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['options' => 'array', 'is_required' => 'boolean', 'is_active' => 'boolean'];
    }
}
