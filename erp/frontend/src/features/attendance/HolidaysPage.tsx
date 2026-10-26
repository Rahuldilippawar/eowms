import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Plus, Trash2, Upload } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchInstitutionOptions } from '@/features/org/api'
import { actionErrorMessage } from '@/lib/forms'
import { formatDate } from '@/lib/format'
import { deleteHoliday, fetchHolidays, holidayTemplateUrl, importHolidays, saveHoliday, type Holiday } from './parityApi'

function HolidayDialog({ open, onOpenChange, holiday }: { open: boolean; onOpenChange: (o: boolean) => void; holiday?: Holiday }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user, can } = useAuth()
  const institutions = useQuery({ queryKey: ['institution-options', user?.current_organization?.id], queryFn: fetchInstitutionOptions, enabled: open && can('institutions.view') })
  const [v, setV] = useState({ holiday_date: '', name: '', institution_id: '' as number | '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    if (open) {
      setV({ holiday_date: holiday?.holiday_date ?? '', name: holiday?.name ?? '', institution_id: holiday?.institution?.id ?? '' })
      setErrors({})
      setGeneral(null)
    }
  }, [open, holiday])

  const submit = async () => {
    const local: Record<string, string> = {}
    if (!v.holiday_date) local.holiday_date = t('form.required')
    if (!v.name.trim()) local.name = t('form.required')
    setErrors(local)
    if (Object.keys(local).length) return
    setBusy(true)
    try {
      await saveHoliday(holiday?.id ?? null, { holiday_date: v.holiday_date, name: v.name.trim(), institution_id: v.institution_id || null })
      void queryClient.invalidateQueries({ queryKey: ['holidays'] })
      toast.success(t('form.saved'))
      onOpenChange(false)
    } catch (e) {
      const r = splitServerErrors(e, ['holiday_date', 'name', 'institution_id'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={holiday ? t('holidays.edit') : t('holidays.new')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button disabled={busy} onClick={() => void submit()}>
            {busy ? <Spinner label={t('common.saving')} /> : t('common.save')}
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
        <Field id="hd-date" label={t('attendance.date')} error={errors.holiday_date}>
          <Input id="hd-date" type="date" value={v.holiday_date} onChange={(e) => setV({ ...v, holiday_date: e.target.value })} />
        </Field>
        <Field id="hd-name" label={t('org.name')} error={errors.name}>
          <Input id="hd-name" value={v.name} maxLength={255} onChange={(e) => setV({ ...v, name: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="hd-inst" label={t('holidays.appliesTo')} error={errors.institution_id}>
            <Select id="hd-inst" value={v.institution_id} onChange={(e) => setV({ ...v, institution_id: e.target.value ? Number(e.target.value) : '' })}>
              <option value="">{t('holidays.wholeOrganization')}</option>
              {(institutions.data ?? []).map((i) => (
                <option key={i.id} value={i.id}>
                  {i.name}
                </option>
              ))}
            </Select>
          </Field>
        </div>
      </div>
    </Modal>
  )
}

export function HolidaysPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user, can } = useAuth()
  const [year, setYear] = useState(new Date().getFullYear())
  const [editing, setEditing] = useState<Holiday | undefined>()
  const [open, setOpen] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)
  const q = useQuery({ queryKey: ['holidays', user?.current_organization?.id, year], queryFn: () => fetchHolidays(year) })
  const manage = can('attendance.manage')

  const onImport = async () => {
    const file = fileRef.current?.files?.[0]
    if (!file) return
    try {
      const r = await importHolidays(file)
      toast.success(t('holidays.imported', { created: r.created, skipped: r.skipped }))
      void queryClient.invalidateQueries({ queryKey: ['holidays'] })
    } catch (e) {
      toast.error(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      if (fileRef.current) fileRef.current.value = ''
    }
  }

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.holidays')}</h1>
        {manage ? (
          <div className="flex flex-wrap items-center gap-3">
            <a href={holidayTemplateUrl} className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
              <Download aria-hidden className="size-4" />
              {t('import.template')}
            </a>
            <input ref={fileRef} id="hol-import" type="file" accept=".csv,text/csv" className="sr-only" onChange={() => void onImport()} />
            <label htmlFor="hol-import" className="inline-flex cursor-pointer items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
              <Upload aria-hidden className="size-4" />
              {t('holidays.import')}
            </label>
            <Button
              onClick={() => {
                setEditing(undefined)
                setOpen(true)
              }}
            >
              <Plus aria-hidden className="size-4" />
              {t('holidays.new')}
            </Button>
          </div>
        ) : null}
      </div>
      <Field id="hol-year" label={t('holidays.year')}>
        <Input id="hol-year" type="number" min={2000} max={2100} value={year} className="w-32" onChange={(e) => setYear(Number(e.target.value) || year)} />
      </Field>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.length === 0 ? <EmptyState message={t('holidays.none')} /> : null}
      {q.data && q.data.length > 0 ? (
        <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {q.data.map((h) => (
            <li key={h.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
              <div>
                <p className="font-medium">{h.name}</p>
                <p className="text-sm text-slate-500">
                  {formatDate(h.holiday_date)} · {h.institution?.name ?? t('holidays.wholeOrganization')}
                </p>
              </div>
              {manage ? (
                <div className="flex gap-1">
                  <Button
                    variant="ghost"
                    className="min-h-8 px-2 py-1 text-xs"
                    aria-label={t('common.editNamed', { name: h.name })}
                    onClick={() => {
                      setEditing(h)
                      setOpen(true)
                    }}
                  >
                    {t('common.edit')}
                  </Button>
                  <Button
                    variant="ghost"
                    className="min-h-8 px-2 py-1 text-xs text-red-700"
                    aria-label={t('work.deleteNamed', { name: h.name })}
                    onClick={async () => {
                      try {
                        await deleteHoliday(h.id)
                        void queryClient.invalidateQueries({ queryKey: ['holidays'] })
                      } catch (e) {
                        toast.error(actionErrorMessage(e, t('common.actionFailed')))
                      }
                    }}
                  >
                    <Trash2 aria-hidden className="size-4" />
                  </Button>
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}
      <HolidayDialog open={open} onOpenChange={setOpen} holiday={editing} />
    </div>
  )
}
