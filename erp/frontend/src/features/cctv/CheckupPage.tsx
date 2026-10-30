import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { todayYmd } from '@/lib/format'
import { cn } from '@/lib/utils'
import { CAMERA_STATUSES, fetchSheet, saveSheet, type CameraStatus, type Sheet } from './api'

function SheetForm({ sheet, onSaved }: { sheet: Sheet; onSaved: (s: Sheet) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [v, setV] = useState({
    storage: sheet.storage_used_percent?.toString() ?? '',
    footage: sheet.footage_days?.toString() ?? '',
    backup: sheet.backup_done,
    backupWhere: sheet.backup_location ?? '',
    remarks: sheet.remarks ?? '',
  })
  const [cams, setCams] = useState(sheet.cameras.map((c) => ({ ...c, remarks: c.remarks ?? '' })))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () =>
      saveSheet(sheet.dvr.id, {
        date: sheet.date,
        storage_used_percent: v.storage === '' ? null : Number(v.storage),
        footage_days: v.footage === '' ? null : Number(v.footage),
        backup_done: v.backup,
        backup_location: v.backupWhere.trim() || null,
        remarks: v.remarks.trim() || null,
        cameras: cams.map((c) => ({ camera_id: c.id, status: c.status, remarks: c.remarks.trim() || null })),
      }),
    onSuccess: (s) => {
      toast.success(t('cctv.checkSaved'))
      onSaved(s)
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['storage_used_percent', 'footage_days', 'backup_location', 'remarks', 'date'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const tone: Record<CameraStatus, string> = { working: 'bg-emerald-700 text-white', not_working: 'bg-red-700 text-white', needs_attention: 'bg-amber-600 text-white' }

  return (
    <div className="space-y-4">
      {general ? (
        <p role="alert" className="text-sm text-red-600">
          {general}
        </p>
      ) : null}
      <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 dark:border-slate-800 dark:bg-slate-900">
        <Field id="ck-storage" label={t('cctv.storageUsedLabel')} error={errors.storage_used_percent}>
          <Input id="ck-storage" type="number" inputMode="decimal" min={0} max={100} step="0.5" value={v.storage} onChange={(e) => setV({ ...v, storage: e.target.value })} />
        </Field>
        <Field id="ck-footage" label={t('cctv.footageLabel')} error={errors.footage_days}>
          <Input id="ck-footage" type="number" inputMode="numeric" min={0} value={v.footage} onChange={(e) => setV({ ...v, footage: e.target.value })} />
        </Field>
        <label className="inline-flex items-center gap-2 text-sm">
          <input type="checkbox" checked={v.backup} onChange={(e) => setV({ ...v, backup: e.target.checked })} />
          {t('cctv.backupTaken')}
        </label>
        {v.backup ? (
          <Field id="ck-where" label={t('cctv.backupWhere')} error={errors.backup_location}>
            <Input id="ck-where" maxLength={255} placeholder={t('cctv.backupWhereHint')} value={v.backupWhere} onChange={(e) => setV({ ...v, backupWhere: e.target.value })} />
          </Field>
        ) : null}
      </section>
      <section aria-labelledby="ck-cams" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="ck-cams" className="mb-2 font-semibold">
          {t('cctv.cameras')}
        </h2>
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {cams.map((c, i) => (
            <li key={c.id} className="space-y-2 py-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm">
                  <span className="font-medium">{c.name}</span>
                  {c.location ? <span className="text-slate-500"> · {c.location}</span> : null}
                </span>
                <div role="radiogroup" aria-label={c.name} className="inline-flex rounded-lg border border-slate-200 p-0.5 dark:border-slate-700">
                  {CAMERA_STATUSES.map((s) => (
                    <button
                      key={s}
                      type="button"
                      role="radio"
                      aria-checked={c.status === s}
                      onClick={() => setCams(cams.map((x, j) => (j === i ? { ...x, status: s } : x)))}
                      className={cn('min-h-9 rounded-md px-3 text-xs font-medium', c.status === s ? tone[s] : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
                    >
                      {t(`cctv.camera.${s}`)}
                    </button>
                  ))}
                </div>
              </div>
              {c.status !== 'working' ? (
                <Input aria-label={t('cctv.cameraRemark', { name: c.name })} maxLength={255} placeholder={t('cctv.whatIsWrong')} value={c.remarks} onChange={(e) => setCams(cams.map((x, j) => (j === i ? { ...x, remarks: e.target.value } : x)))} />
              ) : null}
            </li>
          ))}
        </ul>
      </section>
      <Field id="ck-remarks" label={t('store.remarks')} error={errors.remarks}>
        <Input id="ck-remarks" maxLength={2000} value={v.remarks} onChange={(e) => setV({ ...v, remarks: e.target.value })} />
      </Field>
      <Button disabled={save.isPending} onClick={() => save.mutate()}>
        {save.isPending ? <Spinner label={t('common.saving')} /> : t('cctv.saveCheck')}
      </Button>
    </div>
  )
}

export function CheckupPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const { id } = useParams()
  const [params, setParams] = useSearchParams()
  const date = params.get('date') ?? todayYmd()
  const key = ['cctv-sheet', user?.current_organization?.id, Number(id), date]
  const q = useQuery({ queryKey: key, queryFn: () => fetchSheet(Number(id), date) })

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <Link to={`/cctv/dvrs/${id}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {q.data?.dvr.name ?? t('nav.cctv')}
      </Link>
      <h1 className="text-2xl font-semibold">{t('cctv.dailyCheck')}</h1>
      <Field id="ck-date" label={t('store.date')}>
        <Input id="ck-date" type="date" max={todayYmd()} value={date} onChange={(e) => e.target.value && setParams({ date: e.target.value })} />
      </Field>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data ? (
        <>
          {q.data.saved ? <p className="rounded-xl bg-sky-50 px-4 py-2 text-sm text-sky-900 dark:bg-sky-950 dark:text-sky-200">{t('cctv.alreadyChecked', { name: q.data.checked_by ?? '' })}</p> : null}
          <SheetForm
            key={`${q.data.date}-${q.data.saved}`}
            sheet={q.data}
            onSaved={(s) => {
              queryClient.setQueryData(key, s)
              void queryClient.invalidateQueries({ queryKey: ['cctv-dashboard'] })
              void queryClient.invalidateQueries({ queryKey: ['cctv-dvr'] })
            }}
          />
        </>
      ) : null}
    </div>
  )
}
