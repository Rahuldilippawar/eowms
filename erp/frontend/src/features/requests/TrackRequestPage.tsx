import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { LanguageSwitcher } from '@/components/layout/LanguageSwitcher'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { formatDate, formatTime } from '@/lib/format'
import { actionErrorMessage } from '@/lib/forms'
import { trackRequest } from './api'
import { RequestStatusBadge } from './RequestsPage'

const when = (iso: string | null) => (iso ? `${formatDate(iso.slice(0, 10))} ${formatTime(iso)}` : '')

/** The old track-request.php: code and phone number together, never the phone alone. */
export function TrackRequestPage() {
  const { t } = useTranslation()
  const { org = '' } = useParams()
  const [code, setCode] = useState('')
  const [contact, setContact] = useState('')
  const track = useMutation({ mutationFn: () => trackRequest(org, code.trim(), contact.trim()) })
  const r = track.data

  return (
    <div className="flex min-h-full items-start justify-center bg-slate-50 p-4 sm:items-center dark:bg-slate-950">
      <div className="w-full max-w-lg space-y-4">
        <div className="flex items-center justify-between">
          <p className="text-lg font-bold text-brand-700">{t('app.name')}</p>
          <LanguageSwitcher />
        </div>
        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h1 className="mb-4 text-lg font-semibold">{t('requests.trackTitle')}</h1>
          <form
            className="grid gap-3"
            onSubmit={(e) => {
              e.preventDefault()
              if (code.trim() && contact.trim()) track.mutate()
            }}
          >
            <Field id="tr-code" label={t('requests.code')}>
              <Input id="tr-code" value={code} maxLength={30} placeholder="REQ-26-0001" onChange={(e) => setCode(e.target.value)} />
            </Field>
            <Field id="tr-phone" label={t('requests.yourPhone')}>
              <Input id="tr-phone" type="tel" value={contact} maxLength={20} onChange={(e) => setContact(e.target.value)} />
            </Field>
            {track.isError ? (
              <p role="alert" className="text-sm text-red-600">
                {actionErrorMessage(track.error, t('common.actionFailed'))}
              </p>
            ) : null}
            <Button type="submit" disabled={track.isPending || !code.trim() || !contact.trim()}>
              {track.isPending ? <Spinner label={t('common.loading')} /> : t('requests.trackButton')}
            </Button>
          </form>
          {r ? (
            <div className="mt-5 space-y-3 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
              <p className="flex flex-wrap items-center gap-2">
                <span className="font-semibold">{r.request_code}</span>
                <RequestStatusBadge status={r.status} />
              </p>
              <p className="text-slate-600 dark:text-slate-300">{[r.department, when(r.created_at)].filter(Boolean).join(' · ')}</p>
              <p className="text-slate-600 dark:text-slate-300">{r.seen_at ? t('requests.seenAt', { when: when(r.seen_at) }) : t('requests.notSeenYet')}</p>
              {r.completion_remarks ? <p className="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">{r.completion_remarks}</p> : null}
              <ol className="space-y-1">
                {r.timeline.map((a, i) => (
                  <li key={i}>
                    <span className="text-slate-500">{when(a.at)}</span> — {t(`requests.action.${a.type}`, { status: a.status ? t(`requests.status.${a.status}`) : '' })}
                  </li>
                ))}
              </ol>
            </div>
          ) : null}
          <Link to={`/request/${org}`} className="mt-4 block text-center text-sm font-medium text-brand-700 hover:underline">
            {t('requests.newPublic')}
          </Link>
        </section>
      </div>
    </div>
  )
}
