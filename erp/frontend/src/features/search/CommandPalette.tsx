import * as Dialog from '@radix-ui/react-dialog'
import { useQuery } from '@tanstack/react-query'
import { CornerDownLeft, Search } from 'lucide-react'
import { useEffect, useMemo, useState, type KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { visibleNavigation } from '@/components/layout/visibleNav'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { cn } from '@/lib/utils'
import { SEARCH_GROUPS, searchAll } from './api'

interface Option {
  key: string
  group: string
  title: string
  subtitle?: string | null
  url: string
}

/** Ctrl+K / ⌘K: jump to a page or to a record found by global search. */
export function CommandPalette({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const navigate = useNavigate()
  const [text, setText] = useState('')
  const [active, setActive] = useState(0)
  const q = useDebouncedValue(text.trim(), 250)

  const results = useQuery({
    queryKey: ['search', user?.current_organization?.id, q],
    queryFn: () => searchAll(q),
    enabled: open && q.length >= 2,
    staleTime: 30_000,
  })

  const options = useMemo<Option[]>(() => {
    const needle = text.trim().toLowerCase()
    const pages = visibleNavigation(user, can)
      .flatMap((s) => s.items)
      .filter((item) => !needle || t(item.labelKey).toLowerCase().includes(needle))
      .slice(0, needle ? 6 : 8)
      .map((item) => ({ key: `page:${item.to}`, group: t('search.pages'), title: t(item.labelKey), url: item.to }))
    const found = q.length >= 2 && results.data
      ? SEARCH_GROUPS.flatMap((g) => (results.data[g] ?? []).map((h) => ({ key: `${g}:${h.id}`, group: t(`search.groups.${g}`), title: h.title, subtitle: h.subtitle, url: h.url })))
      : []
    return [...pages, ...found]
  }, [text, q, results.data, user, can, t])

  useEffect(() => setActive(0), [text])

  const go = (o: Option | undefined) => {
    if (!o) return
    onOpenChange(false)
    setText('')
    navigate(o.url)
  }
  const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActive((i) => Math.min(i + 1, options.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActive((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      go(options[active])
    }
  }

  let lastGroup = ''
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-40 bg-slate-900/40" />
        <Dialog.Content className="fixed inset-x-0 top-0 z-50 mx-auto flex max-h-[85vh] w-full max-w-xl flex-col overflow-hidden bg-white shadow-xl sm:top-[10vh] sm:rounded-2xl dark:bg-slate-900">
          <Dialog.Title className="sr-only">{t('search.title')}</Dialog.Title>
          <Dialog.Description className="sr-only">{t('search.hint')}</Dialog.Description>
          <div className="flex items-center gap-2 border-b border-slate-200 px-4 dark:border-slate-800">
            <Search aria-hidden className="size-5 text-slate-400" />
            <input
              autoFocus
              role="combobox"
              aria-expanded
              aria-controls="palette-list"
              aria-activedescendant={options[active] ? `palette-${active}` : undefined}
              aria-label={t('search.title')}
              placeholder={t('search.placeholder')}
              value={text}
              onChange={(e) => setText(e.target.value)}
              onKeyDown={onKeyDown}
              className="min-h-12 w-full bg-transparent text-base outline-none"
            />
          </div>
          <ul id="palette-list" role="listbox" aria-label={t('search.title')} className="overflow-y-auto p-2">
            {options.map((o, i) => {
              const header = o.group !== lastGroup ? o.group : null
              lastGroup = o.group
              return (
                <li key={o.key} role="presentation">
                  {header ? <p className="px-3 pt-2 pb-1 text-xs font-semibold tracking-wide text-slate-400 uppercase">{header}</p> : null}
                  <div
                    id={`palette-${i}`}
                    role="option"
                    aria-selected={i === active}
                    onMouseEnter={() => setActive(i)}
                    onClick={() => go(o)}
                    className={cn('flex cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm', i === active && 'bg-brand-50 text-brand-800 dark:bg-brand-800/30 dark:text-brand-100')}
                  >
                    <span className="min-w-0">
                      <span className="block truncate font-medium">{o.title}</span>
                      {o.subtitle ? <span className="block truncate text-xs text-slate-500">{o.subtitle}</span> : null}
                    </span>
                    {i === active ? <CornerDownLeft aria-hidden className="size-4 shrink-0 text-slate-400" /> : null}
                  </div>
                </li>
              )
            })}
          </ul>
          {q.length >= 2 && results.isFetching ? <p className="px-5 pb-3 text-xs text-slate-500">{t('search.searching')}</p> : null}
          {options.length === 0 && !results.isFetching ? <p className="px-5 pb-4 text-sm text-slate-500">{t('common.noResults')}</p> : null}
          {text.trim().length === 1 ? <p className="px-5 pb-3 text-xs text-slate-500">{t('search.minChars')}</p> : null}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}
