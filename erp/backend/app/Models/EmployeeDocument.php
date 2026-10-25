<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use BelongsToOrganization;

    public const TYPES = ['aadhaar', 'pan', 'photo_id', 'education', 'experience', 'appointment', 'medical', 'bank', 'other'];

    protected $fillable = ['organization_id', 'employee_id', 'document_type', 'title', 'file_path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by'];

    protected $hidden = ['file_path'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
