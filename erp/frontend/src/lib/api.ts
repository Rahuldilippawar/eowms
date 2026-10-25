import axios, { AxiosError } from 'axios'
import i18n from '@/i18n'

/**
 * One HTTP client for the whole app. Same-origin session cookies
 * (Laravel Sanctum SPA auth): no tokens are ever stored in JavaScript.
 * Axios copies the XSRF-TOKEN cookie into the X-XSRF-TOKEN header.
 */
export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

// Server messages (validation, auth) come back in the language shown in the UI.
api.interceptors.request.use((config) => {
  config.headers.set('Accept-Language', i18n.language || 'mr')
  return config
})

/** Sets the CSRF cookie; call before the first state-changing request. */
export async function ensureCsrfCookie() {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export type ApiValidationErrors = Record<string, string[]>

/** Pulls a human message (and field errors) out of a Laravel JSON error. */
export function parseApiError(error: unknown): { status?: number; message?: string; errors?: ApiValidationErrors } {
  if (error instanceof AxiosError) {
    const data = error.response?.data as { message?: string; errors?: ApiValidationErrors } | undefined
    return { status: error.response?.status, message: data?.message, errors: data?.errors }
  }
  return {}
}
