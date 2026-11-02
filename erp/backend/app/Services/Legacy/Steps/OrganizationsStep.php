<?php

namespace App\Services\Legacy\Steps;

use App\Models\Organization;
use App\Services\Legacy\LegacyImport;
use App\Services\Legacy\Step;
use App\Support\Access\DefaultRoles;
use Illuminate\Support\Str;

/** organizations → organizations, each with the default roles. */
final class OrganizationsStep implements Step
{
    public function key(): string
    {
        return 'organizations';
    }

    public function run(LegacyImport $import): void
    {
        foreach ($import->legacy()->table('organizations')->orderBy('id')->get() as $o) {
            $import->read();
            if ($import->mapped('organizations', $o->id) !== null) {
                $import->existing();

                continue;
            }
            $code = self::uniqueCode(strtoupper(Str::limit(Str::slug((string) $o->slug, ''), 20, '')) ?: 'ORG'.$o->id);
            $org = Organization::query()->create([
                'name' => $o->name, 'code' => $code, 'email' => $o->contact_email ?? null, 'phone' => $o->contact_phone ?? null, 'is_active' => (bool) $o->is_active,
            ]);
            DefaultRoles::createFor($org);
            $import->remember('organizations', $o->id, 'organizations', $org->id);
            $import->created();
        }
    }

    private static function uniqueCode(string $code): string
    {
        $try = $code;
        for ($i = 2; Organization::query()->where('code', $try)->exists(); $i++) {
            $try = Str::limit($code, 26, '').'-'.$i;
        }

        return $try;
    }
}
