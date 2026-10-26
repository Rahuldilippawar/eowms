import i18n from '@/i18n'

const locale = () => (i18n.language === 'mr' ? 'mr-IN' : 'en-IN')

export function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat(locale(), { hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
}

/** "YYYY-MM-DD" shown as a local date (no timezone shift). */
export function formatDate(ymd: string | null | undefined): string {
  if (!ymd) return '—'
  const [y, m, d] = ymd.split('-').map(Number)
  return new Intl.DateTimeFormat(locale(), { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(y, m - 1, d))
}

/** Rupees, e.g. ₹1,250 (paise only when there are any). */
export function formatMoney(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return new Intl.NumberFormat(locale(), { style: 'currency', currency: 'INR', minimumFractionDigits: Number.isInteger(n) ? 0 : 2, maximumFractionDigits: 2 }).format(n)
}

/** Today's date in the browser's timezone as YYYY-MM-DD (the server re-checks). */
export function todayYmd(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function monthYm(d = new Date()): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/** "YYYY-MM-DD" shifted by whole days (calendar arithmetic, no timezone involved). */
export function addDays(ymd: string, days: number): string {
  const [y, m, d] = ymd.split('-').map(Number)
  const date = new Date(Date.UTC(y, m - 1, d + days))
  return date.toISOString().slice(0, 10)
}
