import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { useToast } from '@/components/ui/Toast'
import { when } from '@/features/assets/when'
import { actionErrorMessage } from '@/lib/forms'
import { resolveTicket, startTicket, type Ticket } from './api'

/** One repair ticket; IT can start and resolve it. */
export function TicketRow({ ticket, canResolve, showAsset = true, onChanged }: { ticket: Ticket; canResolve: boolean; showAsset?: boolean; onChanged: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [notes, setNotes] = useState('')
  const [resolving, setResolving] = useState(false)
  const onError = (e: unknown) => toast.error(actionErrorMessage(e, t('common.actionFailed')))
  const start = useMutation({ mutationFn: () => startTicket(ticket.id), onSuccess: onChanged, onError })
  const resolve = useMutation({
    mutationFn: () => resolveTicket(ticket.id, notes.trim()),
    onSuccess: () => {
      setResolving(false)
      onChanged()
    },
    onError,
  })
  return (
    <li className="space-y-1 py-2 text-sm">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <span className="min-w-0">
          {showAsset && ticket.asset ? (
            <Link to={`/it/assets/${ticket.asset.id}`} className="font-medium text-brand-700 hover:underline">
              {ticket.asset.asset_tag}
            </Link>
          ) : null}
          {showAsset ? <span className="text-slate-500"> · {[ticket.lab, ticket.asset?.workstation_no].filter(Boolean).join(' · ')}</span> : null}
          <span className="block">{ticket.issue_description}</span>
          <span className="block text-slate-500">{t('it.reportedBy', { name: ticket.reported_by ?? '—', when: when(ticket.created_at) })}</span>
          {ticket.resolution_notes ? <span className="block text-emerald-800">{t('it.fixed', { notes: ticket.resolution_notes, name: ticket.resolved_by ?? '' })}</span> : null}
        </span>
        <span className="flex items-center gap-2">
          <Badge tone={ticket.status === 'resolved' ? 'green' : ticket.status === 'in_progress' ? 'blue' : 'slate'}>{t(`it.ticketStatus.${ticket.status}`)}</Badge>
          {canResolve && ticket.status === 'pending' ? (
            <Button variant="secondary" disabled={start.isPending} onClick={() => start.mutate()}>
              {t('it.startWork')}
            </Button>
          ) : null}
          {canResolve && ticket.status !== 'resolved' && !resolving ? <Button onClick={() => setResolving(true)}>{t('it.markFixed')}</Button> : null}
        </span>
      </div>
      {resolving ? (
        <div className="flex flex-wrap items-center gap-2">
          <Input aria-label={t('it.whatWasDone', { tag: ticket.asset?.asset_tag ?? '' })} className="min-w-56 flex-1" maxLength={2000} placeholder={t('it.whatWasDoneHint')} value={notes} onChange={(e) => setNotes(e.target.value)} />
          <Button disabled={!notes.trim() || resolve.isPending} onClick={() => resolve.mutate()}>
            {t('common.save')}
          </Button>
          <Button variant="secondary" onClick={() => setResolving(false)}>
            {t('common.cancel')}
          </Button>
        </div>
      ) : null}
    </li>
  )
}
