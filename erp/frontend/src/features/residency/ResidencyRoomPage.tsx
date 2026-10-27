import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
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
import { RecipientPicker } from '@/features/letters/LetterBits'
import type { Recipient } from '@/features/letters/api'
import { formatDate, formatMoney, monthYm, todayYmd } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'
import {
  addGuest,
  addInspection,
  addItem,
  addOccupant,
  createBill,
  fetchRoom,
  fetchRooms,
  guestLeft,
  markSharePaid,
  removeItem,
  transferOccupant,
  vacateOccupant,
  type Condition,
  type Occupant,
  type RoomDetail,
} from './api'

const card = 'rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'
const TABS = ['occupants', 'bills', 'items', 'inspections', 'guests'] as const
type Tab = (typeof TABS)[number]

function useRoomForm(fields: string[], run: () => Promise<RoomDetail>, onSaved: (r: RoomDetail) => void) {
  const { t } = useTranslation()
  const toast = useToast()
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const m = useMutation({
    mutationFn: run,
    onSuccess: (r) => {
      onSaved(r)
      setErrors({})
      setGeneral(null)
      toast.success(t('form.saved'))
    },
    onError: (e) => {
      const r = splitServerErrors(e, fields, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  return { errors, general, m }
}

function Footer({ onClose, busy, onSave, label }: { onClose: () => void; busy: boolean; onSave: () => void; label?: string }) {
  const { t } = useTranslation()
  return (
    <>
      <Button variant="secondary" onClick={onClose}>
        {t('common.cancel')}
      </Button>
      <Button disabled={busy} onClick={onSave}>
        {busy ? <Spinner label={t('common.saving')} /> : (label ?? t('common.save'))}
      </Button>
    </>
  )
}

function Alert({ text }: { text: string | null }) {
  return text ? (
    <p role="alert" className="text-sm text-red-600 sm:col-span-2">
      {text}
    </p>
  ) : null
}

function AddOccupantDialog({ room, onClose, onSaved }: { room: RoomDetail; onClose: () => void; onSaved: (r: RoomDetail) => void }) {
  const { t } = useTranslation()
  const heads = room.occupants.filter((o) => o.status === 'active' && o.occupant_type === 'staff')
  const [type, setType] = useState<'staff' | 'family'>('staff')
  const [pick, setPick] = useState<Recipient | null>(null)
  const [v, setV] = useState({ family_of_id: heads[0]?.id.toString() ?? '', name: '', relation: '', age: '', moved_in_date: todayYmd() })
  const f = useRoomForm(
    ['user_id', 'family_of_id', 'name', 'relation', 'age', 'moved_in_date', 'location_id'],
    () =>
      addOccupant(
        room.id,
        type === 'staff'
          ? { occupant_type: 'staff', user_id: pick?.user_id, moved_in_date: v.moved_in_date || null }
          : { occupant_type: 'family', family_of_id: Number(v.family_of_id), name: v.name.trim(), relation: v.relation.trim() || null, age: v.age === '' ? null : Number(v.age), moved_in_date: v.moved_in_date || null },
      ),
    (r) => {
      onSaved(r)
      onClose()
    },
  )
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={t('residency.addOccupant')} footer={<Footer onClose={onClose} busy={f.m.isPending} onSave={() => f.m.mutate()} />}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Alert text={f.general ?? f.errors.location_id ?? null} />
        <Field id="ao-type" label={t('residency.occupantType')}>
          <Select id="ao-type" value={type} onChange={(e) => setType(e.target.value as 'staff' | 'family')}>
            <option value="staff">{t('residency.type.staff')}</option>
            <option value="family" disabled={heads.length === 0}>
              {t('residency.type.family')}
            </option>
          </Select>
        </Field>
        <Field id="ao-date" label={t('residency.movedIn')} error={f.errors.moved_in_date}>
          <Input id="ao-date" type="date" max={todayYmd()} value={v.moved_in_date} onChange={(e) => setV({ ...v, moved_in_date: e.target.value })} />
        </Field>
        {type === 'staff' ? (
          <div className="sm:col-span-2">
            <RecipientPicker label={t('residency.staffMember')} value={pick} onChange={setPick} error={f.errors.user_id} />
          </div>
        ) : (
          <>
            <Field id="ao-head" label={t('residency.familyOf')} error={f.errors.family_of_id}>
              <Select id="ao-head" value={v.family_of_id} onChange={(e) => setV({ ...v, family_of_id: e.target.value })}>
                {heads.map((h) => (
                  <option key={h.id} value={h.id}>
                    {h.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field id="ao-name" label={t('residency.name')} error={f.errors.name}>
              <Input id="ao-name" maxLength={255} value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />
            </Field>
            <Field id="ao-relation" label={t('residency.relation')} error={f.errors.relation}>
              <Input id="ao-relation" maxLength={100} value={v.relation} onChange={(e) => setV({ ...v, relation: e.target.value })} />
            </Field>
            <Field id="ao-age" label={t('residency.age')} error={f.errors.age}>
              <Input id="ao-age" type="number" inputMode="numeric" min={0} max={120} value={v.age} onChange={(e) => setV({ ...v, age: e.target.value })} />
            </Field>
          </>
        )}
      </div>
    </Modal>
  )
}

function MoveDialog({ room, occupant, mode, onClose, onSaved }: { room: RoomDetail; occupant: Occupant; mode: 'vacate' | 'transfer'; onClose: () => void; onSaved: (r: RoomDetail) => void }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [to, setTo] = useState('')
  const [note, setNote] = useState('')
  const rooms = useQuery({ queryKey: ['residency-rooms', user?.current_organization?.id, '', '', false], queryFn: () => fetchRooms({}), enabled: mode === 'transfer' })
  const f = useRoomForm(
    ['to_location_id', 'location_id', 'remark', 'reason', 'occupant'],
    () => (mode === 'vacate' ? vacateOccupant(occupant.id, note.trim() || null) : transferOccupant(occupant.id, Number(to), note.trim() || null)),
    (r) => {
      onSaved(r)
      onClose()
    },
  )
  const family = room.occupants.filter((o) => o.family_of_id === occupant.id && o.status === 'active').length
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={t(mode === 'vacate' ? 'residency.vacateNamed' : 'residency.transferNamed', { name: occupant.name })}
      footer={<Footer onClose={onClose} busy={f.m.isPending} onSave={() => (mode === 'transfer' && !to ? undefined : f.m.mutate())} label={t(mode === 'vacate' ? 'residency.vacate' : 'residency.transfer')} />}
    >
      <div className="grid gap-3">
        <Alert text={f.general ?? f.errors.location_id ?? f.errors.occupant ?? null} />
        {family > 0 ? <p className="text-sm text-slate-600">{t('residency.familyMovesToo', { count: family })}</p> : null}
        {mode === 'transfer' ? (
          <Field id="mv-to" label={t('residency.toRoom')} error={f.errors.to_location_id}>
            <Select id="mv-to" value={to} onChange={(e) => setTo(e.target.value)}>
              <option value="">—</option>
              {(rooms.data?.data ?? [])
                .filter((r) => r.id !== room.id)
                .map((r) => (
                  <option key={r.id} value={r.id}>
                    {[r.building?.name, r.name].filter(Boolean).join(' · ')}
                    {r.capacity !== null ? ` (${r.occupants_count}/${r.capacity})` : ''}
                  </option>
                ))}
            </Select>
          </Field>
        ) : null}
        <Field id="mv-note" label={t(mode === 'vacate' ? 'residency.remark' : 'residency.reason')} error={f.errors.remark ?? f.errors.reason}>
          <Input id="mv-note" maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}

function BillDialog({ room, onClose, onSaved }: { room: RoomDetail; onClose: () => void; onSaved: (r: RoomDetail) => void }) {
  const { t } = useTranslation()
  const [v, setV] = useState({ billing_month: monthYm(), room_rent: '', electricity_bill: '', water_bill: '' })
  const num = (s: string) => (s === '' ? 0 : Number(s))
  const f = useRoomForm(
    ['billing_month', 'room_rent', 'electricity_bill', 'water_bill', 'location_id'],
    () => createBill(room.id, { billing_month: v.billing_month, room_rent: num(v.room_rent), electricity_bill: num(v.electricity_bill), water_bill: num(v.water_bill) }),
    (r) => {
      onSaved(r)
      onClose()
    },
  )
  const staff = room.occupants.filter((o) => o.status === 'active' && o.occupant_type === 'staff').length
  const total = num(v.room_rent) + num(v.electricity_bill) + num(v.water_bill)
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={t('residency.newBill')} footer={<Footer onClose={onClose} busy={f.m.isPending} onSave={() => f.m.mutate()} />}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Alert text={f.general ?? f.errors.location_id ?? null} />
        <Field id="bl-month" label={t('residency.month')} error={f.errors.billing_month}>
          <Input id="bl-month" type="month" value={v.billing_month} onChange={(e) => setV({ ...v, billing_month: e.target.value })} />
        </Field>
        {(['room_rent', 'electricity_bill', 'water_bill'] as const).map((k) => (
          <Field key={k} id={`bl-${k}`} label={t(`residency.bill.${k}`)} error={f.errors[k]}>
            <Input id={`bl-${k}`} type="number" inputMode="decimal" min={0} step="0.01" value={v[k]} onChange={(e) => setV({ ...v, [k]: e.target.value })} />
          </Field>
        ))}
        <p className="text-sm text-slate-600 sm:col-span-2">{staff > 0 ? t('residency.splitHint', { total: formatMoney(total), count: staff, each: formatMoney(total / staff) }) : t('residency.noStaffToBill')}</p>
      </div>
    </Modal>
  )
}

function ItemDialog({ room, onClose, onSaved }: { room: RoomDetail; onClose: () => void; onSaved: (r: RoomDetail) => void }) {
  const { t } = useTranslation()
  const [v, setV] = useState({ item_name: '', ownership: 'institution' as 'institution' | 'personal', quantity: '1', remarks: '' })
  const f = useRoomForm(
    ['item_name', 'ownership', 'quantity', 'remarks'],
    () => addItem(room.id, { item_name: v.item_name.trim(), ownership: v.ownership, quantity: Number(v.quantity) || 1, remarks: v.remarks.trim() || null }),
    (r) => {
      onSaved(r)
      onClose()
    },
  )
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={t('residency.addItem')} footer={<Footer onClose={onClose} busy={f.m.isPending} onSave={() => f.m.mutate()} />}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Alert text={f.general} />
        <Field id="it-name" label={t('residency.itemName')} error={f.errors.item_name}>
          <Input id="it-name" maxLength={255} value={v.item_name} onChange={(e) => setV({ ...v, item_name: e.target.value })} />
        </Field>
        <Field id="it-own" label={t('residency.ownership')}>
          <Select id="it-own" value={v.ownership} onChange={(e) => setV({ ...v, ownership: e.target.value as 'institution' | 'personal' })}>
            <option value="institution">{t('residency.own.institution')}</option>
            <option value="personal">{t('residency.own.personal')}</option>
          </Select>
        </Field>
        <Field id="it-qty" label={t('residency.quantity')} error={f.errors.quantity}>
          <Input id="it-qty" type="number" inputMode="numeric" min={1} value={v.quantity} onChange={(e) => setV({ ...v, quantity: e.target.value })} />
        </Field>
        <Field id="it-remarks" label={t('residency.remark')} error={f.errors.remarks}>
          <Input id="it-remarks" maxLength={500} value={v.remarks} onChange={(e) => setV({ ...v, remarks: e.target.value })} />
        </Field>
      </div>
    </Modal>
  )
}

function InspectionDialog({ room, onClose, onSaved }: { room: RoomDetail; onClose: () => void; onSaved: (r: RoomDetail) => void }) {
  const { t } = useTranslation()
  const [v, setV] = useState({ inspection_date: todayYmd(), condition_rating: 'good' as Condition, remarks: '' })
  const f = useRoomForm(
    ['inspection_date', 'condition_rating', 'remarks'],
    () => addInspection(room.id, { ...v, remarks: v.remarks.trim() || null }),
    (r) => {
      onSaved(r)
      onClose()
    },
  )
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={t('residency.addInspection')} footer={<Footer onClose={onClose} busy={f.m.isPending} onSave={() => f.m.mutate()} />}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Alert text={f.general} />
        <Field id="in-date" label={t('residency.date')} error={f.errors.inspection_date}>
          <Input id="in-date" type="date" max={todayYmd()} value={v.inspection_date} onChange={(e) => setV({ ...v, inspection_date: e.target.value })} />
        </Field>
        <Field id="in-rating" label={t('residency.condition')}>
          <Select id="in-rating" value={v.condition_rating} onChange={(e) => setV({ ...v, condition_rating: e.target.value as Condition })}>
            {(['excellent', 'good', 'fair', 'poor'] as const).map((c) => (
              <option key={c} value={c}>
                {t(`residency.rating.${c}`)}
              </option>
            ))}
          </Select>
        </Field>
        <div className="sm:col-span-2">
          <Field id="in-remarks" label={t('residency.remark')} error={f.errors.remarks}>
            <Input id="in-remarks" maxLength={2000} value={v.remarks} onChange={(e) => setV({ ...v, remarks: e.target.value })} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}

function GuestDialog({ room, onClose, onSaved }: { room: RoomDetail; onClose: () => void; onSaved: (r: RoomDetail) => void }) {
  const { t } = useTranslation()
  const hosts = room.occupants.filter((o) => o.status === 'active')
  const [v, setV] = useState({ guest_name: '', guest_contact: '', relation: '', purpose: '', host: hosts[0]?.id.toString() ?? '', arrival_date: todayYmd(), expected_departure_date: '' })
  const f = useRoomForm(
    ['guest_name', 'guest_contact', 'relation', 'purpose', 'host_occupant_id', 'arrival_date', 'expected_departure_date'],
    () =>
      addGuest(room.id, {
        guest_name: v.guest_name.trim(),
        guest_contact: v.guest_contact.trim() || null,
        relation: v.relation.trim() || null,
        purpose: v.purpose.trim() || null,
        host_occupant_id: v.host ? Number(v.host) : null,
        arrival_date: v.arrival_date,
        expected_departure_date: v.expected_departure_date || null,
      }),
    (r) => {
      onSaved(r)
      onClose()
    },
  )
  const text = (k: 'guest_name' | 'guest_contact' | 'relation' | 'purpose', label: string, max: number) => (
    <Field id={`gs-${k}`} label={t(label)} error={f.errors[k]}>
      <Input id={`gs-${k}`} maxLength={max} value={v[k]} onChange={(e) => setV({ ...v, [k]: e.target.value })} />
    </Field>
  )
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={t('residency.addGuest')} footer={<Footer onClose={onClose} busy={f.m.isPending} onSave={() => f.m.mutate()} />}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Alert text={f.general} />
        {text('guest_name', 'residency.guestName', 255)}
        {text('guest_contact', 'residency.contact', 50)}
        {text('relation', 'residency.relation', 100)}
        {text('purpose', 'residency.purpose', 255)}
        <Field id="gs-host" label={t('residency.host')} error={f.errors.host_occupant_id}>
          <Select id="gs-host" value={v.host} onChange={(e) => setV({ ...v, host: e.target.value })}>
            <option value="">—</option>
            {hosts.map((h) => (
              <option key={h.id} value={h.id}>
                {h.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="gs-arrival" label={t('residency.arrival')} error={f.errors.arrival_date}>
          <Input id="gs-arrival" type="date" value={v.arrival_date} onChange={(e) => setV({ ...v, arrival_date: e.target.value })} />
        </Field>
        <Field id="gs-departure" label={t('residency.expectedDeparture')} error={f.errors.expected_departure_date}>
          <Input id="gs-departure" type="date" min={v.arrival_date} value={v.expected_departure_date} onChange={(e) => setV({ ...v, expected_departure_date: e.target.value })} />
        </Field>
      </div>
    </Modal>
  )
}

type Dialog = { kind: 'occupant' | 'bill' | 'item' | 'inspection' | 'guest' } | { kind: 'vacate' | 'transfer'; occupant: Occupant }

export function ResidencyRoomPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const { id } = useParams()
  const roomId = Number(id)
  const key = ['residency-room', user?.current_organization?.id, roomId]
  const q = useQuery({ queryKey: key, queryFn: () => fetchRoom(roomId) })
  const [tab, setTab] = useState<Tab>('occupants')
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const [showPast, setShowPast] = useState(false)
  const store = (r: RoomDetail) => {
    queryClient.setQueryData(['residency-room', user?.current_organization?.id, r.id], r)
    void queryClient.invalidateQueries({ queryKey: ['residency-rooms'] })
  }
  const act = useMutation({
    mutationFn: (fn: () => Promise<RoomDetail>) => fn(),
    onSuccess: store,
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const paid = useMutation({
    mutationFn: ({ share, value }: { share: number; value: boolean }) => markSharePaid(share, value),
    onSuccess: (bill) => {
      queryClient.setQueryData<RoomDetail>(key, (r) => (r ? { ...r, bills: r.bills.map((b) => (b.id === bill.id ? bill : b)) } : r))
      void queryClient.invalidateQueries({ queryKey: ['residency-bills'] })
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const r = q.data
  const active = r.occupants.filter((o) => o.status === 'active')
  const past = r.occupants.filter((o) => o.status !== 'active')
  const shown = showPast ? r.occupants : active
  const close = () => setDialog(null)
  const addButton = (kind: 'occupant' | 'bill' | 'item' | 'inspection' | 'guest', label: string, disabled = false) =>
    r.can_manage ? (
      <Button disabled={disabled} onClick={() => setDialog({ kind })}>
        <Plus aria-hidden className="size-4" />
        {t(label)}
      </Button>
    ) : null

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/residency" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('residency.allRooms')}
      </Link>
      <div>
        <h1 className="text-2xl font-semibold">{r.name}</h1>
        <p className="text-sm text-slate-500">
          {[r.building?.name, r.location_code, r.capacity !== null ? t('residency.occupancy', { count: active.length, capacity: r.capacity }) : t('residency.occupants', { count: active.length })]
            .filter(Boolean)
            .join(' · ')}
        </p>
      </div>

      <div role="tablist" className="inline-flex flex-wrap rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
        {TABS.map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={tab === k}
            onClick={() => setTab(k)}
            className={cn('min-h-9 rounded-md px-4 text-sm font-medium', tab === k ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
          >
            {t(`residency.roomTabs.${k}`)}
          </button>
        ))}
      </div>

      {tab === 'occupants' ? (
        <section className="space-y-3">
          <div className="flex flex-wrap items-center gap-3">
            {addButton('occupant', 'residency.addOccupant', r.capacity !== null && active.length >= r.capacity)}
            {past.length ? (
              <label className="inline-flex min-h-10 items-center gap-2 text-sm">
                <input type="checkbox" checked={showPast} onChange={(e) => setShowPast(e.target.checked)} />
                {t('residency.showPast', { count: past.length })}
              </label>
            ) : null}
          </div>
          <div className={card}>
            {shown.length === 0 ? <EmptyState message={t('residency.vacant')} /> : null}
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {shown.map((o) => (
                <li key={o.id} className={cn('flex flex-wrap items-center justify-between gap-2 py-2', o.occupant_type === 'family' && 'ps-6')}>
                  <span className="min-w-0 text-sm">
                    <span className="font-medium">{o.name}</span>{' '}
                    <Badge tone={o.occupant_type === 'staff' ? 'blue' : 'slate'}>{o.occupant_type === 'staff' ? t('residency.type.staff') : (o.relation ?? t('residency.type.family'))}</Badge>
                    {o.occupant_type === 'staff' && !o.user ? <span className="ms-2 text-amber-700">{t('residency.notLinked')}</span> : null}
                    <span className="block text-slate-500">
                      {o.status === 'active'
                        ? t('residency.since', { date: formatDate(o.moved_in_date) })
                        : t('residency.vacatedOn', { date: formatDate(o.vacated_date) }) + (o.vacated_remark ? ` · ${o.vacated_remark}` : '')}
                    </span>
                  </span>
                  {r.can_manage && o.status === 'active' ? (
                    <span className="flex gap-2">
                      {o.occupant_type === 'staff' ? (
                        <Button variant="secondary" onClick={() => setDialog({ kind: 'transfer', occupant: o })}>
                          {t('residency.transfer')}
                        </Button>
                      ) : null}
                      <Button variant="secondary" onClick={() => setDialog({ kind: 'vacate', occupant: o })}>
                        {t('residency.vacate')}
                      </Button>
                    </span>
                  ) : null}
                </li>
              ))}
            </ul>
          </div>
          {r.transfers.length ? (
            <div className={card}>
              <h2 className="mb-2 font-semibold">{t('residency.transfers')}</h2>
              <ul className="space-y-1 text-sm">
                {r.transfers.map((x, i) => (
                  <li key={i}>
                    {t('residency.transferLine', { date: formatDate(x.transfer_date), name: x.occupant ?? '—', from: x.from ?? '—', to: x.to ?? '—' })}
                    {x.reason ? <span className="text-slate-500"> · {x.reason}</span> : null}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </section>
      ) : null}

      {tab === 'bills' ? (
        <section className="space-y-3">
          {addButton('bill', 'residency.newBill', active.every((o) => o.occupant_type !== 'staff'))}
          {r.bills.length === 0 ? <EmptyState message={t('residency.noBills')} /> : null}
          {r.bills.map((b) => (
            <div key={b.id} className={card}>
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-semibold">{b.billing_month}</h2>
                <span className="font-semibold tabular-nums">{formatMoney(b.total_amount)}</span>
              </div>
              <p className="text-sm text-slate-500">
                {t('residency.billBreakup', { rent: formatMoney(b.room_rent), electricity: formatMoney(b.electricity_bill), water: formatMoney(b.water_bill) })}
              </p>
              <ul className="mt-2 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                {b.shares.map((s) => (
                  <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>{s.occupant}</span>
                    <span className="flex items-center gap-2">
                      <span className="tabular-nums">{formatMoney(s.amount_due)}</span>
                      {r.can_manage ? (
                        <label className="inline-flex items-center gap-1">
                          <input
                            type="checkbox"
                            checked={!!s.paid_at}
                            disabled={paid.isPending}
                            aria-label={t('residency.paidNamed', { name: s.occupant ?? '', month: b.billing_month })}
                            onChange={(e) => paid.mutate({ share: s.id, value: e.target.checked })}
                          />
                          {t('residency.paid')}
                        </label>
                      ) : (
                        <Badge tone={s.paid_at ? 'green' : 'slate'}>{s.paid_at ? t('residency.paid') : t('residency.unpaid')}</Badge>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </section>
      ) : null}

      {tab === 'items' ? (
        <section className="space-y-3">
          {addButton('item', 'residency.addItem')}
          <div className={card}>
            {r.items.length === 0 ? <EmptyState message={t('residency.noItems')} /> : null}
            <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
              {r.items.map((i) => (
                <li key={i.id} className="flex items-center justify-between gap-2 py-2">
                  <span>
                    <span className="font-medium">
                      {i.item_name} × {i.quantity}
                    </span>{' '}
                    <Badge>{t(`residency.own.${i.ownership}`)}</Badge>
                    {i.remarks ? <span className="block text-slate-500">{i.remarks}</span> : null}
                  </span>
                  {r.can_manage ? (
                    <Button variant="ghost" aria-label={t('residency.removeItem', { name: i.item_name })} disabled={act.isPending} onClick={() => act.mutate(() => removeItem(i.id))}>
                      <Trash2 aria-hidden className="size-4" />
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
          </div>
        </section>
      ) : null}

      {tab === 'inspections' ? (
        <section className="space-y-3">
          {addButton('inspection', 'residency.addInspection')}
          <div className={card}>
            {r.inspections.length === 0 ? <EmptyState message={t('residency.noInspections')} /> : null}
            <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
              {r.inspections.map((i) => (
                <li key={i.id} className="py-2">
                  <span className="font-medium">{formatDate(i.inspection_date)}</span> — {t(`residency.rating.${i.condition_rating}`)}
                  <span className="block text-slate-500">{[i.remarks, i.inspected_by].filter(Boolean).join(' · ')}</span>
                </li>
              ))}
            </ul>
          </div>
        </section>
      ) : null}

      {tab === 'guests' ? (
        <section className="space-y-3">
          {addButton('guest', 'residency.addGuest', active.length === 0)}
          <div className={card}>
            {r.guests.length === 0 ? <EmptyState message={t('residency.noGuests')} /> : null}
            <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
              {r.guests.map((g) => (
                <li key={g.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span>
                    <span className="font-medium">{g.guest_name}</span>
                    <span className="block text-slate-500">
                      {[g.relation, g.purpose, g.host ? t('residency.guestOf', { name: g.host }) : null, formatDate(g.arrival_date) + ' – ' + (g.actual_departure_date ? formatDate(g.actual_departure_date) : t('residency.staying'))]
                        .filter(Boolean)
                        .join(' · ')}
                    </span>
                  </span>
                  {r.can_manage && !g.actual_departure_date ? (
                    <Button variant="secondary" disabled={act.isPending} onClick={() => act.mutate(() => guestLeft(g.id))}>
                      {t('residency.guestLeft')}
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
          </div>
        </section>
      ) : null}

      {dialog?.kind === 'occupant' ? <AddOccupantDialog room={r} onClose={close} onSaved={store} /> : null}
      {dialog?.kind === 'vacate' || dialog?.kind === 'transfer' ? <MoveDialog room={r} occupant={dialog.occupant} mode={dialog.kind} onClose={close} onSaved={store} /> : null}
      {dialog?.kind === 'bill' ? <BillDialog room={r} onClose={close} onSaved={store} /> : null}
      {dialog?.kind === 'item' ? <ItemDialog room={r} onClose={close} onSaved={store} /> : null}
      {dialog?.kind === 'inspection' ? <InspectionDialog room={r} onClose={close} onSaved={store} /> : null}
      {dialog?.kind === 'guest' ? <GuestDialog room={r} onClose={close} onSaved={store} /> : null}
    </div>
  )
}
