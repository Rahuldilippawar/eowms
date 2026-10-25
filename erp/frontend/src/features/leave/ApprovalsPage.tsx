import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { decideCorrection, fetchCorrections, type Correction } from '@/features/attendance/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { actionErrorMessage } from '@/lib/forms'
import { formatDate } from '@/lib/format'
import { decideLeave, fetchLeaveApprovals } from './api'
import { LeaveList } from './MyLeavePage'

/** Approve / reject with optional remarks; shows the server's refusal in the dialog. */
function DecideButtons({ label, onDecide, invalidate }: { label: string; onDecide: (approve: boolean, remarks: string) => Promise<unknown>; invalidate: string[] }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [mode, setMode] = useState<'approve' | 'reject' | null>(null)
  const [remarks, setRemarks] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const confirm = async () => {
    setBusy(true)
    setError(null)
    try {
      await onDecide(mode === 'approve', remarks.trim())
      invalidate.forEach((k) => void queryClient.invalidateQueries({ queryKey: [k] }))
      toast.success(mode === 'approve' ? t('requests.approvedToast') : t('requests.rejectedToast'))
      setMode(null)
      setRemarks('')
    } catch (e) {
      setError(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <Button className="min-h-8 px-3 py-1 text-xs" onClick={() => setMode('approve')} aria-label={`${t('requests.approve')}: ${label}`}>
        {t('requests.approve')}
      </Button>
      <Button variant="secondary" className="min-h-8 px-3 py-1 text-xs" onClick={() => setMode('reject')} aria-label={`${t('requests.reject')}: ${label}`}>
        {t('requests.reject')}
      </Button>
      <Modal
        open={mode !== null}
        onOpenChange={(o) => !busy && !o && setMode(null)}
        title={mode === 'approve' ? t('requests.approveTitle') : t('requests.rejectTitle')}
        description={label}
        footer={
          <>
            <Button variant="secondary" disabled={busy} onClick={() => setMode(null)}>
              {t('common.cancel')}
            </Button>
            <Button className={mode === 'reject' ? 'bg-red-600 hover:bg-red-700' : undefined} disabled={busy} onClick={() => void confirm()}>
              {busy ? <Spinner label={t('common.saving')} /> : mode === 'approve' ? t('requests.approve') : t('requests.reject')}
            </Button>
          </>
        }
      >
        <label className="block text-sm">
          <span className="mb-1.5 block font-medium">{t('requests.remarks')}</span>
          <textarea value={remarks} maxLength={500} rows={2} onChange={(e) => setRemarks(e.target.value)} className="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
        </label>
        {error ? (
          <p role="alert" className="mt-3 text-sm text-red-600">
            {error}
          </p>
        ) : null}
      </Modal>
    </>
  )
}

function CorrectionRow({ c }: { c: Correction }) {
  const { t } = useTranslation()
  const label = `${c.employee?.name ?? ''} — ${formatDate(c.work_date)}`
  return (
    <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="text-sm">
        <p className="font-medium">{label}</p>
        <p className="text-slate-500">
          → {t(`attendance.status.${c.requested_status}`)}
          {c.requested_check_in ? ` · ${c.requested_check_in}` : ''}
          {c.requested_check_out ? ` – ${c.requested_check_out}` : ''} · {c.reason}
        </p>
      </div>
      <div className="flex gap-2">
        <DecideButtons label={label} invalidate={['corrections', 'dashboard']} onDecide={(approve, remarks) => decideCorrection(c.id, approve, remarks)} />
      </div>
    </li>
  )
}

export function ApprovalsPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const orgKey = user?.current_organization?.id
  const leave = useQuery({ queryKey: ['leave-approvals', orgKey], queryFn: fetchLeaveApprovals })
  const corrections = useQuery({ queryKey: ['corrections', orgKey], queryFn: fetchCorrections, enabled: can('attendance.approve') })

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <h1 className="text-2xl font-semibold">{t('nav.approvals')}</h1>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 className="mb-2 font-semibold">{t('leave.pendingLeave')}</h2>
        {leave.isPending ? (
          <LoadingState />
        ) : leave.isError ? (
          <ErrorState onRetry={() => void leave.refetch()} />
        ) : leave.data.length === 0 ? (
          <EmptyState message={t('requests.nothingPending')} />
        ) : (
          <LeaveList
            rows={leave.data}
            actions={(r) => (
              <DecideButtons
                label={`${r.employee?.name ?? ''} — ${r.leave_type.name}`}
                invalidate={['leave-approvals', 'dashboard']}
                onDecide={(approve, remarks) => decideLeave(r.id, approve, remarks)}
              />
            )}
          />
        )}
      </section>
      {can('attendance.approve') ? (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <h2 className="mb-2 font-semibold">{t('attendance.pendingCorrections')}</h2>
          {corrections.isPending ? (
            <LoadingState />
          ) : corrections.isError ? (
            <ErrorState onRetry={() => void corrections.refetch()} />
          ) : corrections.data.length === 0 ? (
            <EmptyState message={t('requests.nothingPending')} />
          ) : (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {corrections.data.map((c) => (
                <CorrectionRow key={c.id} c={c} />
              ))}
            </ul>
          )}
        </section>
      ) : null}
    </div>
  )
}
