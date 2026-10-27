import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { when } from '@/features/assets/when'
import { useAuth } from '@/features/auth/AuthProvider'
import { actionErrorMessage } from '@/lib/forms'
import { fetchMyTickets, lookupTag, reportProblem } from './api'

/** Anyone: find a computer, monitor or smart board by the tag printed on it and say what is wrong. */
export function ReportProblemPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const [params] = useSearchParams()
  const [tag, setTag] = useState(params.get('tag') ?? '')
  const [found, setFound] = useState<Awaited<ReturnType<typeof lookupTag>> | null>(null)
  const [description, setDescription] = useState('')
  const [error, setError] = useState<string | null>(null)
  const mine = useQuery({ queryKey: ['my-it-tickets', user?.current_organization?.id], queryFn: fetchMyTickets })
  const find = useMutation({
    mutationFn: () => lookupTag(tag.trim()),
    onSuccess: (a) => {
      setFound(a)
      setError(null)
    },
    onError: (e) => {
      setFound(null)
      setError(actionErrorMessage(e, t('common.actionFailed')))
    },
  })
  const send = useMutation({
    mutationFn: () => reportProblem(found!.id, description.trim()),
    onSuccess: () => {
      toast.success(t('it.reported'))
      setFound(null)
      setTag('')
      setDescription('')
      void queryClient.invalidateQueries({ queryKey: ['my-it-tickets'] })
    },
    onError: (e) => setError(actionErrorMessage(e, t('common.actionFailed'))),
  })

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <h1 className="text-2xl font-semibold">{t('nav.reportIt')}</h1>
      <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <p className="text-sm text-slate-600">{t('it.reportHint')}</p>
        <form
          className="flex flex-wrap items-end gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (tag.trim()) find.mutate()
          }}
        >
          <div className="min-w-48 flex-1">
            <Field id="rp-tag" label={t('it.tag')}>
              <Input id="rp-tag" maxLength={40} placeholder="PC-26-0001" value={tag} onChange={(e) => setTag(e.target.value)} />
            </Field>
          </div>
          <Button type="submit" variant="secondary" disabled={!tag.trim() || find.isPending}>
            {t('it.find')}
          </Button>
        </form>
        {error ? (
          <p role="alert" className="text-sm text-red-600">
            {error}
          </p>
        ) : null}
        {found ? (
          <div className="space-y-3">
            <p className="text-sm">
              <span className="font-medium">{found.asset_tag}</span> · {[t(`it.type.${found.asset_type}`), found.brand, found.model, found.lab, found.workstation_no].filter(Boolean).join(' · ')}
              {found.open_tickets ? <span className="block text-amber-700">{t('it.alreadyReported', { count: found.open_tickets })}</span> : null}
            </p>
            <Field id="rp-desc" label={t('it.whatIsWrong')}>
              <Textarea id="rp-desc" maxLength={2000} value={description} onChange={(e) => setDescription(e.target.value)} />
            </Field>
            <Button disabled={description.trim().length < 5 || send.isPending} onClick={() => send.mutate()}>
              {send.isPending ? <Spinner label={t('common.saving')} /> : t('it.sendReport')}
            </Button>
          </div>
        ) : null}
      </section>
      {mine.data && mine.data.length ? (
        <section aria-labelledby="rp-mine" className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <h2 id="rp-mine" className="mb-2 font-semibold">
            {t('it.myReports')}
          </h2>
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {mine.data.map((tk) => (
              <li key={tk.id} className="flex flex-wrap items-start justify-between gap-2 py-2">
                <span>
                  <span className="font-medium">{tk.asset?.asset_tag}</span> · {tk.issue_description}
                  <span className="block text-slate-500">{when(tk.created_at)}</span>
                  {tk.resolution_notes ? <span className="block text-emerald-800">{tk.resolution_notes}</span> : null}
                </span>
                <Badge tone={tk.status === 'resolved' ? 'green' : 'slate'}>{t(`it.ticketStatus.${tk.status}`)}</Badge>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  )
}
