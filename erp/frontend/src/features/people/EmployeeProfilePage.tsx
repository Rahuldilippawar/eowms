import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Camera, Eye, IdCard, Trash2 } from 'lucide-react'
import { useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { parseApiError } from '@/lib/api'
import { actionErrorMessage } from '@/lib/forms'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { AttendanceTab } from './AttendanceTab'
import { CustomFieldInputs } from './CustomFieldInputs'
import {
  addAchievement,
  deleteAchievement,
  deleteDocument,
  deleteFamily,
  DOCUMENT_TYPES,
  documentUrl,
  fetchProfile,
  photoUrl,
  PROFILE_FIELDS,
  revealPrivate,
  saveFamily,
  SELF_FIELDS,
  updatePrivate,
  updateProfile,
  uploadDocument,
  uploadPhoto,
  type CustomValues,
  type EmployeeProfile,
  type ProfileField,
} from './profileApi'

type Tab = 'personal' | 'private' | 'attendance' | 'documents' | 'family' | 'achievements'

/** Options for the profile fields that are fixed lists (values match the API). */
const CHOICES: Partial<Record<ProfileField, readonly string[]>> = {
  employment_type: ['permanent', 'probation', 'contract', 'temporary', 'part_time', 'honorary', 'volunteer'],
  teaching_mode: ['teaching', 'non_teaching'],
  gender: ['female', 'male', 'other'],
  marital_status: ['single', 'married', 'widowed', 'divorced'],
  blood_group: ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
}
const DATES: ProfileField[] = ['date_of_birth', 'left_date']
const LONG: ProfileField[] = ['local_address', 'permanent_address']

function useSave(p: EmployeeProfile, mine: boolean) {
  const queryClient = useQueryClient()
  return (next: EmployeeProfile) => {
    queryClient.setQueryData(['employee-profile', mine ? 'me' : p.id], next)
    void queryClient.invalidateQueries({ queryKey: ['employees'] })
  }
}

function Section({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
  return (
    <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-semibold">{title}</h2>
        {action}
      </div>
      {children}
    </section>
  )
}

function PersonalTab({ p, mine }: { p: EmployeeProfile; mine: boolean }) {
  const { t } = useTranslation()
  const toast = useToast()
  const store = useSave(p, mine)
  const editable: ProfileField[] = p.can.edit ? [...PROFILE_FIELDS] : p.can.self ? SELF_FIELDS : []
  const initial = () => Object.fromEntries(PROFILE_FIELDS.map((f) => [f, f === 'is_disabled' ? p.is_disabled : (p[f] ?? '')])) as Record<ProfileField, string | boolean>
  const [v, setV] = useState(initial)
  const [custom, setCustom] = useState<CustomValues>(p.custom ?? {})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  const save = async () => {
    setBusy(true)
    setErrors({})
    try {
      const input: Record<string, unknown> = {}
      for (const f of editable) input[f] = f === 'is_disabled' ? v[f] : (v[f] as string).trim() || null
      if (p.can.edit) input.custom = custom
      store(await updateProfile(p.can.edit ? p.id : 'me', input))
      toast.success(t('form.saved'))
    } catch (e) {
      const r = splitServerErrors(e, [...PROFILE_FIELDS, 'custom'], t('common.actionFailed'))
      setErrors(r.byField)
      if (r.general) toast.error(r.general)
    } finally {
      setBusy(false)
    }
  }

  const control = (f: ProfileField) => {
    const id = `pf-${f}`
    const disabled = !editable.includes(f)
    if (f === 'is_disabled')
      return (
        <label key={f} className="flex min-h-10 items-center gap-2 text-sm">
          <input type="checkbox" disabled={disabled} checked={!!v.is_disabled} onChange={(e) => setV({ ...v, is_disabled: e.target.checked })} />
          {t('profile.fields.is_disabled')}
        </label>
      )
    const value = v[f] as string
    return (
      <div key={f} className={LONG.includes(f) ? 'sm:col-span-2' : undefined}>
        <Field id={id} label={t(`profile.fields.${f}`)} error={errors[f]}>
          {CHOICES[f] ? (
            <Select id={id} disabled={disabled} value={value} onChange={(e) => setV({ ...v, [f]: e.target.value })}>
              <option value="">—</option>
              {CHOICES[f]!.map((o) => (
                <option key={o} value={o}>
                  {f === 'blood_group' ? o : t(`profile.choices.${f}.${o}`)}
                </option>
              ))}
            </Select>
          ) : LONG.includes(f) ? (
            <Textarea id={id} rows={2} disabled={disabled} value={value} onChange={(e) => setV({ ...v, [f]: e.target.value })} />
          ) : (
            <Input id={id} type={DATES.includes(f) ? 'date' : 'text'} disabled={disabled} value={value} aria-invalid={!!errors[f] || undefined} onChange={(e) => setV({ ...v, [f]: e.target.value })} />
          )}
        </Field>
      </div>
    )
  }

  return (
    <Section title={t('profile.tabs.personal')}>
      {!p.can.edit && p.can.self ? <p className="text-sm text-slate-500">{t('profile.selfHint')}</p> : null}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {PROFILE_FIELDS.map(control)}
        <CustomFieldInputs entity="employee" values={custom} onChange={setCustom} errors={errors} disabled={!p.can.edit} />
      </div>
      {editable.length ? (
        <Button disabled={busy} onClick={() => void save()}>
          {busy ? <Spinner label={t('common.saving')} /> : t('common.save')}
        </Button>
      ) : null}
    </Section>
  )
}

function PrivateTab({ p, mine }: { p: EmployeeProfile; mine: boolean }) {
  const { t } = useTranslation()
  const toast = useToast()
  const store = useSave(p, mine)
  const d = p.private!
  const [revealed, setRevealed] = useState<{ aadhaar_number: string | null; bank_account_no: string | null } | null>(null)
  const [v, setV] = useState({
    aadhaar_number: '',
    bank_account_no: '',
    ration_card: d.ration_card ?? '',
    ifsc_code: d.ifsc_code ?? '',
    bank_name: d.bank_name ?? '',
    bank_branch: d.bank_branch ?? '',
    uan_no: d.uan_no ?? '',
    esic_no: d.esic_no ?? '',
    monthly_salary: d.monthly_salary?.toString() ?? '',
    pf_applicable: !!d.pf_applicable,
    esic_applicable: !!d.esic_applicable,
    pt_applicable: !!d.pt_applicable,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  const save = async () => {
    setBusy(true)
    setErrors({})
    try {
      const n = (s: string) => s.trim() || null
      const input: Record<string, unknown> = {
        ration_card: n(v.ration_card),
        ifsc_code: n(v.ifsc_code),
        bank_name: n(v.bank_name),
        bank_branch: n(v.bank_branch),
        uan_no: n(v.uan_no),
        esic_no: n(v.esic_no),
        monthly_salary: v.monthly_salary === '' ? null : Number(v.monthly_salary),
        pf_applicable: v.pf_applicable,
        esic_applicable: v.esic_applicable,
        pt_applicable: v.pt_applicable,
      }
      // Blank secret fields mean "keep the stored number".
      if (v.aadhaar_number.trim()) input.aadhaar_number = v.aadhaar_number.trim()
      if (v.bank_account_no.trim()) input.bank_account_no = v.bank_account_no.trim()
      store(await updatePrivate(p, input))
      setV({ ...v, aadhaar_number: '', bank_account_no: '' })
      setRevealed(null)
      toast.success(t('form.saved'))
    } catch (e) {
      const r = splitServerErrors(e, Object.keys(v), t('common.actionFailed'))
      setErrors(r.byField)
      if (r.general) toast.error(r.general)
    } finally {
      setBusy(false)
    }
  }

  const reveal = async () => {
    try {
      setRevealed(await revealPrivate(p))
    } catch (e) {
      toast.error(actionErrorMessage(e, t('common.actionFailed')))
    }
  }

  const text = (k: keyof typeof v, label: string, extra?: { type?: string; placeholder?: string }) => (
    <Field id={`pv-${k}`} label={label} error={errors[k]}>
      <Input
        id={`pv-${k}`}
        type={extra?.type ?? 'text'}
        placeholder={extra?.placeholder}
        disabled={!p.can.edit_private}
        autoComplete="off"
        value={v[k] as string}
        aria-invalid={!!errors[k] || undefined}
        onChange={(e) => setV({ ...v, [k]: e.target.value })}
      />
    </Field>
  )

  return (
    <Section
      title={t('profile.tabs.private')}
      action={
        <Button variant="secondary" className="min-h-8 px-3 py-1 text-xs" onClick={() => void reveal()}>
          <Eye aria-hidden className="size-4" />
          {t('profile.reveal')}
        </Button>
      }
    >
      <p className="text-sm text-slate-500">{t('profile.privateHint')}</p>
      <dl className="grid gap-2 text-sm sm:grid-cols-2">
        <div>
          <dt className="text-slate-500">{t('profile.private.aadhaar')}</dt>
          <dd className="font-mono">{revealed ? (revealed.aadhaar_number ?? '—') : (d.aadhaar_masked ?? '—')}</dd>
        </div>
        <div>
          <dt className="text-slate-500">{t('profile.private.bank_account')}</dt>
          <dd className="font-mono">{revealed ? (revealed.bank_account_no ?? '—') : (d.bank_account_masked ?? '—')}</dd>
        </div>
      </dl>
      {p.can.edit_private ? (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {text('aadhaar_number', t('profile.private.newAadhaar'), { placeholder: t('profile.private.keep'), type: 'text' })}
            {text('bank_account_no', t('profile.private.newBankAccount'), { placeholder: t('profile.private.keep') })}
            {text('ifsc_code', t('profile.private.ifsc'))}
            {text('bank_name', t('profile.private.bank_name'))}
            {text('bank_branch', t('profile.private.bank_branch'))}
            {text('ration_card', t('profile.private.ration_card'))}
            {text('uan_no', t('profile.private.uan'))}
            {text('esic_no', t('profile.private.esic'))}
            {text('monthly_salary', t('profile.private.salary'), { type: 'number' })}
          </div>
          <div className="flex flex-wrap gap-4 text-sm">
            {(['pf_applicable', 'esic_applicable', 'pt_applicable'] as const).map((k) => (
              <label key={k} className="flex items-center gap-2">
                <input type="checkbox" checked={v[k]} onChange={(e) => setV({ ...v, [k]: e.target.checked })} />
                {t(`profile.private.${k}`)}
              </label>
            ))}
          </div>
          <Button disabled={busy} onClick={() => void save()}>
            {busy ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      ) : (
        <dl className="grid gap-2 text-sm sm:grid-cols-3">
          {(['ifsc_code', 'bank_name', 'bank_branch', 'uan_no', 'esic_no'] as const).map((k) => (
            <div key={k}>
              <dt className="text-slate-500">{t(`profile.private.${k === 'ifsc_code' ? 'ifsc' : k === 'uan_no' ? 'uan' : k === 'esic_no' ? 'esic' : k}`)}</dt>
              <dd>{d[k] ?? '—'}</dd>
            </div>
          ))}
        </dl>
      )}
    </Section>
  )
}

function DocumentsTab({ p, mine }: { p: EmployeeProfile; mine: boolean }) {
  const { t } = useTranslation()
  const toast = useToast()
  const store = useSave(p, mine)
  const fileRef = useRef<HTMLInputElement>(null)
  const [type, setType] = useState<string>('education')
  const [title, setTitle] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const canUpload = p.can.edit || p.can.self

  const upload = async () => {
    const file = fileRef.current?.files?.[0]
    if (!file) return setError(t('form.required'))
    if (file.size > 5 * 1024 * 1024) return setError(t('leave.fileTooBig'))
    setBusy(true)
    setError(null)
    try {
      store(await uploadDocument(p, { document_type: type, title: title.trim(), file }))
      if (fileRef.current) fileRef.current.value = ''
      setTitle('')
      toast.success(t('profile.uploaded'))
    } catch (e) {
      setError(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Section title={t('profile.tabs.documents')}>
      {p.documents.length === 0 ? <p className="text-sm text-slate-500">{t('profile.noDocuments')}</p> : null}
      <ul className="divide-y divide-slate-100 dark:divide-slate-800">
        {p.documents.map((d) => (
          <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
            <span>
              <span className="font-medium">{t(`profile.docTypes.${d.document_type}`)}</span>
              {d.title ? ` — ${d.title}` : ''}{' '}
              <a href={documentUrl(d.id)} className="text-brand-700 hover:underline">
                {d.original_name}
              </a>
            </span>
            {p.can.edit || p.can.self ? (
              <Button
                variant="ghost"
                className="min-h-8 px-2 py-1 text-xs text-red-700"
                aria-label={t('work.deleteNamed', { name: d.original_name })}
                onClick={async () => {
                  try {
                    store(await deleteDocument(d.id))
                  } catch (e) {
                    toast.error(actionErrorMessage(e, t('common.actionFailed')))
                  }
                }}
              >
                <Trash2 aria-hidden className="size-4" />
              </Button>
            ) : null}
          </li>
        ))}
      </ul>
      {canUpload ? (
        <div className="grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-4 sm:items-end dark:border-slate-800">
          <Field id="doc-type" label={t('profile.docType')}>
            <Select id="doc-type" value={type} onChange={(e) => setType(e.target.value)}>
              {DOCUMENT_TYPES.map((d) => (
                <option key={d} value={d}>
                  {t(`profile.docTypes.${d}`)}
                </option>
              ))}
            </Select>
          </Field>
          <Field id="doc-title" label={t('profile.docTitle')}>
            <Input id="doc-title" value={title} maxLength={255} onChange={(e) => setTitle(e.target.value)} />
          </Field>
          <Field id="doc-file" label={t('leave.attachment')} error={error ?? undefined}>
            <input id="doc-file" ref={fileRef} type="file" accept=".pdf,.jpg,.jpeg,.png" className="block w-full text-sm" />
          </Field>
          <Button disabled={busy} onClick={() => void upload()}>
            {busy ? <Spinner label={t('common.saving')} /> : t('profile.upload')}
          </Button>
        </div>
      ) : null}
    </Section>
  )
}

function FamilyTab({ p, mine }: { p: EmployeeProfile; mine: boolean }) {
  const { t } = useTranslation()
  const toast = useToast()
  const store = useSave(p, mine)
  const empty = { name: '', relation: '', date_of_birth: '', phone: '', lives_on_campus: false }
  const [editing, setEditing] = useState<number | null>(null)
  const [v, setV] = useState(empty)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const canEdit = p.can.edit || p.can.self

  const save = async () => {
    try {
      store(await saveFamily(p, editing, { ...v, date_of_birth: v.date_of_birth || null, phone: v.phone || null }))
      setV(empty)
      setEditing(null)
      setErrors({})
    } catch (e) {
      setErrors(splitServerErrors(e, ['name', 'relation', 'date_of_birth', 'phone'], t('common.actionFailed')).byField)
    }
  }

  return (
    <Section title={t('profile.tabs.family')}>
      {p.family_members.length === 0 ? <p className="text-sm text-slate-500">{t('profile.noFamily')}</p> : null}
      <ul className="divide-y divide-slate-100 dark:divide-slate-800">
        {p.family_members.map((m) => (
          <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
            <span>
              <span className="font-medium">{m.name}</span> · {m.relation}
              {m.date_of_birth ? ` · ${formatDate(m.date_of_birth)}` : ''}
              {m.phone ? ` · ${m.phone}` : ''}
              {m.lives_on_campus ? ` · ${t('profile.livesOnCampus')}` : ''}
            </span>
            {canEdit ? (
              <span className="flex gap-1">
                <Button
                  variant="ghost"
                  className="min-h-8 px-2 py-1 text-xs"
                  onClick={() => {
                    setEditing(m.id)
                    setV({ name: m.name, relation: m.relation, date_of_birth: m.date_of_birth ?? '', phone: m.phone ?? '', lives_on_campus: m.lives_on_campus })
                  }}
                >
                  {t('common.edit')}
                </Button>
                <Button variant="ghost" className="min-h-8 px-2 py-1 text-xs text-red-700" aria-label={t('work.deleteNamed', { name: m.name })} onClick={async () => store(await deleteFamily(p, m.id))}>
                  <Trash2 aria-hidden className="size-4" />
                </Button>
              </span>
            ) : null}
          </li>
        ))}
      </ul>
      {canEdit ? (
        <div className="grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-5 sm:items-end dark:border-slate-800">
          <Field id="fm-name" label={t('org.name')} error={errors.name}>
            <Input id="fm-name" value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />
          </Field>
          <Field id="fm-rel" label={t('profile.relation')} error={errors.relation}>
            <Input id="fm-rel" value={v.relation} onChange={(e) => setV({ ...v, relation: e.target.value })} />
          </Field>
          <Field id="fm-dob" label={t('people.dob')} error={errors.date_of_birth}>
            <Input id="fm-dob" type="date" value={v.date_of_birth} onChange={(e) => setV({ ...v, date_of_birth: e.target.value })} />
          </Field>
          <Field id="fm-phone" label={t('org.phone')} error={errors.phone}>
            <Input id="fm-phone" type="tel" value={v.phone} onChange={(e) => setV({ ...v, phone: e.target.value })} />
          </Field>
          <label className="flex min-h-10 items-center gap-2 text-sm">
            <input type="checkbox" checked={v.lives_on_campus} onChange={(e) => setV({ ...v, lives_on_campus: e.target.checked })} />
            {t('profile.livesOnCampus')}
          </label>
          <div className="flex gap-2 sm:col-span-5">
            <Button variant="secondary" disabled={!v.name.trim() || !v.relation.trim()} onClick={() => void save().catch(() => toast.error(t('common.actionFailed')))}>
              {editing ? t('common.save') : t('profile.addMember')}
            </Button>
            {editing ? (
              <Button
                variant="ghost"
                onClick={() => {
                  setEditing(null)
                  setV(empty)
                }}
              >
                {t('common.cancel')}
              </Button>
            ) : null}
          </div>
        </div>
      ) : null}
    </Section>
  )
}

function AchievementsTab({ p, mine }: { p: EmployeeProfile; mine: boolean }) {
  const { t } = useTranslation()
  const toast = useToast()
  const store = useSave(p, mine)
  const [v, setV] = useState({ title: '', achieved_on: '', description: '' })

  return (
    <Section title={t('profile.tabs.achievements')}>
      {p.achievements.length === 0 ? <p className="text-sm text-slate-500">{t('profile.noAchievements')}</p> : null}
      <ul className="divide-y divide-slate-100 dark:divide-slate-800">
        {p.achievements.map((a) => (
          <li key={a.id} className="flex flex-wrap items-start justify-between gap-2 py-2 text-sm">
            <span>
              <span className="font-medium">{a.title}</span>
              {a.achieved_on ? ` · ${formatDate(a.achieved_on)}` : ''}
              {a.description ? <span className="block text-slate-600 dark:text-slate-400">{a.description}</span> : null}
            </span>
            {p.can.edit ? (
              <Button variant="ghost" className="min-h-8 px-2 py-1 text-xs text-red-700" aria-label={t('work.deleteNamed', { name: a.title })} onClick={async () => store(await deleteAchievement(p, a.id))}>
                <Trash2 aria-hidden className="size-4" />
              </Button>
            ) : null}
          </li>
        ))}
      </ul>
      {p.can.edit ? (
        <div className="grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-3 sm:items-end dark:border-slate-800">
          <Field id="ac-title" label={t('profile.achievement')}>
            <Input id="ac-title" value={v.title} onChange={(e) => setV({ ...v, title: e.target.value })} />
          </Field>
          <Field id="ac-date" label={t('attendance.date')}>
            <Input id="ac-date" type="date" value={v.achieved_on} onChange={(e) => setV({ ...v, achieved_on: e.target.value })} />
          </Field>
          <Button
            variant="secondary"
            disabled={!v.title.trim()}
            onClick={async () => {
              try {
                store(await addAchievement(p, { title: v.title.trim(), achieved_on: v.achieved_on || null, description: v.description || null }))
                setV({ title: '', achieved_on: '', description: '' })
              } catch (e) {
                toast.error(actionErrorMessage(e, t('common.actionFailed')))
              }
            }}
          >
            {t('profile.addAchievement')}
          </Button>
        </div>
      ) : null}
    </Section>
  )
}

export function EmployeeProfilePage({ mine = false }: { mine?: boolean }) {
  const { t } = useTranslation()
  const toast = useToast()
  const params = useParams()
  const key = mine ? 'me' : Number(params.id)
  const q = useQuery({ queryKey: ['employee-profile', key], queryFn: () => fetchProfile(key as number | 'me') })
  const [tab, setTab] = useState<Tab>('personal')
  const [photoVersion, setPhotoVersion] = useState(0)
  const photoRef = useRef<HTMLInputElement>(null)
  const queryClient = useQueryClient()

  if (q.isPending) return <LoadingState />
  if (q.isError) {
    const status = parseApiError(q.error).status
    if (status === 404) return <EmptyState message={mine ? t('attendance.noEmployee') : t('profile.notFound')} />
    return <ErrorState onRetry={() => void q.refetch()} />
  }
  const p = q.data
  const tabs: Tab[] = ['personal', ...(p.private ? (['private'] as Tab[]) : []), 'attendance', 'documents', 'family', 'achievements']

  const onPhoto = async () => {
    const file = photoRef.current?.files?.[0]
    if (!file) return
    if (file.size > 2 * 1024 * 1024) return toast.error(t('profile.photoTooBig'))
    try {
      queryClient.setQueryData(['employee-profile', key], await uploadPhoto(p, file))
      setPhotoVersion((x) => x + 1)
      toast.success(t('profile.photoSaved'))
    } catch (e) {
      toast.error(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      if (photoRef.current) photoRef.current.value = ''
    }
  }

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      {!mine ? (
        <Link to="/employees" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
          <ArrowLeft aria-hidden className="size-4" />
          {t('nav.employees')}
        </Link>
      ) : null}
      <div className="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:flex-row sm:items-center dark:border-slate-800 dark:bg-slate-900">
        <div className="relative size-24 shrink-0 overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-800">
          {p.has_photo ? <img src={photoUrl(p.id, photoVersion)} alt={t('profile.photoOf', { name: p.name })} className="size-full object-cover" /> : null}
          {p.can.edit || p.can.self ? (
            <>
              <input ref={photoRef} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" id="photo-input" onChange={() => void onPhoto()} />
              <label htmlFor="photo-input" className="absolute right-1 bottom-1 cursor-pointer rounded-full bg-white/90 p-1.5 shadow dark:bg-slate-900/90" title={t('profile.changePhoto')}>
                <Camera aria-hidden className="size-4" />
                <span className="sr-only">{t('profile.changePhoto')}</span>
              </label>
            </>
          ) : null}
        </div>
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-semibold">{p.name}</h1>
          <p className="text-sm text-slate-600 dark:text-slate-400">
            <span className="font-mono">{p.employee_code}</span>
            {p.designation ? ` · ${p.designation}` : ''} · {p.department.name}
            {p.institution ? ` · ${p.institution.name}` : ''}
          </p>
          <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <Badge tone={p.is_active ? 'green' : 'slate'}>{p.is_active ? t('org.active') : t('org.inactive')}</Badge>
            {p.reporting_officer ? <span className="text-slate-500">{t('people.reportingOfficer')}: {p.reporting_officer.name}</span> : null}
            {p.joining_date ? <span className="text-slate-500">{t('people.joiningDate')}: {formatDate(p.joining_date)}</span> : null}
          </div>
        </div>
        {!mine && p.public_token ? (
          <Link to={`/employees/id-cards?ids=${p.id}`} className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
            <IdCard aria-hidden className="size-4" />
            {t('profile.idCard')}
          </Link>
        ) : null}
      </div>

      <div role="tablist" aria-label={t('profile.sections')} className="flex gap-1 overflow-x-auto rounded-lg bg-slate-100 p-1 text-sm dark:bg-slate-800">
        {tabs.map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={tab === k}
            onClick={() => setTab(k)}
            className={cn('shrink-0 rounded-md px-3 py-1.5 font-medium', tab === k ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-600 dark:text-slate-400')}
          >
            {t(`profile.tabs.${k}`)}
          </button>
        ))}
      </div>
      {tab === 'personal' ? <PersonalTab key={p.id} p={p} mine={mine} /> : null}
      {tab === 'private' && p.private ? <PrivateTab key={p.id} p={p} mine={mine} /> : null}
      {tab === 'attendance' ? <AttendanceTab employeeId={p.id} mine={mine} /> : null}
      {tab === 'documents' ? <DocumentsTab p={p} mine={mine} /> : null}
      {tab === 'family' ? <FamilyTab p={p} mine={mine} /> : null}
      {tab === 'achievements' ? <AchievementsTab p={p} mine={mine} /> : null}
    </div>
  )
}
