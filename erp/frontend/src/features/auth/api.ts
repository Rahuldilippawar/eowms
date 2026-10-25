import { api, ensureCsrfCookie, parseApiError } from '@/lib/api'

export interface CurrentUser {
  id: number
  name: string
  email: string
  last_login_at: string | null
}

export interface LoginInput {
  email: string
  password: string
  remember: boolean
}

/** Returns the signed-in user, or null when there is no session. */
export async function fetchCurrentUser(): Promise<CurrentUser | null> {
  try {
    const { data } = await api.get<{ data: CurrentUser }>('/auth/me')
    return data.data
  } catch (error) {
    if (parseApiError(error).status === 401) return null
    throw error
  }
}

export async function login(input: LoginInput): Promise<CurrentUser> {
  await ensureCsrfCookie()
  const { data } = await api.post<{ data: CurrentUser }>('/auth/login', input)
  return data.data
}

export async function logout(): Promise<void> {
  await api.post('/auth/logout')
}
