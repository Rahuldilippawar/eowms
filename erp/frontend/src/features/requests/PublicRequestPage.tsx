import { useMutation, useQuery } from '@tanstack/react-query'
import { CheckCircle2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { LanguageSwitcher } from '@/components/layout/LanguageSwitcher'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { CATEGORIES, fetchPublicForm, submitPublicRequest, type Category } from './api'

/** The old request.php: anyone (no login) asks a department for work. */
export function PublicRequestPage() {
  const { t } = useTranslation()
  const { org = '' } = useParams()
  const form = useQuery({ queryKey: ['public-request-form', org], queryFn: () => fetchPublicForm(org), retry: false })
  const [v, setV] = useState({ requester_name: '', requester_contact: '', department_id: '', category: 'general' as Category, location: '', work_description: '', website: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })
  const send = useMutation({
    mutationFn: () =>
      submitPublicRequest(org, {
        requester_name: v.requester_name.trim(),
        requester_contact: v.requester_contact.trim(),
        department_id: Number(v.department_id),
        category: v.category,
        location: v.location.trim() || null,
        work_description: v.work_description.trim(),
        website: v.website,
      }),
    onError: (e) => {
      const r = splitServerErrors(e, ['requester_name', 'requester_contact', 'department_id', 'location', 'work_description'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const submit = () => {
    const local: Record<string, string> = {}
    for (const k of ['requester_name', 'requester_contact', 'department_id', 'work_description'] as const) if (!v[k].trim()) local[k] = t('form.required')
    if (v.requester_contact.trim() && v.requester_contact.replace(/\D/g, '').length < 10) local.requester_contact = t('requests.phoneRule')
    setErrors(local)
    if (Object.keys(local).length === 0) send.mutate()
  }

  return (
    <div className="flex min-h-full items-start justify-center bg-slate-50 p-4 sm:items-center dark:bg-slate-950">
      <div className="w-full max-w-lg space-y-4">
        <div className="flex items-center justify-between">
          <p className="text-lg font-bold text-brand-700">{form.data?.organization.name ?? t('app.name')}</p>
          <LanguageSwitcher />
        </div>
        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h1 className="mb-1 text-lg font-semibold">{t('requests.publicTitle')}</h1>
          <p className="mb-4 text-sm text-slate-500">{t('requests.publicHint')}</p>
          {form.isPending ? <Spinner label={t('common.loading')} /> : null}
          {form.isError ? (
            <p role="alert" className="text-red-700">
              {t('requests.publicUnavailable')}
            </p>
          ) : null}
          {send.data ? (
            <div role="status" className="space-y-3 text-center">
              <CheckCircle2 aria-hidden className="mx-auto size-10 text-emerald-600" />
              <p className="font-medium">{t('requests.publicThanks')}</p>
              {send.data.request_code ? (
                <>
                  <p className="text-2xl font-semibold tracking-wide">{send.data.request_code}</p>
                  <p className="text-sm text-slate-500">{t('requests.publicKeepCode')}</p>
                </>
              ) : null}
              <Link to={`/request/${org}/track`} className="inline-block text-sm font-medium text-brand-700 hover:underline">
                {t('requests.trackLink')}
              </Link>
            </div>
          ) : form.data ? (
            <form
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault()
                submit()
              }}
            >
              {general ? (
                <p role="alert" className="text-sm text-red-600">
                  {general}
                </p>
              ) : null}
              <Field id="pr-name" label={t('requests.yourName')} error={errors.requester_name}>
                <Input id="pr-name" value={v.requester_name} maxLength={255} autoComplete="name" onChange={set('requester_name')} />
              </Field>
              <Field id="pr-phone" label={t('requests.yourPhone')} error={errors.requester_contact}>
                <Input id="pr-phone" type="tel" value={v.requester_contact} maxLength={20} autoComplete="tel" onChange={set('requester_contact')} />
              </Field>
              <Field id="pr-dept" label={t('requests.toDepartment')} error={errors.department_id}>
                <Select id="pr-dept" value={v.department_id} onChange={set('department_id')}>
                  <option value="">—</option>
                  {form.data.departments.map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.name}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field id="pr-category" label={t('requests.category')}>
                <Select id="pr-category" value={v.category} onChange={(e) => setV({ ...v, category: e.target.value as Category })}>
                  {CATEGORIES.map((c) => (
                    <option key={c} value={c}>
                      {t(`requests.categories.${c}`)}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field id="pr-location" label={t('requests.location')} error={errors.location}>
                <Input id="pr-location" value={v.location} maxLength={255} onChange={set('location')} />
              </Field>
              <Field id="pr-desc" label={t('requests.whatIsNeeded')} error={errors.work_description}>
                <Textarea id="pr-desc" rows={4} maxLength={3000} value={v.work_description} onChange={set('work_description')} />
              </Field>
              {/* Honeypot: hidden from people (and screen readers); bots fill it. */}
              <div aria-hidden="true" className="absolute -left-[10000px] h-px w-px overflow-hidden">
                <label htmlFor="pr-website">Website</label>
                <input id="pr-website" tabIndex={-1} autoComplete="off" value={v.website} onChange={set('website')} />
              </div>
              <Button type="submit" disabled={send.isPending}>
                {send.isPending ? <Spinner label={t('common.saving')} /> : t('requests.send')}
              </Button>
              <Link to={`/request/${org}/track`} className="text-center text-sm font-medium text-brand-700 hover:underline">
                {t('requests.trackLink')}
              </Link>
            </form>
          ) : null}
        </section>
      </div>
    </div>
  )
}
