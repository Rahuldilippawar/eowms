<?php

namespace App\Services\Legacy\Steps;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Legacy\LegacyImport;
use App\Services\Legacy\Step;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * users → accounts with a role. Passwords move as they are (bcrypt), so people keep signing in
 * with the same password. An e-mail the ERP already has joins that account instead of a new one.
 *
 * Old role categories become the ERP's starter roles (owners adjust them afterwards):
 * super_admin → super admin; management → Principal, for their campus (the old "principal") or every
 * campus; hod / supervisor / coordinator → Head of Department; staff → Staff. Extra department /
 * campus access is added to the role's reach.
 */
final class UsersStep implements Step
{
    public function key(): string
    {
        return 'users';
    }

    public function run(LegacyImport $import): void
    {
        $legacy = $import->legacy();
        $categories = $legacy->getSchemaBuilder()->hasTable('role_definitions')
            ? $legacy->table('role_definitions')->pluck('category', 'role_key')->all() : [];
        $extraDepartments = $legacy->getSchemaBuilder()->hasTable('user_extra_department_access')
            ? $legacy->table('user_extra_department_access')->get()->groupBy('user_id') : collect();
        $extraCampuses = $legacy->getSchemaBuilder()->hasTable('user_extra_campus_access')
            ? $legacy->table('user_extra_campus_access')->get()->groupBy('user_id') : collect();

        foreach ($legacy->table('users')->orderBy('id')->get() as $u) {
            $import->read();
            if ($import->mapped('users', $u->id) !== null) {
                $import->existing();

                continue;
            }
            $org = $import->mapped('organizations', $u->organization_id);
            if ($org === null) {
                $import->skip('users', $u->id, 'organization_missing');

                continue;
            }
            $email = mb_strtolower(trim((string) $u->email));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $import->skip('users', $u->id, 'email_invalid');

                continue;
            }
            $category = $categories[$u->role] ?? ($u->role === 'super_admin' ? 'super_admin' : 'staff');
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            if ($user !== null) {
                $import->matched();
            } else {
                $hash = (string) $u->password;
                $bcrypt = preg_match('/^\$2[aby]\$\d{2}\$/', $hash) === 1;
                if (! $bcrypt) {
                    $import->warn("users #{$u->id}: password is not bcrypt; the person must reset it");
                    $hash = bcrypt(Str::random(40));
                }
                $id = DB::table('users')->insertGetId([
                    'name' => $u->name, 'email' => $email, 'password' => $hash, 'is_active' => (bool) $u->is_active,
                    'is_super_admin' => $category === 'super_admin', 'must_change_password' => (bool) ($u->must_change_password ?? false) || ! $bcrypt,
                    'last_login_at' => $u->last_login_at ?? null, 'onboarding_seen_at' => ($u->onboarding_seen ?? 0) ? now() : null,
                    'locale' => 'mr', 'created_at' => $u->created_at ?? now(), 'updated_at' => now(),
                ]);
                $user = User::query()->findOrFail($id);
                $import->created();
            }
            $import->remember('users', $u->id, 'users', $user->id);
            if ($category !== 'super_admin') {
                $this->assign($import, $user, $org, $category, $u, $extraDepartments->get($u->id, collect()), $extraCampuses->get($u->id, collect()));
            }
        }
    }

    private function assign(LegacyImport $import, User $user, int $org, string $category, object $u, $extraDepts, $extraCampuses): void
    {
        $key = match ($category) {
            'management' => 'principal',
            'hod' => 'hod',
            default => 'staff',
        };
        $role = Role::withoutGlobalScopes()->where(['organization_id' => $org, 'key' => $key])->first();
        if ($role === null) {
            $import->warn("users #{$u->id}: role {$key} missing in the organization");

            return;
        }
        $campuses = collect([$u->campus_id])->merge($extraCampuses->pluck('campus_id'))->map(fn ($id) => $import->mapped('campuses', $id))->filter()->unique();
        if ($key === 'principal') {
            // The old "principal" ran one campus; the other management roles saw the whole trust.
            $institutions = $u->role === 'principal' ? $campuses : Institution::withoutGlobalScopes()->where('organization_id', $org)->pluck('id');
            $departments = collect();
        } else {
            $institutions = collect();
            // Extra campus access reaches every department of those campuses.
            $extraInstitutions = $extraCampuses->pluck('campus_id')->map(fn ($id) => $import->mapped('campuses', $id))->filter();
            $departments = collect([$u->department_id])->merge($extraDepts->pluck('department_id'))->map(fn ($id) => $import->mapped('departments', $id))->filter()
                ->merge($extraInstitutions->isEmpty() ? [] : Department::withoutGlobalScopes()->whereIn('institution_id', $extraInstitutions->all())->pluck('id'))->unique();
        }
        $assignment = RoleAssignment::withoutGlobalScopes()->firstOrCreate(['user_id' => $user->id, 'role_id' => $role->id], ['organization_id' => $org]);
        $assignment->institutions()->syncWithoutDetaching($institutions->values()->all());
        $assignment->departments()->syncWithoutDetaching($departments->values()->all());
    }
}
