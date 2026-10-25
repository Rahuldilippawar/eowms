import { parseApiError } from '@/lib/api'

/** Field → first message from a Laravel 422, plus a general message for the rest. */
export function splitServerErrors(error: unknown, fields: readonly string[], fallback: string) {
  const { status, errors, message } = parseApiError(error)
  const byField: Record<string, string> = {}
  const general: string[] = []
  if (status === 422 && errors) {
    for (const [key, msgs] of Object.entries(errors)) {
      const field = key.split('.')[0]
      if (fields.includes(field)) byField[field] = msgs[0]
      else general.push(msgs[0])
    }
  } else {
    general.push(status === 403 && message ? message : fallback)
  }
  return { byField, general: general.join(' ') || null }
}
