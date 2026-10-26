import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { addDays, todayYmd } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { fetchRollSheet, saveRollSheet, SESSIONS, type RollStatus, type Session } from './api'

const tone: Record<RollStatus, string> = {
  present: 'bg-emerald-600 text-white',
  absent: 'bg-red-600 text-white',
  on_leave: 'bg-slate-600 text-white',
}

function currentSession(): Session {
  const h = new Date().getHours()
  return h < 12 ? 'morning' : h < 17 ? 'afternoon' : 'evening'
}

/** One roll call: everyone present by default, out-pass holders on leave; tap to change. */
export function HostelAttendancePage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const hostelId = Number(id)
  const toast = useToast()
  const queryClient = useQueryClient()
  const [date, setDate] = useState(todayYmd)
  const [session, setSession] = useState<Session>(currentSession)
  const q = useQuery({ queryKey: ['hostel-roll', hostelId, date, session], queryFn: () => fetchRollSheet(hostelId, session, date) })
  const [marks, setMarks] = useState<Record<number, { status: RollStatus; remark: string }>>({})
  useEffect(() => {
    if (q.data) setMarks(Object.fromEntries(q.data.residents.map((r) => [r.id, { status: r.status, remark: r.remark ?? '' }])))
  }, [q.data])
  const save = useMutation({
    mutationFn: () => saveRollSheet(hostelId, session, date, Object.entries(marks).map(([rid, m]) => ({ resident_id: Number(rid), status: m.status, remark: m.remark.trim() || null }))),
    onSuccess: (sheet) => {
      queryClient.setQueryData(['hostel-roll', hostelId, date, session], sheet)
      void queryClient.invalidateQueries({ queryKey: ['hostel', hostelId] })
      toast.success(t('hostels.rollSaved'))
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const counts = Object.values(marks).reduce((c, m) => ({ ...c, [m.status]: (c[m.status] ?? 0) + 1 }), {} as Record<RollStatus, number>)

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <Link to={`/hostels/${hostelId}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('hostels.backToHostel')}
      </Link>
      <h1 className="text-2xl font-semibold">{t('hostels.rollCall')}</h1>
      <div className="flex flex-wrap items-end gap-3">
        <Field id="rc-date" label={t('attendance.date')}>
          <Input id="rc-date" type="date" min={addDays(todayYmd(), -7)} max={todayYmd()} value={date} onChange={(e) => setDate(e.target.value)} />
        </Field>
        <Field id="rc-session" label={t('hostels.sessionLabel')}>
          <Select id="rc-session" value={session} onChange={(e) => setSession(e.target.value as Session)}>
            {SESSIONS.map((s) => (
              <option key={s} value={s}>
                {t(`hostels.session.${s}`)}
                {q.data?.sessions_marked.includes(s) ? ` ✓` : ''}
              </option>
            ))}
          </Select>
        </Field>
        {q.data ? <p className="py-2 text-sm text-slate-600 dark:text-slate-400">{q.data.marked ? t('hostels.alreadyTaken') : t('hostels.notTakenYet')}</p> : null}
      </div>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.residents.length === 0 ? <EmptyState message={t('hostels.noResidents')} /> : null}
      {q.data && q.data.residents.length > 0 ? (
        <>
          <p className="text-sm">{t('hostels.rollSummary', { present: counts.present ?? 0, absent: counts.absent ?? 0, leave: counts.on_leave ?? 0 })}</p>
          <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
            {q.data.residents.map((r) => {
              const m = marks[r.id] ?? { status: r.status, remark: '' }
              return (
                <li key={r.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                  <span>
                    <span className="block font-medium">{r.name}</span>
                    <span className="block text-xs text-slate-500">{[r.room ? t('hostels.roomN', { room: r.room }) : null, r.on_out_pass ? t('hostels.onOutPass') : null].filter(Boolean).join(' · ')}</span>
                  </span>
                  <span role="radiogroup" aria-label={r.name} className="inline-flex gap-1">
                    {(['present', 'absent', 'on_leave'] as const).map((s) => (
                      <button
                        key={s}
                        type="button"
                        role="radio"
                        aria-checked={m.status === s}
                        onClick={() => setMarks({ ...marks, [r.id]: { ...m, status: s } })}
                        className={cn('min-h-9 rounded-md px-3 text-sm font-medium', m.status === s ? tone[s] : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200')}
                      >
                        {t(`hostels.roll.${s}`)}
                      </button>
                    ))}
                  </span>
                </li>
              )
            })}
          </ul>
          <Button disabled={save.isPending} onClick={() => save.mutate()}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('hostels.saveRoll')}
          </Button>
        </>
      ) : null}
    </div>
  )
}
