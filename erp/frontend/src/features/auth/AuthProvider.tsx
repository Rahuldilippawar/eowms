import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useContext, type ReactNode } from 'react'
import { setOrgTimeZone } from '@/lib/format'
import {
  fetchCurrentUser,
  login as loginRequest,
  logout as logoutRequest,
  switchOrganization as switchRequest,
  type CurrentUser,
  type LoginInput,
} from './api'

interface AuthContextValue {
  user: CurrentUser | null
  isLoading: boolean
  isError: boolean
  refetch: () => void
  login: (input: LoginInput) => Promise<CurrentUser>
  logout: () => Promise<void>
  switchOrganization: (organizationId: number) => Promise<void>
  /** UI hint only — the API checks every permission itself. */
  can: (permission: string) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

export const CURRENT_USER_KEY = ['auth', 'me'] as const

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const me = useQuery({ queryKey: CURRENT_USER_KEY, queryFn: fetchCurrentUser, retry: false, staleTime: 5 * 60_000 })

  const loginMutation = useMutation({
    mutationFn: loginRequest,
    onSuccess: (user) => queryClient.setQueryData(CURRENT_USER_KEY, user),
  })

  const logoutMutation = useMutation({
    mutationFn: logoutRequest,
    onSettled: () => {
      // Drop every cached query so the next user never sees this user's data.
      queryClient.clear()
      queryClient.setQueryData(CURRENT_USER_KEY, null)
    },
  })

  const switchMutation = useMutation({
    mutationFn: switchRequest,
    onSuccess: async (user) => {
      // Everything cached belongs to the previous organization.
      queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== CURRENT_USER_KEY[0] })
      queryClient.setQueryData(CURRENT_USER_KEY, user)
    },
  })

  const user = me.data ?? null
  // Set during render so pages rendered with this user already count "today" in the organization's day.
  setOrgTimeZone(user?.current_organization?.timezone)

  const value: AuthContextValue = {
    user,
    isLoading: me.isPending,
    isError: me.isError,
    refetch: () => void me.refetch(),
    login: (input) => loginMutation.mutateAsync(input),
    logout: () => logoutMutation.mutateAsync(),
    switchOrganization: async (organizationId) => {
      await switchMutation.mutateAsync(organizationId)
    },
    can: (permission) => !!user && (user.is_super_admin || user.permissions.includes(permission)),
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}
