import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as adminApi from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    fetchRoles: vi.fn(),
    fetchRole: vi.fn(),
    fetchPermissionCatalog: vi.fn(),
    createRole: vi.fn(),
    updateRole: vi.fn(),
    deleteRole: vi.fn(),
    fetchUsers: vi.fn(),
    fetchUser: vi.fn(),
    createUser: vi.fn(),
  }
})

const auth = vi.mocked(authApi)
const admin = vi.mocked(adminApi)

describe('roles', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    admin.fetchPermissionCatalog.mockResolvedValue([
      {
        module: 'departments',
        permissions: [
          { key: 'departments.view', action: 'view', my_scope: 'institutions' },
          { key: 'departments.edit', action: 'edit', my_scope: null },
        ],
      },
    ])
  })

  it('disables what the editor cannot grant and saves the chosen grants', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['roles.view', 'roles.create'] }))
    admin.createRole.mockResolvedValue({ id: 9, key: 'clerk', name: 'Clerk', description: null, is_system: false })
    admin.fetchRoles.mockResolvedValue([])
    renderApp('/roles/new')

    await userEvent.type(await screen.findByLabelText('Name'), 'Clerk')
    expect(screen.getByRole('checkbox', { name: /Edit departments.edit/ })).toBeDisabled()

    await userEvent.click(screen.getByRole('checkbox', { name: /View departments.view/ }))
    const scope = screen.getByRole('combobox', { name: 'Scope for departments.view' })
    expect(within(scope).getByRole('option', { name: 'Whole organization' })).toBeDisabled()
    await userEvent.selectOptions(scope, 'departments')

    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() =>
      expect(admin.createRole.mock.calls[0][0]).toEqual({ name: 'Clerk', description: null, permissions: [{ key: 'departments.view', scope: 'departments' }] }),
    )
  })

  it('lists roles and hides delete for built-in ones', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['roles.view', 'roles.delete'] }))
    admin.fetchRoles.mockResolvedValue([
      { id: 1, key: 'org_admin', name: 'Organization Admin', description: null, is_system: true, assignments_count: 2 },
      { id: 2, key: 'clerk', name: 'Clerk', description: null, is_system: false, assignments_count: 0 },
    ])
    renderApp('/roles')

    const builtIn = (await screen.findByText('Organization Admin')).closest('li')!
    const custom = screen.getByText('Clerk').closest('li')!
    expect(within(builtIn).queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument()
    expect(within(custom).getByRole('button', { name: 'Delete' })).toBeInTheDocument()
  })
})

describe('users', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    admin.fetchUsers.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null } })
  })

  it('offers "New user" only to people who can both create users and assign roles', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['users.view', 'users.create'] }))
    renderApp('/users')
    await screen.findByText('No users yet.')
    expect(screen.queryByRole('button', { name: 'New user' })).not.toBeInTheDocument()
  })

  it('requires a role before creating the account', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['users.view', 'users.create', 'roles.assign'] }))
    admin.fetchRoles.mockResolvedValue([{ id: 3, key: 'staff', name: 'Staff', description: null, is_system: true }])
    renderApp('/users')

    await userEvent.click(await screen.findByRole('button', { name: 'New user' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Name'), 'Asha')
    await userEvent.type(within(dialog).getByLabelText('Email'), 'asha@example.com')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Create account' }))

    expect(await within(dialog).findByText('This is required')).toBeInTheDocument()
    expect(admin.createUser).not.toHaveBeenCalled()
  })
})
