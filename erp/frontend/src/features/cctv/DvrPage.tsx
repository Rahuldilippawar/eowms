import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ClipboardCheck, Pencil } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { addCamera, CAMERA_TYPES, fetchDvr, updateCamera, type CameraType, type DvrDetail } from './api'
import { DvrDialog } from './DvrDialog'

export function DvrPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user, can } = useAuth()
  const { id } = useParams()
  const key = ['cctv-dvr', user?.current_organization?.id, Number(id)]
  const q = useQuery({ queryKey: key, queryFn: () => fetchDvr(Number(id)) })
  const [editing, setEditing] = useState(false)
  const [cam, setCam] = useState({ name: '', location: '', camera_type: 'dome' as CameraType })
  const store = (d: DvrDetail) => {
    queryClient.setQueryData(key, d)
    void queryClient.invalidateQueries({ queryKey: ['cctv-dashboard'] })
  }
  const onError = (e: unknown) => toast.error(actionErrorMessage(e, t('common.actionFailed')))
  const add = useMutation({
    mutationFn: () => addCamera(Number(id), { name: cam.name.trim(), location: cam.location.trim() || null, camera_type: cam.camera_type }),
    onSuccess: (d) => {
      store(d)
      setCam({ name: '', location: '', camera_type: cam.camera_type })
    },
    onError,
  })
  const toggle = useMutation({
    mutationFn: (c: DvrDetail['cameras'][number]) => updateCamera(c.id, { name: c.name, location: c.location, camera_type: c.camera_type, is_active: !c.is_active }),
    onSuccess: store,
    onError,
  })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const d = q.data
  const active = d.cameras.filter((c) => c.is_active).length

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/cctv" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.cctv')}
      </Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{d.name}</h1>
          <p className="text-sm text-slate-500">
            {[d.location, t('cctv.camerasOf', { count: active, capacity: d.channel_capacity ?? '—' }), d.storage_tb !== null ? t('cctv.storageOf', { tb: d.storage_tb }) : null, d.installed_date ? t('cctv.installedOn', { date: formatDate(d.installed_date) }) : null]
              .filter(Boolean)
              .join(' · ')}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {can('cctv.check') || d.can_manage ? (
            <Link to={`/cctv/dvrs/${d.id}/checkup`} className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand-700 px-4 text-sm font-medium text-white hover:bg-brand-800">
              <ClipboardCheck aria-hidden className="size-4" />
              {t('cctv.checkNow')}
            </Link>
          ) : null}
          {d.can_manage ? (
            <Button variant="secondary" onClick={() => setEditing(true)}>
              <Pencil aria-hidden className="size-4" />
              {t('common.edit')}
            </Button>
          ) : null}
        </div>
      </div>
      <section aria-labelledby="dv-cams" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="dv-cams" className="mb-2 font-semibold">
          {t('cctv.cameras')}
        </h2>
        {d.cameras.length === 0 ? <EmptyState message={t('cctv.noCameras')} /> : null}
        <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
          {d.cameras.map((c) => (
            <li key={c.id} className={cn('flex items-center justify-between gap-2 py-2', !c.is_active && 'opacity-60')}>
              <span>
                <span className="font-medium">{c.name}</span> <span className="text-slate-500">· {[t(`cctv.type.${c.camera_type}`), c.location].filter(Boolean).join(' · ')}</span>
              </span>
              {d.can_manage ? (
                <button type="button" className="text-xs font-medium text-brand-700 hover:underline" aria-label={t(c.is_active ? 'cctv.removeNamed' : 'cctv.restoreNamed', { name: c.name })} onClick={() => toggle.mutate(c)}>
                  {c.is_active ? t('cctv.remove') : t('cctv.restore')}
                </button>
              ) : null}
            </li>
          ))}
        </ul>
        {d.can_manage ? (
          <div className="mt-3 flex flex-wrap items-end gap-2">
            <Input aria-label={t('cctv.newCameraName')} className="min-w-32 flex-1" maxLength={255} placeholder={t('cctv.newCameraName')} value={cam.name} onChange={(e) => setCam({ ...cam, name: e.target.value })} />
            <Input aria-label={t('cctv.newCameraLocation')} className="min-w-32 flex-1" maxLength={255} placeholder={t('cctv.location')} value={cam.location} onChange={(e) => setCam({ ...cam, location: e.target.value })} />
            <Select aria-label={t('cctv.newCameraType')} className="w-32" value={cam.camera_type} onChange={(e) => setCam({ ...cam, camera_type: e.target.value as CameraType })}>
              {CAMERA_TYPES.map((x) => (
                <option key={x} value={x}>
                  {t(`cctv.type.${x}`)}
                </option>
              ))}
            </Select>
            <Button variant="secondary" disabled={!cam.name.trim() || add.isPending} onClick={() => add.mutate()}>
              {t('cctv.addCamera')}
            </Button>
          </div>
        ) : null}
      </section>
      <section aria-labelledby="dv-checks" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="dv-checks" className="mb-2 font-semibold">
          {t('cctv.recentChecks')}
        </h2>
        {d.checkups.length === 0 ? <EmptyState message={t('cctv.neverChecked')} /> : null}
        <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
          {d.checkups.map((c) => (
            <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <Link to={`/cctv/dvrs/${d.id}/checkup?date=${c.date}`} className="font-medium text-brand-700 hover:underline">
                  {formatDate(c.date)}
                </Link>
                <span className="block text-slate-500">
                  {[
                    c.storage_used_percent !== null ? t('cctv.storageUsed', { percent: c.storage_used_percent }) : null,
                    c.footage_days !== null ? t('cctv.footageDays', { count: c.footage_days }) : null,
                    c.backup_done ? t('cctv.backupDone') : t('cctv.noBackup'),
                    c.checked_by,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                </span>
              </span>
              {c.not_working || c.needs_attention ? (
                <span className="font-medium text-red-700">{t('cctv.problemCounts', { down: c.not_working, attention: c.needs_attention })}</span>
              ) : (
                <span className="text-emerald-700">{t('cctv.allWorking')}</span>
              )}
            </li>
          ))}
        </ul>
      </section>
      {editing ? (
        <DvrDialog
          dvr={d}
          onClose={() => setEditing(false)}
          onSaved={(x) => {
            store(x)
          }}
        />
      ) : null}
    </div>
  )
}
