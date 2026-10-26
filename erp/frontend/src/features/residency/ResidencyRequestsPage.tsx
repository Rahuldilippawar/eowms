import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatDate } from '@/lib/format'
import { decideRequest, fetchAllotmentRequests, fetchRooms, type AllotmentRequest } from './api'
import { ResidencyNav } from './ResidencyNav'

type Decision = 'approve' | 'waitlist' | 'reject'

function DecideDialog({ request, decision, onClose }: { request: AllotmentRequest; decision: Decision; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const [room, setRoom] = useState('')
  const [remarks, setRemarks] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const rooms = useQuery({ queryKey: ['residency-rooms', user?.current_organization?.id, '', '', false], queryFn: () => fetchRooms({}), enabled: decision === 'approve' })
  const save = useMutation({
    mutationFn: () => decideRequest(request.id, { decision, location_id: decision === 'approve' ? Number(room) : undefined, remarks: remarks.trim() || null }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['residency-requests'] })
      void queryClient.invalidateQueries({ queryKey: ['residency-rooms'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['location_id', 'remarks'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general ?? r.byField.user_id ?? r.byField.decision ?? null)
    },
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t(`residency.decide.${decision}Title`, { name: request.requested_by ?? '' })}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending || (decision === 'approve' && !room)} onClick={() => save.mutate()}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t(`residency.decide.${decision}`)}
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {general ? (
          <p role="alert" className="text-sm text-red-600">
            {general}
          </p>
        ) : null}
        {decision === 'approve' ? (
          <Field id="dc-room" label={t('residency.allotRoom')} error={errors.location_id}>
            <Select id="dc-room" value={room} onChange={(e) => setRoom(e.target.value)}>
              <option value="">—</option>
              {(rooms.data?.data ?? []).map((r) => (
                <option key={r.id} value={r.id} disabled={r.capacity !== null && r.occupants_count >= r.capacity}>
                  {[r.building?.name, r.name].filter(Boolean).join(' · ')}
                  {r.capacity !== null ? ` (${r.occupants_count}/${r.capacity})` : ''}
                </option>
              ))}
            </Select>
          </Field>
        ) : null}
        <Field id="dc-remarks" label={t('residency.remark')} error={errors.remarks}>
          <Input id="dc-remarks" maxLength={1000} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}

export function ResidencyRequestsPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const [status, setStatus] = useState('open')
  const [deciding, setDeciding] = useState<{ request: AllotmentRequest; decision: Decision } | null>(null)
  const q = useQuery({ queryKey: ['residency-requests', user?.current_organization?.id, status], queryFn: () => fetchAllotmentRequests(status) })
  const manage = can('residency.manage')

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('residency.requestsTitle')}</h1>
      <ResidencyNav />
      <Field id="rq-status" label={t('attendance.statusLabel')}>
        <Select id="rq-status" value={status} onChange={(e) => setStatus(e.target.value)}>
          {(['open', 'approved', 'rejected', 'cancelled', 'all'] as const).map((s) => (
            <option key={s} value={s}>
              {t(`residency.requestFilter.${s}`)}
            </option>
          ))}
        </Select>
      </Field>
      <div className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {q.isPending ? <LoadingState /> : null}
        {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        {q.data && q.data.length === 0 ? <EmptyState message={t('residency.noRequests')} /> : null}
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {(q.data ?? []).map((r) => (
            <li key={r.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
              <span className="min-w-0 text-sm">
                <span className="font-medium">{r.requested_by}</span> <Badge tone={r.status === 'approved' ? 'green' : r.status === 'waitlisted' ? 'blue' : 'slate'}>{t(`residency.status.${r.status}`)}</Badge>
                <span className="block text-slate-500">
                  {[
                    formatDate(r.created_at?.slice(0, 10)),
                    t('residency.familyOfN', { count: r.family_size }),
                    r.preferred_building ? t('residency.prefers', { building: r.preferred_building }) : null,
                    r.allotted_room ? t('residency.allotted', { room: r.allotted_room }) : null,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                </span>
                {r.reason ? <span className="block">{r.reason}</span> : null}
                {r.remarks ? <span className="block text-slate-500">{r.remarks}</span> : null}
              </span>
              {manage && (r.status === 'pending' || r.status === 'waitlisted') ? (
                <span className="flex shrink-0 flex-wrap gap-2">
                  <Button onClick={() => setDeciding({ request: r, decision: 'approve' })}>{t('residency.decide.approve')}</Button>
                  {r.status === 'pending' ? (
                    <Button variant="secondary" onClick={() => setDeciding({ request: r, decision: 'waitlist' })}>
                      {t('residency.decide.waitlist')}
                    </Button>
                  ) : null}
                  <Button variant="secondary" onClick={() => setDeciding({ request: r, decision: 'reject' })}>
                    {t('residency.decide.reject')}
                  </Button>
                </span>
              ) : null}
            </li>
          ))}
        </ul>
      </div>
      {deciding ? <DecideDialog request={deciding.request} decision={deciding.decision} onClose={() => setDeciding(null)} /> : null}
    </div>
  )
}
