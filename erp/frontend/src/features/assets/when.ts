import { formatDate, formatTime } from '@/lib/format'

/** "10 Jul 2026 04:30 pm" for an ISO date-time, "—" for none. */
export const when = (iso: string | null) => (iso ? `${formatDate(iso.slice(0, 10))} ${formatTime(iso)}` : '—')
