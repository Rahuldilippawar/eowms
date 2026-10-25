import type { CurrentUser } from '@/features/auth/api'

export function makeUser(overrides: Partial<CurrentUser> = {}): CurrentUser {
  return {
    id: 1,
    name: 'Dev Admin',
    email: 'admin@example.com',
    is_super_admin: false,
    last_login_at: null,
    current_organization: { id: 10, name: 'Shivaji Education Trust', short_name: 'SET' },
    organizations: [{ id: 10, name: 'Shivaji Education Trust', short_name: 'SET' }],
    permissions: [],
    ...overrides,
  }
}
