<?php

namespace App\Services\Legacy\Steps;

use App\Enums\InstitutionType;
use App\Models\Institution;
use App\Services\Legacy\LegacyImport;
use App\Services\Legacy\Step;
use Illuminate\Support\Str;

/** campuses → institutions (school / college / other from the old college type). */
final class InstitutionsStep implements Step
{
    private const SCHOOLS = ['Primary School', 'Secondary School', 'Junior College'];

    public function key(): string
    {
        return 'institutions';
    }

    public function run(LegacyImport $import): void
    {
        foreach ($import->legacy()->table('campuses')->orderBy('id')->get() as $c) {
            $import->read();
            if ($import->mapped('campuses', $c->id) !== null) {
                $import->existing();

                continue;
            }
            $org = $import->mapped('organizations', $c->organization_id);
            if ($org === null) {
                $import->skip('campuses', $c->id, 'organization_missing');

                continue;
            }
            $type = match (true) {
                in_array($c->college_type, self::SCHOOLS, true) => InstitutionType::School,
                $c->college_type === null, $c->college_type === 'Trust HQ', $c->college_type === 'Other' => InstitutionType::Other,
                default => InstitutionType::College,
            };
            $code = Str::limit((string) $c->code, 30, '') ?: 'C'.$c->id;
            for ($i = 2; Institution::withoutGlobalScopes()->where(['organization_id' => $org, 'code' => $code])->exists(); $i++) {
                $code = Str::limit((string) $c->code, 26, '').'-'.$i;
            }
            $institution = Institution::withoutGlobalScopes()->create([
                'organization_id' => $org, 'type' => $type, 'name' => $c->name, 'code' => $code, 'is_active' => (bool) $c->is_active,
                'address' => implode(', ', array_filter([$c->address ?? null, $c->city ?? null, $c->state ?? null])) ?: null,
            ]);
            $import->remember('campuses', $c->id, 'institutions', $institution->id);
            $import->created();
        }
    }
}
