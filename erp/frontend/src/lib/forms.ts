import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { parseApiError } from './api'

/**
 * Puts Laravel 422 field errors onto the form. Returns a general message for
 * anything that is not tied to a visible field (or null when every error was placed).
 */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>, fields: readonly string[], fallback: string): string | null {
  const { status, errors, message } = parseApiError(error)
  if (status !== 422 || !errors) return status === 403 ? (message ?? fallback) : fallback

  const general: string[] = []
  for (const [key, messages] of Object.entries(errors)) {
    const field = key.split('.')[0]
    if (fields.includes(field)) setError(field as Path<T>, { type: 'server', message: messages[0] })
    else general.push(messages[0])
  }
  return general.length ? general.join(' ') : null
}

/** Message for a non-form action (activate, delete…). */
export function actionErrorMessage(error: unknown, fallback: string): string {
  const { errors, message } = parseApiError(error)
  const first = errors ? Object.values(errors)[0]?.[0] : undefined
  return first ?? message ?? fallback
}
