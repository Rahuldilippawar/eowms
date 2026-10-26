import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate, formatTime } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { fetchCorrections, reviewCorrection, type Correction } from './api'
import { EntryDialog } from './EntryDialog'
import { entryValues } from './entryFields'

const statusTone = { pending: 'bg-amber-50 text-amber-800', approved: 'bg-emerald-50 text-emerald-700', rejected: 'bg-red-50 text-red-700' }

export function VehicleCorrectionsPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('pending')
  const [fixing, setFixing] = useState<Correction | null>(null)
  const [deciding, setDeciding] = useState<{ c: Correction; decision: 'approve' | 'reject' } | null>(null)
  const [note, setNote] = useState('')
  const q = useQuery({ queryKey: ['vehicle-corrections', user?.current_organization?.id, status], queryFn: () => fetchCorrections(status) })
  const done = (msg: string) => {
    void queryClient.invalidateQueries({ queryKey: ['vehicle-corrections'] })
    void queryClient.invalidateQueries({ queryKey: ['vehicle'] })
    toast.success(msg)
  }
  const review = useMutation({
    mutationFn: (input: { c: Correction; decision: 'approve' | 'reject'; changes?: Record<string, string | number | null> }) =>
      reviewCorrection(input.c.id, { decision: input.decision, note: note.trim() || null, changes: input.changes }),
    onSuccess: (_, input) => done(input.decision === 'approve' ? t('vehicles.correctionApproved') : t('vehicles.correctionRejected')),
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('nav.vehicleCorrections')}</h1>
      <Field id="vc-status" label={t('attendance.statusLabel')}>
        <Select id="vc-status" value={status} onChange={(e) => setStatus(e.target.value)}>
          {(['pending', 'approved', 'rejected', 'all'] as const).map((s) => (
            <option key={s} value={s}>
              {t(`vehicles.correctionStatus.${s}`)}
            </option>
          ))}
        </Select>
      </Field>
      <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.length === 0 ? <EmptyState message={t('vehicles.noCorrections')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data ?? []).map((c) => (
            <li key={c.id} className="space-y-2 px-4 py-3">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-medium">
                    <Link to={`/vehicles/${c.vehicle.id}`} className="text-brand-700 hover:underline">
                      {c.vehicle.vehicle_number}
                    </Link>{' '}
                    — {t(`vehicles.tabs.${c.entry_type}`)} · {c.request_type === 'delete' ? t('vehicles.requestDelete') : t('vehicles.requestEdit')}
                  </p>
                  <p className="text-sm text-slate-500">{[c.requested_by, c.created_at ? `${formatDate(c.created_at.slice(0, 10))} ${formatTime(c.created_at)}` : null].filter(Boolean).join(' · ')}</p>
                </div>
                <span className={cn('rounded-md px-2 py-0.5 text-xs font-medium', statusTone[c.status])}>{t(`vehicles.correctionStatus.${c.status}`)}</span>
              </div>
              <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">{c.reason}</p>
              {c.entry ? (
                <p className="text-xs text-slate-500">
                  {Object.entries(c.entry)
                    .filter(([, val]) => val !== null && val !== '')
                    .map(([k, val]) => `${t(`vehicles.field.${k}`)}: ${val}`)
                    .join(' · ')}
                </p>
              ) : (
                <p className="text-xs text-slate-500">{t('vehicles.entryGone')}</p>
              )}
              {c.review_note || c.reviewed_by ? <p className="text-xs text-slate-500">{[c.reviewed_by, c.review_note].filter(Boolean).join(' — ')}</p> : null}
              {c.can_review && c.entry ? (
                <div className="flex flex-wrap gap-2">
                  {c.request_type === 'edit' ? (
                    <Button onClick={() => setFixing(c)}>{t('vehicles.fixAndApprove')}</Button>
                  ) : (
                    <Button onClick={() => setDeciding({ c, decision: 'approve' })}>
                      {t('vehicles.deleteAsAsked')}
                    </Button>
                  )}
                  <Button variant="secondary" onClick={() => setDeciding({ c, decision: 'reject' })}>
                    {t('vehicles.reject')}
                  </Button>
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      </section>

      {fixing && fixing.entry ? (
        <EntryDialog
          type={fixing.entry_type}
          title={t('vehicles.fixAndApprove')}
          submitLabel={t('vehicles.fixAndApprove')}
          initial={entryValues(fixing.entry_type, fixing.entry)}
          extra={
            <Field id="vc-note" label={t('vehicles.reviewNote')}>
              <Input id="vc-note" value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />
            </Field>
          }
          onSubmit={(input) => review.mutateAsync({ c: fixing, decision: 'approve', changes: input as unknown as Record<string, string | number | null> })}
          onClose={() => {
            setFixing(null)
            setNote('')
          }}
        />
      ) : null}
      <ConfirmDialog
        open={deciding !== null}
        onOpenChange={(o) => !o && setDeciding(null)}
        title={deciding?.decision === 'reject' ? t('vehicles.reject') : t('vehicles.deleteAsAsked')}
        body={deciding?.decision === 'reject' ? t('vehicles.rejectBody') : t('vehicles.deleteEntryBody')}
        confirmLabel={deciding?.decision === 'reject' ? t('vehicles.reject') : t('common.delete')}
        danger
        withReason
        onConfirm={async (reason) => {
          if (!deciding) return
          setNote(reason)
          await reviewCorrection(deciding.c.id, { decision: deciding.decision, note: reason || null })
          done(deciding.decision === 'approve' ? t('vehicles.correctionApproved') : t('vehicles.correctionRejected'))
          setDeciding(null)
          setNote('')
        }}
      />
    </div>
  )
}
