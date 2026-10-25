import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchDepartmentOptions, fetchInstitutionOptions } from '@/features/org/api'
import { fetchRole, fetchRoles, type AssignmentInput } from './api'

/**
 * Role picker plus the institutions / departments a scoped role applies to.
 * The lists show only units the current user can see; the API re-checks reach.
 */
export function AssignmentFields({
  value,
  onChange,
  errors,
}: {
  value: AssignmentInput
  onChange: (v: AssignmentInput) => void
  errors: Partial<Record<'role_id' | 'institution_ids' | 'department_ids', string>>
}) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const orgKey = user?.current_organization?.id
  const roles = useQuery({ queryKey: ['roles', orgKey], queryFn: fetchRoles })
  const role = useQuery({ queryKey: ['role', orgKey, value.role_id], queryFn: () => fetchRole(value.role_id), enabled: value.role_id > 0 })
  const scopes = new Set((role.data?.permissions ?? []).map((p) => p.scope))
  const needsInstitutions = scopes.has('institutions')
  const needsDepartments = scopes.has('departments')
  const institutions = useQuery({ queryKey: ['institution-options', orgKey], queryFn: fetchInstitutionOptions, enabled: needsInstitutions })
  const departments = useQuery({ queryKey: ['department-options', orgKey], queryFn: fetchDepartmentOptions, enabled: needsDepartments })

  const toggle = (list: number[], id: number) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id])

  return (
    <div className="space-y-4">
      <Field id="assign-role" label={t('admin.role')} error={errors.role_id}>
        <Select
          id="assign-role"
          aria-invalid={!!errors.role_id || undefined}
          value={value.role_id || ''}
          onChange={(e) => onChange({ role_id: Number(e.target.value), institution_ids: [], department_ids: [] })}
        >
          <option value="">{t('admin.chooseRole')}</option>
          {(roles.data ?? []).map((r) => (
            <option key={r.id} value={r.id}>
              {r.name}
            </option>
          ))}
        </Select>
      </Field>

      {needsInstitutions ? (
        <fieldset>
          <legend className="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">{t('admin.limitInstitutions')}</legend>
          <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2 dark:border-slate-700">
            {(institutions.data ?? []).map((i) => (
              <label key={i.id} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={value.institution_ids.includes(i.id)} onChange={() => onChange({ ...value, institution_ids: toggle(value.institution_ids, i.id) })} />
                {i.name}
              </label>
            ))}
          </div>
          {errors.institution_ids ? (
            <p role="alert" className="mt-1 text-sm text-red-600">
              {errors.institution_ids}
            </p>
          ) : null}
        </fieldset>
      ) : null}

      {needsDepartments ? (
        <fieldset>
          <legend className="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">{t('admin.limitDepartments')}</legend>
          <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2 dark:border-slate-700">
            {(departments.data ?? []).map((d) => (
              <label key={d.id} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={value.department_ids.includes(d.id)} onChange={() => onChange({ ...value, department_ids: toggle(value.department_ids, d.id) })} />
                {d.name}
                <span className="text-xs text-slate-500">{d.institution ? d.institution.name : t('org.central')}</span>
              </label>
            ))}
          </div>
          {errors.department_ids ? (
            <p role="alert" className="mt-1 text-sm text-red-600">
              {errors.department_ids}
            </p>
          ) : null}
        </fieldset>
      ) : null}
    </div>
  )
}
