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

let orgTimeZone: string | null = null

/** Set from the signed-in user's current organization; "today" then follows its day. */
export function setOrgTimeZone(timeZone: string | null | undefined) {
  orgTimeZone = timeZone || null
}

/** Today's date as YYYY-MM-DD in the organization's time zone (else the browser's); the server re-checks. */
export function todayYmd(): string {
  if (orgTimeZone) {
    try {
      return new Intl.DateTimeFormat('en-CA', { timeZone: orgTimeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
    } catch {
      // Unknown time zone name: fall back to the browser's day.
    }
  }
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** YYYY-MM of the given date, or of today (as todayYmd). */
export function monthYm(d?: Date): string {
  if (!d) return todayYmd().slice(0, 7)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/** "YYYY-MM-DD" shifted by whole days (calendar arithmetic, no timezone involved). */
export function addDays(ymd: string, days: number): string {
  const [y, m, d] = ymd.split('-').map(Number)
  const date = new Date(Date.UTC(y, m - 1, d + days))
  return date.toISOString().slice(0, 10)
}
