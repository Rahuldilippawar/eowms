import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { fetchTvScreen } from './api'

/** The notice-board screen itself: no sign-in, refreshes every minute (erp/docs/ABOUT-AND-TV.md). */
export function TvScreenPage() {
  const { t, i18n } = useTranslation()
  const { token = '' } = useParams()
  const q = useQuery({ queryKey: ['tv-screen', token], queryFn: () => fetchTvScreen(token), refetchInterval: 60_000, retry: 2 })
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 1000)
    return () => window.clearInterval(id)
  }, [])
  const tz = q.data?.timezone
  const locale = i18n.language === 'mr' ? 'mr-IN' : 'en-IN'
  const time = new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit', second: '2-digit', ...(tz ? { timeZone: tz } : {}) }).format(now)
  const date = new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', ...(tz ? { timeZone: tz } : {}) }).format(now)

  if (q.isError && !q.data) {
    return <main className="grid min-h-screen place-items-center bg-slate-950 p-8 text-center text-2xl text-slate-200">{t('tv.invalid')}</main>
  }
  const d = q.data
  const tiles: [string, number | null | undefined][] = d
    ? [
        ['tv.present', d.present_today === null ? null : d.present_today],
        ['tv.staff', d.staff],
        ['tv.onLeave', d.on_leave_today],
        ['tv.openRequests', d.open_requests],
      ]
    : []
  return (
    <main className="min-h-screen bg-slate-950 p-6 text-slate-100 sm:p-10">
      <header className="flex flex-wrap items-end justify-between gap-4 border-b border-slate-800 pb-6">
        <h1 className="text-3xl font-bold sm:text-5xl">{d?.organization ?? ''}</h1>
        <div className="text-right">
          <p className="text-4xl font-semibold tabular-nums sm:text-6xl">{time}</p>
          <p className="text-lg text-slate-400 sm:text-2xl">{date}</p>
        </div>
      </header>
      <section aria-label={t('tv.today')} className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        {tiles
          .filter(([, v]) => v !== null && v !== undefined)
          .map(([key, v]) => (
            <div key={key} className="rounded-2xl bg-slate-900 p-6">
              <p className="text-lg text-slate-400 sm:text-xl">{t(key)}</p>
              <p className="mt-2 text-5xl font-bold tabular-nums sm:text-7xl">{v}</p>
            </div>
          ))}
      </section>
      {d && (d.birthdays.length > 0 || d.anniversaries.length > 0) ? (
        <section aria-label={t('tv.wishes')} className="mt-8 grid gap-6 md:grid-cols-2">
          {d.birthdays.length > 0 ? (
            <div className="rounded-2xl bg-teal-900/60 p-6">
              <h2 className="text-2xl font-semibold">🎂 {t('tv.birthdays')}</h2>
              <p className="mt-3 text-2xl sm:text-3xl">{d.birthdays.join(', ')}</p>
            </div>
          ) : null}
          {d.anniversaries.length > 0 ? (
            <div className="rounded-2xl bg-indigo-900/60 p-6">
              <h2 className="text-2xl font-semibold">🎉 {t('tv.anniversaries')}</h2>
              <p className="mt-3 text-2xl sm:text-3xl">{d.anniversaries.join(', ')}</p>
            </div>
          ) : null}
        </section>
      ) : null}
    </main>
  )
}
