import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import QRCode from 'qrcode'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchDepartmentOptions } from '@/features/org/api'
import { actionErrorMessage } from '@/lib/forms'
import { fetchDepartmentEmployees } from './api'
import { bulkMove, fetchIdCards, photoUrl, type IdCard } from './profileApi'

function Qr({ value, label }: { value: string; label: string }) {
  const [src, setSrc] = useState<string | null>(null)
  useEffect(() => {
    void QRCode.toDataURL(value, { margin: 0, width: 160, errorCorrectionLevel: 'M' }).then(setSrc)
  }, [value])
  return src ? <img src={src} alt={label} className="size-20" /> : <span className="size-20" />
}

function Card({ c, org }: { c: IdCard; org: string }) {
  const { t } = useTranslation()
  const verify = `${window.location.origin}/verify/employee/${c.public_token}`
  return (
    <article className="flex h-[54mm] w-[86mm] break-inside-avoid flex-col overflow-hidden rounded-lg border border-slate-300 bg-white text-slate-900 print:rounded-none">
      <header className="bg-brand-700 px-3 py-1.5 text-center text-xs font-semibold text-white">{org}</header>
      <div className="flex flex-1 gap-3 p-2.5">
        <div className="h-[30mm] w-[24mm] shrink-0 overflow-hidden rounded bg-slate-100">{c.has_photo ? <img src={photoUrl(c.id)} alt="" className="size-full object-cover" /> : null}</div>
        <div className="min-w-0 flex-1 text-[10px] leading-tight">
          <p className="text-sm leading-tight font-bold">{c.name}</p>
          <p>{c.designation}</p>
          <p className="text-slate-600">{c.department}</p>
          <p className="text-slate-600">{c.institution}</p>
          <p className="mt-1 font-mono">{c.employee_code}</p>
          {c.blood_group ? (
            <p>
              {t('profile.fields.blood_group')}: {c.blood_group}
            </p>
          ) : null}
          {c.emergency_contact_phone ? (
            <p>
              {t('idCards.emergency')}: {c.emergency_contact_phone}
            </p>
          ) : null}
        </div>
        <div className="flex flex-col items-center justify-end">
          <Qr value={verify} label={t('idCards.qr', { name: c.name })} />
        </div>
      </div>
    </article>
  )
}

/** Choose people (by department or a list), then print cards or move them to another department. */
export function IdCardsPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user, can } = useAuth()
  const orgKey = user?.current_organization?.id
  const [params] = useSearchParams()
  const presetIds = (params.get('ids') ?? '').split(',').map(Number).filter(Boolean)
  const [departmentId, setDepartmentId] = useState<number | ''>('')
  const [picked, setPicked] = useState<number[]>(presetIds)
  const [cards, setCards] = useState<{ data: IdCard[]; organization: { name: string } } | null>(null)
  const [busy, setBusy] = useState(false)
  const [target, setTarget] = useState<number | ''>('')
  const [reason, setReason] = useState('')
  const departments = useQuery({ queryKey: ['department-options', orgKey], queryFn: fetchDepartmentOptions, enabled: can('departments.view') })
  const people = useQuery({
    queryKey: ['employees', orgKey, 'pick', departmentId],
    queryFn: () => fetchDepartmentEmployees(Number(departmentId)),
    enabled: departmentId !== '',
  })

  useEffect(() => {
    if (presetIds.length) void load({ ids: presetIds })
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only for the ids in the link
  }, [])

  async function load(p: { ids?: number[]; department_id?: number }) {
    setBusy(true)
    try {
      setCards(await fetchIdCards(p))
    } catch (e) {
      toast.error(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(false)
    }
  }

  const move = async () => {
    if (!target || picked.length === 0) return
    try {
      const r = await bulkMove({ employee_ids: picked, department_id: Number(target), reason: reason.trim() })
      toast.success(t('idCards.moved', { count: r.moved }))
      void queryClient.invalidateQueries({ queryKey: ['employees'] })
      setPicked([])
    } catch (e) {
      toast.error(actionErrorMessage(e, t('common.actionFailed')))
    }
  }

  const list = people.data ?? []
  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <div className="print:hidden">
        <h1 className="text-2xl font-semibold">{t('idCards.title')}</h1>
        <div className="mt-4 flex flex-wrap items-end gap-3">
          <Field id="ic-dept" label={t('people.department')}>
            <Select
              id="ic-dept"
              value={departmentId}
              onChange={(e) => {
                setDepartmentId(e.target.value ? Number(e.target.value) : '')
                setPicked([])
              }}
            >
              <option value="">{t('people.chooseDepartment')}</option>
              {(departments.data ?? []).map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                  {d.institution ? ` — ${d.institution.name}` : ''}
                </option>
              ))}
            </Select>
          </Field>
          <Button disabled={busy || (!picked.length && departmentId === '')} onClick={() => void load(picked.length ? { ids: picked } : { department_id: Number(departmentId) })}>
            {busy ? <Spinner label={t('common.loading')} /> : picked.length ? t('idCards.showPicked', { count: picked.length }) : t('idCards.showDepartment')}
          </Button>
          {cards?.data.length ? (
            <Button variant="secondary" onClick={() => window.print()}>
              <Printer aria-hidden className="size-4" />
              {t('idCards.print')}
            </Button>
          ) : null}
        </div>

        {departmentId !== '' ? (
          <fieldset className="mt-4 rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
            <legend className="px-1 text-sm font-medium">{t('idCards.pick')}</legend>
            <div className="grid max-h-64 gap-1 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">
              {list.map((e) => (
                <label key={e.id} className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={picked.includes(e.id)} onChange={() => setPicked(picked.includes(e.id) ? picked.filter((x) => x !== e.id) : [...picked, e.id])} />
                  {e.name} <span className="font-mono text-xs text-slate-500">{e.employee_code}</span>
                </label>
              ))}
            </div>
            {can('employees.edit') && picked.length ? (
              <div className="mt-3 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                <Field id="ic-target" label={t('idCards.moveTo')}>
                  <Select id="ic-target" value={target} onChange={(e) => setTarget(e.target.value ? Number(e.target.value) : '')}>
                    <option value="">{t('people.chooseDepartment')}</option>
                    {(departments.data ?? []).map((d) => (
                      <option key={d.id} value={d.id}>
                        {d.name}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field id="ic-reason" label={t('common.reasonOptional')}>
                  <Input id="ic-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
                </Field>
                <Button variant="secondary" disabled={!target} onClick={() => void move()}>
                  {t('idCards.move', { count: picked.length })}
                </Button>
              </div>
            ) : null}
          </fieldset>
        ) : null}
      </div>
      {cards ? (
        cards.data.length === 0 ? (
          <p className="text-sm text-slate-500">{t('people.noEmployees')}</p>
        ) : (
          <div className="flex flex-wrap gap-4 print:gap-2">
            {cards.data.map((c) => (
              <Card key={c.id} c={c} org={cards.organization.name} />
            ))}
          </div>
        )
      ) : null}
    </div>
  )
}
