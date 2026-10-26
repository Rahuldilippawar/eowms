<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class LetterCategory extends Model
{
    use BelongsToOrganization;

    /** Offered by "Add the usual categories" (the old app's defaults). */
    public const DEFAULTS = [
        'आवक पत्र', 'जावक पत्र', 'परिपत्रक', 'आदेश', 'सूचना', 'अर्ज/विनंती', 'निवेदन', 'मंजुरी पत्र', 'तक्रार', 'आमंत्रण पत्र',
        'निविदा/करार', 'स्मरणपत्र', 'खरेदी मागणी', 'शासन निर्णय (GR) संदर्भ', 'नियुक्ती पत्र', 'आर्थिक/Budget मंजुरी',
        'माहिती अधिकार (RTI)', 'सर्वसाधारण पत्रव्यवहार',
    ];

    protected $fillable = ['organization_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
