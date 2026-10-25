import { api, ensureCsrfCookie, parseApiError } from '@/lib/api'

export interface OrganizationSummary {
  id: number
  name: string
  short_name: string | null
}

export interface CurrentUser {
  id: number
  name: string
  email: string
  is_super_admin: boolean
  last_login_at: string | null
  current_organization: OrganizationSummary | null
  organizations: OrganizationSummary[]
  /** Permission keys in the current organization — UI hints only; the API enforces them. */
  permissions: string[]
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

export async function switchOrganization(organizationId: number): Promise<CurrentUser> {
  const { data } = await api.post<{ data: CurrentUser }>('/auth/switch-organization', { organization_id: organizationId })
  return data.data
}

export async function requestPasswordReset(email: string): Promise<string> {
  await ensureCsrfCookie()
  const { data } = await api.post<{ message: string }>('/auth/forgot-password', { email })
  return data.message
}

export interface ResetPasswordInput {
  token: string
  email: string
  password: string
  password_confirmation: string
}

export async function resetPassword(input: ResetPasswordInput): Promise<string> {
  await ensureCsrfCookie()
  const { data } = await api.post<{ message: string }>('/auth/reset-password', input)
  return data.message
}
