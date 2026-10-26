import { useQuery } from '@tanstack/react-query'
import { useEffect, useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { useAuth } from '@/features/auth/AuthProvider'
import { cn } from '@/lib/utils'
import { searchRecipients, type LetterStatus, type Recipient } from './api'

const statusTone: Record<LetterStatus, string> = {
  draft: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  in_progress: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  returned: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
}

export function LetterStatusBadge({ status }: { status: LetterStatus }) {
  const { t } = useTranslation()
  return <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', statusTone[status])}>{t(`letters.status.${status}`)}</span>
}

/** Pick one active colleague (searched by name, code or designation). */
export function RecipientPicker({ value, onChange, label, error }: { value: Recipient | null; onChange: (r: Recipient | null) => void; label: string; error?: string }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const id = useId()
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), 300)
    return () => clearTimeout(timer)
  }, [search])
  const results = useQuery({ queryKey: ['letter-recipients', user?.current_organization?.id, debounced], queryFn: () => searchRecipients(debounced), enabled: debounced.length >= 2 })

  return (
    <div className="space-y-2">
      <label htmlFor={id} className="block text-sm font-medium">
        {label}
      </label>
      {value ? (
        <p className="flex items-center justify-between gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
          <span>
            <span className="font-medium">{value.name}</span>
            <span className="text-slate-500">{[value.designation, value.department].filter(Boolean).map((s) => ` · ${s}`).join('')}</span>
          </span>
          <button type="button" className="text-xs font-medium text-brand-700 hover:underline" onClick={() => onChange(null)}>
            {t('letters.change')}
          </button>
        </p>
      ) : (
        <>
          <Input id={id} value={search} placeholder={t('letters.searchPerson')} aria-invalid={!!error || undefined} onChange={(e) => setSearch(e.target.value)} />
          {debounced.length >= 2 ? (
            results.isPending ? (
              <Spinner label={t('common.loading')} />
            ) : results.data?.length ? (
              <ul className="max-h-48 divide-y divide-slate-100 overflow-auto rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                {results.data.map((r) => (
                  <li key={r.user_id}>
                    <button type="button" className="w-full px-3 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800" onClick={() => onChange(r)}>
                      {t('letters.choosePerson', { name: r.name })}
                      <span className="text-xs text-slate-500">{[r.designation, r.department].filter(Boolean).map((s) => ` · ${s}`).join('')}</span>
                    </button>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-slate-500">{t('letters.noPerson')}</p>
            )
          ) : null}
        </>
      )}
      {error ? <p className="text-sm text-red-600">{error}</p> : null}
    </div>
  )
}
