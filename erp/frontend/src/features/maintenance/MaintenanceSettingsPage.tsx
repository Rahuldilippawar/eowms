import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { actionErrorMessage } from '@/lib/forms'
import { fetchBuildings, fetchRegisterDepartments, saveBuilding, setRegisterDepartment } from './api'

/** Which departments keep a register, and the buildings locations belong to. */
export function MaintenanceSettingsPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const orgId = user?.current_organization?.id
  const departments = useQuery({ queryKey: ['maintenance-departments', orgId], queryFn: fetchRegisterDepartments })
  const buildings = useQuery({ queryKey: ['maintenance-buildings', orgId], queryFn: fetchBuildings })
  const [name, setName] = useState('')
  const [renaming, setRenaming] = useState<{ id: number; name: string } | null>(null)
  const [error, setError] = useState<string | null>(null)

  const toggle = useMutation({
    mutationFn: (d: { id: number; on: boolean }) => setRegisterDepartment(d.id, d.on),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['maintenance-departments'] })
      void queryClient.invalidateQueries({ queryKey: ['maintenance-locations'] })
      toast.success(t('form.saved'))
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const save = useMutation({
    mutationFn: (b: { id: number | null; name: string }) => saveBuilding(b.id, { name: b.name, institution_id: null }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['maintenance-buildings'] })
      setName('')
      setRenaming(null)
      setError(null)
      toast.success(t('form.saved'))
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <h1 className="text-2xl font-semibold">{t('maintenance.settings')}</h1>
      <section aria-labelledby="ms-depts" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="ms-depts" className="font-semibold">
          {t('maintenance.registerDepartments')}
        </h2>
        <p className="mb-3 text-sm text-slate-500">{t('maintenance.registerHint')}</p>
        {departments.isPending ? <LoadingState /> : null}
        {departments.isError ? <ErrorState onRetry={() => void departments.refetch()} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(departments.data ?? []).map((d) => (
            <li key={d.id} className="flex items-center justify-between gap-3 py-2 text-sm">
              <label htmlFor={`ms-d-${d.id}`} className="flex-1">
                {d.name} <span className="text-slate-500">({d.code})</span>
              </label>
              <input
                id={`ms-d-${d.id}`}
                type="checkbox"
                className="size-4"
                checked={d.has_maintenance_register}
                disabled={toggle.isPending}
                onChange={(e) => toggle.mutate({ id: d.id, on: e.target.checked })}
              />
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="ms-buildings" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="ms-buildings" className="mb-3 font-semibold">
          {t('maintenance.buildings')}
        </h2>
        <form
          className="mb-3 flex flex-wrap items-end gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (name.trim()) save.mutate({ id: null, name: name.trim() })
          }}
        >
          <div className="min-w-48 flex-1">
            <Field id="ms-new-building" label={t('maintenance.newBuilding')} error={renaming ? undefined : (error ?? undefined)}>
              <Input id="ms-new-building" value={name} maxLength={255} onChange={(e) => setName(e.target.value)} />
            </Field>
          </div>
          <Button type="submit" disabled={save.isPending || !name.trim()}>
            {t('common.add')}
          </Button>
        </form>
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(buildings.data ?? []).map((b) => (
            <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
              {renaming?.id === b.id ? (
                <form
                  className="flex flex-1 flex-wrap items-end gap-2"
                  onSubmit={(e) => {
                    e.preventDefault()
                    if (renaming.name.trim()) save.mutate({ id: b.id, name: renaming.name.trim() })
                  }}
                >
                  <div className="min-w-40 flex-1">
                    <Field id={`ms-b-${b.id}`} label={t('maintenance.building')} error={error ?? undefined}>
                      <Input id={`ms-b-${b.id}`} value={renaming.name} maxLength={255} onChange={(e) => setRenaming({ id: b.id, name: e.target.value })} />
                    </Field>
                  </div>
                  <Button type="submit" disabled={save.isPending}>
                    {t('common.save')}
                  </Button>
                  <Button type="button" variant="secondary" onClick={() => setRenaming(null)}>
                    {t('common.cancel')}
                  </Button>
                </form>
              ) : (
                <>
                  <span>
                    {b.name} <span className="text-slate-500">· {t('maintenance.locationsCount', { count: b.locations_count ?? 0 })}</span>
                  </span>
                  <button
                    type="button"
                    className="font-medium text-brand-700 hover:underline"
                    onClick={() => {
                      setError(null)
                      setRenaming({ id: b.id, name: b.name })
                    }}
                  >
                    {t('maintenance.rename')}
                  </button>
                </>
              )}
            </li>
          ))}
        </ul>
      </section>
    </div>
  )
}
