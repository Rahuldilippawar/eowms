import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { fetchUsers } from '@/features/admin/api'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchDepartmentOptions } from '@/features/org/api'
import { createEmployee, fetchEmployeeOptions, updateEmployee, type Employee, type EmployeeInput } from './api'

const FIELDS = ['employee_code', 'name', 'designation', 'phone', 'email', 'department_id', 'additional_department_ids', 'reporting_officer_id', 'user_id', 'joining_date'] as const

function initial(e?: Employee): EmployeeInput {
  return {
    employee_code: e?.employee_code ?? '',
    name: e?.name ?? '',
    designation: e?.designation ?? '',
    phone: e?.phone ?? '',
    email: e?.email ?? '',
    department_id: e?.department.id ?? 0,
    additional_department_ids: e?.additional_departments.map((d) => d.id) ?? [],
    reporting_officer_id: e?.reporting_officer?.id ?? null,
    user_id: e?.user?.id ?? null,
    joining_date: e?.joining_date ?? '',
  }
}

export function EmployeeDialog({ open, onOpenChange, employee }: { open: boolean; onOpenChange: (o: boolean) => void; employee?: Employee }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user, can } = useAuth()
  const orgKey = user?.current_organization?.id
  const [v, setV] = useState<EmployeeInput>(initial(employee))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const departments = useQuery({ queryKey: ['department-options', orgKey], queryFn: fetchDepartmentOptions, enabled: open })
  const officers = useQuery({ queryKey: ['employee-options', orgKey], queryFn: fetchEmployeeOptions, enabled: open })
  const accounts = useQuery({ queryKey: ['user-options', orgKey], queryFn: () => fetchUsers({ page: 1 }), enabled: open && can('users.view') })

  useEffect(() => {
    if (open) {
      setV(initial(employee))
      setErrors({})
      setGeneral(null)
    }
  }, [open, employee])

  const save = useMutation({
    mutationFn: () => {
      const n = (s: string | null) => (s && s.trim() ? s.trim() : null)
      const input = { ...v, employee_code: v.employee_code.trim(), name: v.name.trim(), designation: n(v.designation), phone: n(v.phone), email: n(v.email), joining_date: n(v.joining_date) }
      return employee ? updateEmployee(employee.id, input) : createEmployee(input)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['employees'] })
      void queryClient.invalidateQueries({ queryKey: ['employee-options'] })
      toast.success(t('form.saved'))
      onOpenChange(false)
    },
    onError: (e) => {
      const r = splitServerErrors(e, FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.employee_code.trim()) local.employee_code = t('form.required')
    if (!v.name.trim()) local.name = t('form.required')
    if (!v.department_id) local.department_id = t('form.required')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }
  const set = (k: keyof EmployeeInput) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={employee ? t('people.editEmployee') : t('people.newEmployee')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={submit}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <Field id="e-name" label={t('org.name')} error={errors.name}>
          <Input id="e-name" value={v.name} aria-invalid={!!errors.name || undefined} onChange={set('name')} />
        </Field>
        <Field id="e-code" label={t('people.employeeCode')} error={errors.employee_code}>
          <Input id="e-code" value={v.employee_code} aria-invalid={!!errors.employee_code || undefined} onChange={set('employee_code')} />
        </Field>
        <Field id="e-desig" label={t('people.designation')} error={errors.designation}>
          <Input id="e-desig" value={v.designation ?? ''} onChange={set('designation')} />
        </Field>
        <Field id="e-join" label={t('people.joiningDate')} error={errors.joining_date}>
          <Input id="e-join" type="date" value={v.joining_date ?? ''} onChange={set('joining_date')} />
        </Field>
        <Field id="e-phone" label={t('org.phone')} error={errors.phone}>
          <Input id="e-phone" type="tel" value={v.phone ?? ''} onChange={set('phone')} />
        </Field>
        <Field id="e-email" label={t('auth.email')} error={errors.email}>
          <Input id="e-email" type="email" value={v.email ?? ''} onChange={set('email')} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="e-dept" label={t('people.primaryDepartment')} error={errors.department_id}>
            <Select id="e-dept" value={v.department_id || ''} aria-invalid={!!errors.department_id || undefined} onChange={(e) => setV({ ...v, department_id: Number(e.target.value) })}>
              <option value="">{t('people.chooseDepartment')}</option>
              {(departments.data ?? []).map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                  {d.institution ? ` — ${d.institution.name}` : ''}
                </option>
              ))}
            </Select>
          </Field>
        </div>
        <fieldset className="sm:col-span-2">
          <legend className="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">{t('people.additionalDepartments')}</legend>
          <div className="max-h-32 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2 dark:border-slate-700">
            {(departments.data ?? [])
              .filter((d) => d.id !== v.department_id)
              .map((d) => (
                <label key={d.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={v.additional_department_ids.includes(d.id)}
                    onChange={() =>
                      setV({
                        ...v,
                        additional_department_ids: v.additional_department_ids.includes(d.id) ? v.additional_department_ids.filter((x) => x !== d.id) : [...v.additional_department_ids, d.id],
                      })
                    }
                  />
                  {d.name}
                </label>
              ))}
          </div>
          {errors.additional_department_ids ? (
            <p role="alert" className="mt-1 text-sm text-red-600">
              {errors.additional_department_ids}
            </p>
          ) : null}
        </fieldset>
        <Field id="e-officer" label={t('people.reportingOfficer')} error={errors.reporting_officer_id}>
          <Select id="e-officer" value={v.reporting_officer_id ?? ''} onChange={(e) => setV({ ...v, reporting_officer_id: e.target.value ? Number(e.target.value) : null })}>
            <option value="">—</option>
            {(officers.data ?? [])
              .filter((o) => o.id !== employee?.id)
              .map((o) => (
                <option key={o.id} value={o.id}>
                  {o.name}
                </option>
              ))}
          </Select>
        </Field>
        {can('users.view') ? (
          <Field id="e-user" label={t('people.loginAccount')} error={errors.user_id}>
            <Select id="e-user" value={v.user_id ?? ''} onChange={(e) => setV({ ...v, user_id: e.target.value ? Number(e.target.value) : null })}>
              <option value="">{t('people.noAccount')}</option>
              {(accounts.data?.data ?? []).map((u) => (
                <option key={u.id} value={u.id}>
                  {u.name} ({u.email})
                </option>
              ))}
            </Select>
          </Field>
        ) : null}
      </div>
    </Modal>
  )
}
