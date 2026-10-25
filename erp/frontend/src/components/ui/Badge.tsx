import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export function Badge({ tone = 'slate', children }: { tone?: 'green' | 'slate' | 'blue'; children: ReactNode }) {
  const tones = {
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-900/30 dark:text-emerald-300',
    slate: 'bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300',
    blue: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-900/30 dark:text-sky-300',
  }
  return <span className={cn('inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone])}>{children}</span>
}
