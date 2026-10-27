<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidencyImportBatch extends Model
{
    use BelongsToOrganization;

    protected $table = 'residency_import_batches';

    protected $fillable = ['organization_id', 'filename', 'buildings_created', 'rooms_created', 'occupants_created', 'status', 'imported_by', 'rolled_back_at'];

    protected function casts(): array
    {
        return ['rolled_back_at' => 'datetime'];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
