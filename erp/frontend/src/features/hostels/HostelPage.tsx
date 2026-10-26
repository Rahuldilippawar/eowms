import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ClipboardCheck, Download, Pencil, Plus, Upload, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router-dom'
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
import { RecipientPicker } from '@/features/letters/LetterBits'
import type { Recipient } from '@/features/letters/api'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatDate, formatMoney } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { addResident, fetchHostel, fetchResidents, residentsCsvUrl, saveRoom, SESSIONS, setHostelStaff, type HostelDetail, type ResidentInput } from './api'
import { HostelDialog } from './HostelsPage'

export function ResidentForm({ initial, submitLabel, onSubmit }: { initial: ResidentInput; submitLabel: string; onSubmit: (v: ResidentInput) => Promise<unknown> }) {
  const { t } = useTranslation()
  const [v, setV] = useState(initial)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const set = (k: keyof ResidentInput) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value === '' ? null : e.target.value })
  const fields: [keyof ResidentInput, string, string][] = [
    ['name', 'hostels.residentName', 'text'],
    ['school_name', 'hostels.school', 'text'],
    ['class_grade', 'hostels.class', 'text'],
    ['district', 'hostels.district', 'text'],
    ['date_of_birth', 'hostels.dob', 'date'],
    ['admission_date', 'hostels.admitted', 'date'],
    ['guardian_name', 'hostels.guardian', 'text'],
    ['guardian_phone', 'hostels.guardianPhone', 'tel'],
  ]
  return (
    <form
      className="grid gap-3 sm:grid-cols-2"
      onSubmit={async (e) => {
        e.preventDefault()
        if (!v.name?.trim()) return setErrors({ name: t('form.required') })
        setBusy(true)
        try {
          await onSubmit(v)
          setErrors({})
          setGeneral(null)
        } catch (err) {
          const r = splitServerErrors(err, fields.map((f) => f[0]).concat(['gender']), t('common.actionFailed'))
          setErrors(r.byField)
          setGeneral(r.general)
        } finally {
          setBusy(false)
        }
      }}
    >
      {general ? (
        <p role="alert" className="text-sm text-red-600 sm:col-span-2">
          {general}
        </p>
      ) : null}
      {fields.map(([k, label, type]) => (
        <Field key={k} id={`rs-${k}`} label={t(label)} error={errors[k]}>
          <Input id={`rs-${k}`} type={type} value={v[k] ?? ''} maxLength={255} onChange={set(k)} />
        </Field>
      ))}
      <Field id="rs-gender" label={t('hostels.genderLabel')} error={errors.gender}>
        <Select id="rs-gender" value={v.gender ?? ''} onChange={(e) => setV({ ...v, gender: (e.target.value || null) as ResidentInput['gender'] })}>
          <option value="">—</option>
          <option value="male">{t('hostels.male')}</option>
          <option value="female">{t('hostels.female')}</option>
        </Select>
      </Field>
      <div className="flex items-end">
        <Button type="submit" disabled={busy}>
          {busy ? <Spinner label={t('common.saving')} /> : submitLabel}
        </Button>
      </div>
    </form>
  )
}

const blankResident: ResidentInput = { name: '', school_name: null, class_grade: null, district: null, gender: null, date_of_birth: null, admission_date: null, guardian_name: null, guardian_phone: null }

function StaffEditor({ hostel, onSaved }: { hostel: HostelDetail; onSaved: (h: HostelDetail) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [staff, setStaff] = useState(hostel.staff.map((s) => ({ user_id: s.user_id, name: s.name ?? '', role: s.role })))
  const [pick, setPick] = useState<Recipient | null>(null)
  const save = useMutation({
    mutationFn: () => setHostelStaff(hostel.id, staff.map((s) => ({ user_id: s.user_id, role: s.role || 'warden' }))),
    onSuccess: (h) => {
      onSaved(h)
      toast.success(t('form.saved'))
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  return (
    <div className="space-y-3">
      <ul className="space-y-2">
        {staff.map((s, i) => (
          <li key={s.user_id} className="flex flex-wrap items-end gap-2">
            <span className="min-w-40 flex-1 py-2 text-sm font-medium">{s.name}</span>
            <Field id={`st-role-${s.user_id}`} label={t('hostels.role')}>
              <Input id={`st-role-${s.user_id}`} value={s.role} maxLength={30} onChange={(e) => setStaff(staff.map((x, j) => (j === i ? { ...x, role: e.target.value } : x)))} />
            </Field>
            <Button variant="ghost" aria-label={t('hostels.removeStaff', { name: s.name })} onClick={() => setStaff(staff.filter((_, j) => j !== i))}>
              <X aria-hidden className="size-4" />
            </Button>
          </li>
        ))}
      </ul>
      <div className="flex flex-wrap items-end gap-2">
        <div className="min-w-56 flex-1">
          <RecipientPicker label={t('hostels.addStaff')} value={pick} onChange={setPick} />
        </div>
        <Button
          variant="secondary"
          disabled={!pick || staff.some((s) => s.user_id === pick.user_id)}
          onClick={() => {
            if (pick) setStaff([...staff, { user_id: pick.user_id, name: pick.name, role: 'warden' }])
            setPick(null)
          }}
        >
          {t('common.add')}
        </Button>
      </div>
      <Button disabled={save.isPending} onClick={() => save.mutate()}>
        {t('hostels.saveStaff')}
      </Button>
    </div>
  )
}

function RoomDialog({ hostel, room, onClose, onSaved }: { hostel: HostelDetail; room: HostelDetail['rooms'][number] | null; onClose: () => void; onSaved: (h: HostelDetail) => void }) {
  const { t } = useTranslation()
  const [number, setNumber] = useState(room?.room_number ?? '')
  const [capacity, setCapacity] = useState(room?.capacity.toString() ?? '2')
  const [active, setActive] = useState(room?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => saveRoom(hostel.id, room?.id ?? null, { room_number: number.trim(), capacity: Number(capacity), is_active: active }),
    onSuccess: (h) => {
      onSaved(h)
      onClose()
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={room ? t('hostels.editRoom') : t('hostels.newRoom')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending || !number.trim() || !(Number(capacity) >= 1)} onClick={() => save.mutate()}>
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {error ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {error}
          </p>
        ) : null}
        <Field id="rm-number" label={t('hostels.roomNumber')}>
          <Input id="rm-number" value={number} maxLength={50} onChange={(e) => setNumber(e.target.value)} />
        </Field>
        <Field id="rm-capacity" label={t('hostels.beds')}>
          <Input id="rm-capacity" type="number" inputMode="numeric" min={1} max={50} value={capacity} onChange={(e) => setCapacity(e.target.value)} />
        </Field>
        {room ? (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
            {t('maintenance.inUse')}
          </label>
        ) : null}
      </div>
    </Modal>
  )
}

export function HostelPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const hostelId = Number(id)
  const navigate = useNavigate()
  const toast = useToast()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const q = useQuery({ queryKey: ['hostel', hostelId], queryFn: () => fetchHostel(hostelId) })
  const [tab, setTab] = useState<'residents' | 'rooms' | 'staff'>('residents')
  const [status, setStatus] = useState('active')
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const residents = useQuery({ queryKey: ['hostel-residents', hostelId, status, debounced], queryFn: () => fetchResidents(hostelId, status, debounced), placeholderData: keepPreviousData, enabled: tab === 'residents' })
  const [editing, setEditing] = useState(false)
  const [adding, setAdding] = useState(false)
  const [room, setRoom] = useState<HostelDetail['rooms'][number] | 'new' | null>(null)
  const store = (h: HostelDetail) => {
    queryClient.setQueryData(['hostel', h.id], h)
    void queryClient.invalidateQueries({ queryKey: ['hostels'] })
  }

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const h = q.data
  const tabs = (['residents', 'rooms', 'staff'] as const).filter((k) => k !== 'staff' || h.can_manage)

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <Link to="/hostels" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.hostels')}
      </Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{h.name}</h1>
          <p className="text-sm text-slate-500">
            {[t(`hostels.gender.${h.gender}`), h.department?.name, h.staff.length ? t('hostels.staffList', { names: h.staff.map((s) => s.name).join(', ') }) : null].filter(Boolean).join(' · ')}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {h.can_care ? (
            <Button onClick={() => navigate(`/hostels/${h.id}/attendance`)}>
              <ClipboardCheck aria-hidden className="size-4" />
              {t('hostels.rollCall')}
            </Button>
          ) : null}
          {h.can_manage ? (
            <Button variant="secondary" onClick={() => setEditing(true)}>
              <Pencil aria-hidden className="size-4" />
              {t('common.edit')}
            </Button>
          ) : null}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[
          [t('hostels.residents'), String(h.stats.residents)],
          [t('hostels.bedsUsed'), `${h.stats.occupied_beds} / ${h.stats.beds}`],
          [t('hostels.out'), `${h.stats.out_now}${h.stats.overdue_returns ? ` · ${t('hostels.lateCount', { count: h.stats.overdue_returns })}` : ''}`],
          [t('hostels.feesDue'), formatMoney(h.stats.fees_due)],
        ].map(([label, value]) => (
          <div key={label} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
            <p className="text-lg font-semibold tabular-nums">{value}</p>
            <p className="text-xs text-slate-500">{label}</p>
          </div>
        ))}
      </div>
      <section aria-labelledby="hs-today" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="hs-today" className="mb-2 font-semibold">
          {t('hostels.todayRollCalls')}
        </h2>
        <ul className="grid gap-2 sm:grid-cols-3">
          {SESSIONS.map((s) => {
            const x = h.stats.today[s]
            return (
              <li key={s} className={cn('rounded-lg px-3 py-2 text-sm', x ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200')}>
                <span className="font-medium">{t(`hostels.session.${s}`)}</span> —{' '}
                {x ? t('hostels.rollSummary', { present: x.present, absent: x.absent, leave: x.on_leave }) : t('hostels.notTaken')}
              </li>
            )
          })}
        </ul>
      </section>

      <div role="tablist" className="inline-flex flex-wrap rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
        {tabs.map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={tab === k}
            onClick={() => setTab(k)}
            className={cn('min-h-9 rounded-md px-4 text-sm font-medium', tab === k ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
          >
            {t(`hostels.tabs.${k}`)}
          </button>
        ))}
      </div>

      {tab === 'residents' ? (
        <section className="space-y-3">
          <div className="flex flex-wrap items-end gap-3">
            <Field id="hr-status" label={t('attendance.statusLabel')}>
              <Select id="hr-status" value={status} onChange={(e) => setStatus(e.target.value)}>
                {(['active', 'out', 'left'] as const).map((s) => (
                  <option key={s} value={s}>
                    {t(`hostels.filter.${s}`)}
                  </option>
                ))}
              </Select>
            </Field>
            <div className="min-w-48 flex-1">
              <Field id="hr-search" label={t('common.search')}>
                <Input id="hr-search" type="search" value={search} placeholder={t('hostels.searchHint')} onChange={(e) => setSearch(e.target.value)} />
              </Field>
            </div>
            {h.can_care ? (
              <>
                <Link to={`/hostels/${h.id}/import`} className="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
                  <Upload aria-hidden className="size-4" />
                  {t('hostels.import')}
                </Link>
                <Button onClick={() => setAdding(true)}>
                  <Plus aria-hidden className="size-4" />
                  {t('hostels.newResident')}
                </Button>
              </>
            ) : null}
            {can('hostels.export') ? (
              <a href={residentsCsvUrl(h.id)} className="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
                <Download aria-hidden className="size-4" />
                {t('attendance.downloadCsv')}
              </a>
            ) : null}
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            {residents.isPending ? <LoadingState /> : null}
            {residents.isError ? <ErrorState onRetry={() => void residents.refetch()} /> : null}
            {residents.data && residents.data.length === 0 ? <EmptyState message={t('hostels.noResidents')} /> : null}
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {(residents.data ?? []).map((r) => (
                <li key={r.id}>
                  <Link to={`/hostel-residents/${r.id}`} className="flex flex-col gap-1 px-4 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-slate-800">
                    <span className="min-w-0">
                      <span className="block font-medium">{r.name}</span>
                      <span className="block text-sm text-slate-500">{[r.school_name, r.class_grade, r.district].filter(Boolean).join(' · ')}</span>
                    </span>
                    <span className="flex shrink-0 flex-wrap items-center gap-3 text-sm">
                      {r.room ? <span>{t('hostels.roomBed', { room: r.room.room_number, bed: r.room.bed_label ?? '—' })}</span> : r.is_active ? <span className="text-amber-700">{t('hostels.noBed')}</span> : null}
                      {r.out ? (
                        <span className={r.out.overdue ? 'font-medium text-red-700' : 'text-slate-600'}>
                          {r.out.overdue ? t('hostels.lateBack', { date: formatDate(r.out.expected_return_date) }) : t('hostels.outSince', { date: formatDate(r.out.since) })}
                        </span>
                      ) : null}
                      {!r.is_active ? <span className="text-slate-500">{t('hostels.left')}</span> : null}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        </section>
      ) : null}

      {tab === 'rooms' ? (
        <section className="space-y-3">
          {h.can_manage ? (
            <Button onClick={() => setRoom('new')}>
              <Plus aria-hidden className="size-4" />
              {t('hostels.newRoom')}
            </Button>
          ) : null}
          {h.rooms.length === 0 ? <EmptyState message={t('hostels.noRooms')} /> : null}
          <ul className="grid gap-2 sm:grid-cols-3">
            {h.rooms.map((r) => (
              <li key={r.id} className="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-800 dark:bg-slate-900">
                <span>
                  <span className="font-medium">{t('hostels.roomN', { room: r.room_number })}</span>
                  <span className={cn('block', r.occupied >= r.capacity ? 'text-red-700' : 'text-slate-500')}>{t('hostels.occupancy', { occupied: r.occupied, capacity: r.capacity })}</span>
                  {!r.is_active ? <span className="block text-xs text-slate-500">{t('maintenance.notInUse')}</span> : null}
                </span>
                {h.can_manage ? (
                  <button type="button" className="font-medium text-brand-700 hover:underline" onClick={() => setRoom(r)}>
                    {t('common.edit')}
                  </button>
                ) : null}
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {tab === 'staff' && h.can_manage ? (
        <section className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <p className="mb-3 text-sm text-slate-500">{t('hostels.staffHint')}</p>
          <StaffEditor key={h.staff.map((s) => s.user_id).join(',')} hostel={h} onSaved={store} />
        </section>
      ) : null}

      {editing ? <HostelDialog hostel={h} onClose={() => setEditing(false)} /> : null}
      {room ? <RoomDialog hostel={h} room={room === 'new' ? null : room} onClose={() => setRoom(null)} onSaved={store} /> : null}
      {adding ? (
        <Modal open onOpenChange={(o) => !o && setAdding(false)} title={t('hostels.newResident')}>
          <ResidentForm
            initial={blankResident}
            submitLabel={t('common.save')}
            onSubmit={async (v) => {
              const r = await addResident(h.id, v)
              void queryClient.invalidateQueries({ queryKey: ['hostel-residents', h.id] })
              void queryClient.invalidateQueries({ queryKey: ['hostel', h.id] })
              toast.success(t('form.saved'))
              setAdding(false)
              navigate(`/hostel-residents/${r.id}`)
            }}
          />
        </Modal>
      ) : null}
    </div>
  )
}
