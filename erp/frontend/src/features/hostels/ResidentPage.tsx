import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { formatDate, formatMoney, todayYmd } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import {
  addFee,
  addIncident,
  allocateBed,
  departResident,
  fetchResident,
  goOut,
  markReturned,
  payFee,
  saveHealth,
  updateAlumnus,
  updateResident,
  vacateBed,
  type HealthProfile,
  type PaymentMode,
  type ResidentDetail,
} from './api'
import { ResidentForm } from './HostelPage'

const panel = 'rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'

function Panel({ id, title, children }: { id: string; title: string; children: ReactNode }) {
  return (
    <section aria-labelledby={id} className={panel}>
      <h2 id={id} className="mb-3 font-semibold">
        {title}
      </h2>
      {children}
    </section>
  )
}

/** Small forms on this page share one pattern: fields, a button, the server's message on failure. */
function useAct(id: number) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (run: () => Promise<ResidentDetail>) => run(),
    onSuccess: (r) => {
      queryClient.setQueryData(['hostel-resident', id], r)
      void queryClient.invalidateQueries({ queryKey: ['hostel-residents', r.hostel.id] })
      void queryClient.invalidateQueries({ queryKey: ['hostel', r.hostel.id] })
      toast.success(t('form.saved'))
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
}

function BedPanel({ r }: { r: ResidentDetail }) {
  const { t } = useTranslation()
  const act = useAct(r.id)
  const [room, setRoom] = useState(r.room?.room_id.toString() ?? '')
  const [bed, setBed] = useState(r.room?.bed_label ?? '')
  return (
    <Panel id="rp-bed" title={t('hostels.bed')}>
      <div className="flex flex-wrap items-end gap-2">
        <Field id="rp-room" label={t('hostels.room')}>
          <Select id="rp-room" value={room} onChange={(e) => setRoom(e.target.value)}>
            <option value="">—</option>
            {r.rooms.map((x) => (
              <option key={x.id} value={x.id} disabled={x.occupied >= x.capacity && x.id !== r.room?.room_id}>
                {t('hostels.roomOption', { room: x.room_number, occupied: x.occupied, capacity: x.capacity })}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="rp-bedlabel" label={t('hostels.bedLabel')}>
          <Input id="rp-bedlabel" value={bed} maxLength={20} onChange={(e) => setBed(e.target.value)} />
        </Field>
        <Button disabled={!room || act.isPending} onClick={() => act.mutate(() => allocateBed(r.id, Number(room), bed.trim() || null))}>
          {r.room ? t('hostels.move') : t('hostels.allot')}
        </Button>
        {r.room ? (
          <Button variant="secondary" disabled={act.isPending} onClick={() => act.mutate(() => vacateBed(r.id))}>
            {t('hostels.vacate')}
          </Button>
        ) : null}
      </div>
      {r.allocations.length ? (
        <ul className="mt-3 space-y-1 text-xs text-slate-500">
          {r.allocations.map((a) => (
            <li key={a.id}>{t('hostels.allocationLine', { room: a.room, bed: a.bed_label ?? '—', from: formatDate(a.allocated_on), to: a.vacated_on ? formatDate(a.vacated_on) : t('hostels.now') })}</li>
          ))}
        </ul>
      ) : null}
    </Panel>
  )
}

function OutPanel({ r }: { r: ResidentDetail }) {
  const { t } = useTranslation()
  const act = useAct(r.id)
  const [v, setV] = useState({ leave_date: todayYmd(), expected_return_date: '', reason: '', escort_name: '' })
  return (
    <Panel id="rp-out" title={t('hostels.outPasses')}>
      {r.out ? (
        <div className="mb-3 flex flex-wrap items-center gap-3">
          <p className={cn('text-sm', r.out.overdue ? 'font-medium text-red-700' : '')}>
            {t('hostels.outSince', { date: formatDate(r.out.since) })}
            {r.out.expected_return_date ? ` · ${t('hostels.expectedBack', { date: formatDate(r.out.expected_return_date) })}` : ''}
          </p>
          <Button disabled={act.isPending} onClick={() => act.mutate(() => markReturned(r.id, null))}>
            {t('hostels.markReturned')}
          </Button>
        </div>
      ) : (
        <div className="mb-3 grid gap-2 sm:grid-cols-2">
          <Field id="op-from" label={t('hostels.leavingOn')}>
            <Input id="op-from" type="date" value={v.leave_date} onChange={(e) => setV({ ...v, leave_date: e.target.value })} />
          </Field>
          <Field id="op-back" label={t('hostels.backBy')}>
            <Input id="op-back" type="date" min={v.leave_date} value={v.expected_return_date} onChange={(e) => setV({ ...v, expected_return_date: e.target.value })} />
          </Field>
          <Field id="op-reason" label={t('hostels.reason')}>
            <Input id="op-reason" value={v.reason} maxLength={255} onChange={(e) => setV({ ...v, reason: e.target.value })} />
          </Field>
          <Field id="op-escort" label={t('hostels.escort')}>
            <Input id="op-escort" value={v.escort_name} maxLength={255} onChange={(e) => setV({ ...v, escort_name: e.target.value })} />
          </Field>
          <Button
            className="sm:col-span-2"
            disabled={act.isPending || !v.leave_date}
            onClick={() => act.mutate(() => goOut(r.id, { leave_date: v.leave_date, expected_return_date: v.expected_return_date || null, reason: v.reason.trim() || null, escort_name: v.escort_name.trim() || null }))}
          >
            {t('hostels.giveOutPass')}
          </Button>
        </div>
      )}
      <ul className="space-y-1 text-sm">
        {r.leaves.map((l) => (
          <li key={l.id} className={l.overdue ? 'text-red-700' : 'text-slate-600 dark:text-slate-300'}>
            {formatDate(l.leave_date)} → {l.actual_return_date ? formatDate(l.actual_return_date) : l.expected_return_date ? `(${formatDate(l.expected_return_date)})` : '…'}
            {[l.reason, l.escort_name ? t('hostels.withEscort', { name: l.escort_name }) : null, l.approved_by].filter(Boolean).map((x) => ` · ${x}`)}
          </li>
        ))}
      </ul>
    </Panel>
  )
}

function HealthPanel({ r }: { r: ResidentDetail }) {
  const { t } = useTranslation()
  const act = useAct(r.id)
  const [h, setH] = useState<HealthProfile>(
    r.health ?? { blood_group: null, allergies: null, regular_medication: null, emergency_contact_name: null, emergency_contact_phone: null, medical_notes: null },
  )
  const [inc, setInc] = useState({ incident_date: todayYmd(), description: '', action_taken: '' })
  const set = (k: keyof HealthProfile) => (e: { target: { value: string } }) => setH({ ...h, [k]: e.target.value || null })
  return (
    <Panel id="rp-health" title={t('hostels.health')}>
      <div className="grid gap-2 sm:grid-cols-2">
        <Field id="hp-blood" label={t('hostels.bloodGroup')}>
          <Input id="hp-blood" value={h.blood_group ?? ''} maxLength={10} onChange={set('blood_group')} />
        </Field>
        <Field id="hp-ec" label={t('hostels.emergencyContact')}>
          <Input id="hp-ec" value={h.emergency_contact_name ?? ''} maxLength={255} onChange={set('emergency_contact_name')} />
        </Field>
        <Field id="hp-ecp" label={t('hostels.emergencyPhone')}>
          <Input id="hp-ecp" type="tel" value={h.emergency_contact_phone ?? ''} maxLength={30} onChange={set('emergency_contact_phone')} />
        </Field>
        <Field id="hp-allergies" label={t('hostels.allergies')}>
          <Input id="hp-allergies" value={h.allergies ?? ''} maxLength={2000} onChange={set('allergies')} />
        </Field>
        <Field id="hp-meds" label={t('hostels.medication')}>
          <Input id="hp-meds" value={h.regular_medication ?? ''} maxLength={2000} onChange={set('regular_medication')} />
        </Field>
        <Field id="hp-notes" label={t('hostels.medicalNotes')}>
          <Input id="hp-notes" value={h.medical_notes ?? ''} maxLength={5000} onChange={set('medical_notes')} />
        </Field>
        <Button className="sm:col-span-2" disabled={act.isPending} onClick={() => act.mutate(() => saveHealth(r.id, h))}>
          {t('hostels.saveHealth')}
        </Button>
      </div>
      <h3 className="mt-4 mb-2 text-sm font-semibold">{t('hostels.incidents')}</h3>
      <div className="grid gap-2 sm:grid-cols-[10rem_1fr]">
        <Field id="hi-date" label={t('attendance.date')}>
          <Input id="hi-date" type="date" max={todayYmd()} value={inc.incident_date} onChange={(e) => setInc({ ...inc, incident_date: e.target.value })} />
        </Field>
        <Field id="hi-desc" label={t('hostels.whatHappened')}>
          <Input id="hi-desc" value={inc.description} maxLength={5000} onChange={(e) => setInc({ ...inc, description: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="hi-action" label={t('hostels.actionTaken')}>
            <Input id="hi-action" value={inc.action_taken} maxLength={5000} onChange={(e) => setInc({ ...inc, action_taken: e.target.value })} />
          </Field>
        </div>
        <Button
          variant="secondary"
          className="sm:col-span-2"
          disabled={act.isPending || !inc.description.trim()}
          onClick={() =>
            act.mutate(async () => {
              const next = await addIncident(r.id, { incident_date: inc.incident_date, description: inc.description.trim(), action_taken: inc.action_taken.trim() || null })
              setInc({ incident_date: todayYmd(), description: '', action_taken: '' })
              return next
            })
          }
        >
          {t('hostels.addIncident')}
        </Button>
      </div>
      <ul className="mt-3 space-y-1 text-sm">
        {r.incidents.map((i) => (
          <li key={i.id}>
            <span className="text-slate-500">{formatDate(i.incident_date)}</span> — {i.description}
            {i.action_taken ? <span className="text-slate-500"> · {i.action_taken}</span> : null}
          </li>
        ))}
      </ul>
    </Panel>
  )
}

function FeesPanel({ r }: { r: ResidentDetail }) {
  const { t } = useTranslation()
  const act = useAct(r.id)
  const [fee, setFee] = useState({ fee_period: '', amount_due: '', due_date: '' })
  const [pay, setPay] = useState<Record<number, { amount: string; mode: PaymentMode }>>({})
  return (
    <Panel id="rp-fees" title={t('hostels.fees')}>
      <ul className="space-y-3">
        {r.fees.map((f) => {
          const p = pay[f.id] ?? { amount: '', mode: 'cash' as PaymentMode }
          return (
            <li key={f.id} className="rounded-lg border border-slate-100 p-3 text-sm dark:border-slate-800">
              <p className="flex flex-wrap justify-between gap-2">
                <span className="font-medium">{f.fee_period}</span>
                <span>
                  {formatMoney(f.paid)} / {formatMoney(f.amount_due)} · <span className={f.status === 'paid' ? 'text-emerald-700' : 'text-amber-700'}>{t(`hostels.feeStatus.${f.status}`)}</span>
                </span>
              </p>
              {f.payments.length ? (
                <p className="mt-1 text-xs text-slate-500">
                  {f.payments.map((x) => `${formatDate(x.paid_on)} ${formatMoney(x.amount)} (${t(`hostels.mode.${x.payment_mode}`)})`).join(' · ')}
                </p>
              ) : null}
              {f.balance > 0 ? (
                <div className="mt-2 flex flex-wrap items-end gap-2">
                  <Field id={`fp-amt-${f.id}`} label={t('finance.payAmount')}>
                    <Input id={`fp-amt-${f.id}`} type="number" inputMode="decimal" min={0} step="0.01" placeholder={String(f.balance)} value={p.amount} onChange={(e) => setPay({ ...pay, [f.id]: { ...p, amount: e.target.value } })} />
                  </Field>
                  <Field id={`fp-mode-${f.id}`} label={t('finance.paymentMode')}>
                    <Select id={`fp-mode-${f.id}`} value={p.mode} onChange={(e) => setPay({ ...pay, [f.id]: { ...p, mode: e.target.value as PaymentMode } })}>
                      {(['cash', 'online', 'cheque', 'other'] as const).map((m) => (
                        <option key={m} value={m}>
                          {t(`hostels.mode.${m}`)}
                        </option>
                      ))}
                    </Select>
                  </Field>
                  <Button
                    disabled={act.isPending || !(Number(p.amount) > 0)}
                    onClick={() =>
                      act.mutate(async () => {
                        const next = await payFee(f.id, { amount: Number(p.amount), payment_mode: p.mode, paid_on: null, remarks: null })
                        setPay({ ...pay, [f.id]: { amount: '', mode: 'cash' } })
                        return next
                      })
                    }
                  >
                    {t('finance.recordPayment')}
                  </Button>
                </div>
              ) : null}
            </li>
          )
        })}
      </ul>
      <div className="mt-3 grid gap-2 sm:grid-cols-3">
        <Field id="hf-period" label={t('hostels.feePeriod')}>
          <Input id="hf-period" value={fee.fee_period} maxLength={50} placeholder={t('hostels.feePeriodHint')} onChange={(e) => setFee({ ...fee, fee_period: e.target.value })} />
        </Field>
        <Field id="hf-amount" label={t('hostels.amountDue')}>
          <Input id="hf-amount" type="number" inputMode="decimal" min={0} step="0.01" value={fee.amount_due} onChange={(e) => setFee({ ...fee, amount_due: e.target.value })} />
        </Field>
        <Field id="hf-due" label={t('hostels.dueDate')}>
          <Input id="hf-due" type="date" value={fee.due_date} onChange={(e) => setFee({ ...fee, due_date: e.target.value })} />
        </Field>
        <Button
          variant="secondary"
          className="sm:col-span-3"
          disabled={act.isPending || !fee.fee_period.trim() || !(Number(fee.amount_due) > 0)}
          onClick={() =>
            act.mutate(async () => {
              const next = await addFee(r.id, { fee_period: fee.fee_period.trim(), amount_due: Number(fee.amount_due), due_date: fee.due_date || null })
              setFee({ fee_period: '', amount_due: '', due_date: '' })
              return next
            })
          }
        >
          {t('hostels.addFee')}
        </Button>
      </div>
    </Panel>
  )
}

function DeparturePanel({ r }: { r: ResidentDetail }) {
  const { t } = useTranslation()
  const act = useAct(r.id)
  const [v, setV] = useState({
    departure_date: r.alumnus?.departure_date ?? todayYmd(),
    departure_reason: r.alumnus?.departure_reason ?? '',
    current_status: r.alumnus?.current_status ?? '',
    contact_number: r.alumnus?.contact_number ?? '',
    notes: r.alumnus?.notes ?? '',
  })
  const n = (s: string) => s.trim() || null
  return (
    <Panel id="rp-depart" title={r.is_active ? t('hostels.leaving') : t('hostels.alumni')}>
      <div className="grid gap-2 sm:grid-cols-2">
        {r.is_active ? (
          <>
            <Field id="dp-date" label={t('hostels.leftOn')}>
              <Input id="dp-date" type="date" max={todayYmd()} value={v.departure_date} onChange={(e) => setV({ ...v, departure_date: e.target.value })} />
            </Field>
            <Field id="dp-reason" label={t('hostels.whyLeaving')}>
              <Input id="dp-reason" value={v.departure_reason} maxLength={255} onChange={(e) => setV({ ...v, departure_reason: e.target.value })} />
            </Field>
          </>
        ) : (
          <p className="text-sm text-slate-600 sm:col-span-2 dark:text-slate-300">
            {t('hostels.leftLine', { date: formatDate(r.alumnus?.departure_date), reason: r.alumnus?.departure_reason ?? '—' })}
          </p>
        )}
        <Field id="dp-status" label={t('hostels.nowDoing')}>
          <Input id="dp-status" value={v.current_status} maxLength={255} onChange={(e) => setV({ ...v, current_status: e.target.value })} />
        </Field>
        <Field id="dp-contact" label={t('hostels.contactLater')}>
          <Input id="dp-contact" type="tel" value={v.contact_number} maxLength={50} onChange={(e) => setV({ ...v, contact_number: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="dp-notes" label={t('hostels.notes')}>
            <Textarea id="dp-notes" rows={2} maxLength={2000} value={v.notes} onChange={(e) => setV({ ...v, notes: e.target.value })} />
          </Field>
        </div>
        <Button
          variant={r.is_active ? 'secondary' : 'primary'}
          className="sm:col-span-2"
          disabled={act.isPending}
          onClick={() =>
            act.mutate(() =>
              r.is_active
                ? departResident(r.id, { departure_date: v.departure_date, departure_reason: n(v.departure_reason), current_status: n(v.current_status), contact_number: n(v.contact_number), notes: n(v.notes) })
                : updateAlumnus(r.id, { current_status: n(v.current_status), contact_number: n(v.contact_number), notes: n(v.notes) }),
            )
          }
        >
          {r.is_active ? t('hostels.markLeft') : t('common.save')}
        </Button>
      </div>
    </Panel>
  )
}

export function ResidentPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const residentId = Number(id)
  const toast = useToast()
  const queryClient = useQueryClient()
  const q = useQuery({ queryKey: ['hostel-resident', residentId], queryFn: () => fetchResident(residentId) })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const r = q.data

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to={`/hostels/${r.hostel.id}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {r.hostel.name}
      </Link>
      <div>
        <h1 className="text-2xl font-semibold">{r.name}</h1>
        <p className="text-sm text-slate-500">
          {[r.school_name, r.class_grade, r.district, r.room ? t('hostels.roomBed', { room: r.room.room_number, bed: r.room.bed_label ?? '—' }) : null, !r.is_active ? t('hostels.left') : null]
            .filter(Boolean)
            .join(' · ')}
        </p>
        <p className="mt-1 text-sm">{t('hostels.last30', { present: r.attendance_30_days.present, absent: r.attendance_30_days.absent, leave: r.attendance_30_days.on_leave })}</p>
      </div>
      {r.is_active ? <BedPanel key={`bed-${r.room?.room_id}`} r={r} /> : null}
      {r.is_active ? <OutPanel key={`out-${r.leaves.length}-${r.out?.since}`} r={r} /> : null}
      <HealthPanel key={`h-${r.incidents.length}`} r={r} />
      <FeesPanel key={`f-${r.fees.length}`} r={r} />
      <Panel id="rp-profile" title={t('hostels.profile')}>
        <ResidentForm
          key={r.is_active ? 'a' : 'l'}
          initial={{
            name: r.name, school_name: r.school_name, class_grade: r.class_grade, district: r.district, gender: r.gender, date_of_birth: r.date_of_birth,
            admission_date: r.admission_date, guardian_name: r.guardian_name, guardian_phone: r.guardian_phone,
          }}
          submitLabel={t('common.save')}
          onSubmit={async (v) => {
            queryClient.setQueryData(['hostel-resident', r.id], await updateResident(r.id, v))
            toast.success(t('form.saved'))
          }}
        />
      </Panel>
      <DeparturePanel key={`d-${r.is_active}`} r={r} />
    </div>
  )
}
