import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchLeaveTypes, saveLeaveType, type LeaveType } from './api'

const FIELDS = ['code', 'name', 'days_per_year', 'is_paid', 'is_active'] as const

function TypeDialog({ open, onOpenChange, type }: { open: boolean; onOpenChange: (o: boolean) => void; type?: LeaveType }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [v, setV] = useState({ code: '', name: '', days_per_year: '0', is_paid: true, is_active: true })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  useEffect(() => {
    if (open) {
      setV({ code: type?.code ?? '', name: type?.name ?? '', days_per_year: String(type?.days_per_year ?? 0), is_paid: type?.is_paid ?? true, is_active: type?.is_active ?? true })
      setErrors({})
      setGeneral(null)
    }
  }, [open, type])

  const save = useMutation({
    mutationFn: () => saveLeaveType(type?.id ?? null, { ...v, code: v.code.trim(), name: v.name.trim(), days_per_year: Number(v.days_per_year) }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['leave-types'] })
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
    if (!v.code.trim()) local.code = t('form.required')
    if (!v.name.trim()) local.name = t('form.required')
    const n = Number(v.days_per_year)
    if (v.days_per_year === '' || Number.isNaN(n) || n < 0 || n > 366) local.days_per_year = t('leave.daysRange')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={type ? t('leave.editType') : t('leave.newType')}
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
        <Field id="lt-name" label={t('org.name')} error={errors.name}>
          <Input id="lt-name" value={v.name} aria-invalid={!!errors.name || undefined} onChange={(e) => setV({ ...v, name: e.target.value })} />
        </Field>
        <Field id="lt-code" label={t('org.code')} error={errors.code}>
          <Input id="lt-code" value={v.code} aria-invalid={!!errors.code || undefined} onChange={(e) => setV({ ...v, code: e.target.value })} />
        </Field>
        <Field id="lt-days" label={t('leave.daysPerYear')} error={errors.days_per_year}>
          <Input id="lt-days" type="number" min={0} max={366} step="0.5" value={v.days_per_year} aria-invalid={!!errors.days_per_year || undefined} onChange={(e) => setV({ ...v, days_per_year: e.target.value })} />
        </Field>
        <div className="flex flex-col justify-end gap-2 text-sm">
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={v.is_paid} onChange={(e) => setV({ ...v, is_paid: e.target.checked })} />
            {t('leave.paid')}
          </label>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={v.is_active} onChange={(e) => setV({ ...v, is_active: e.target.checked })} />
            {t('org.active')}
          </label>
        </div>
      </div>
    </Modal>
  )
}

export function LeaveTypesPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['leave-types', user?.current_organization?.id], queryFn: fetchLeaveTypes })
  const [editing, setEditing] = useState<LeaveType | undefined>()
  const [open, setOpen] = useState(false)

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.leaveTypes')}</h1>
        <Button
          onClick={() => {
            setEditing(undefined)
            setOpen(true)
          }}
        >
          <Plus aria-hidden className="size-4" />
          {t('leave.newType')}
        </Button>
      </div>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.length === 0 ? <EmptyState message={t('leave.noTypes')} /> : null}
      {q.data && q.data.length > 0 ? (
        <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {q.data.map((lt) => (
            <li key={lt.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
              <div>
                <p className="font-medium">
                  {lt.name} <span className="font-mono text-xs text-slate-500">{lt.code}</span>
                </p>
                <p className="text-sm text-slate-500">
                  {t('leave.perYear', { count: Number(lt.days_per_year) })} · {lt.is_paid ? t('leave.paid') : t('leave.unpaid')}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge tone={lt.is_active ? 'green' : 'slate'}>{lt.is_active ? t('org.active') : t('org.inactive')}</Badge>
                <Button
                  variant="ghost"
                  className="min-h-8 px-2 py-1 text-xs"
                  aria-label={t('common.editNamed', { name: lt.name })}
                  onClick={() => {
                    setEditing(lt)
                    setOpen(true)
                  }}
                >
                  {t('common.edit')}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      ) : null}
      <TypeDialog open={open} onOpenChange={setOpen} type={editing} />
    </div>
  )
}
