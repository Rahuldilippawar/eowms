import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as orgApi from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', () => ({ fetchInstitutions: vi.fn(), fetchDepartments: vi.fn() }))

const auth = vi.mocked(authApi)
const org = vi.mocked(orgApi)
const meta = { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 }

describe('organization structure', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('shows only the menu entries the user is allowed to use', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['departments.view'] }))
    renderApp('/')

    const nav = await screen.findByRole('navigation', { name: 'Main' })
    expect(within(nav).getByRole('link', { name: 'Departments' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Institutions' })).not.toBeInTheDocument()
  })

  it('refuses a page without the permission and never calls its API', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['departments.view'] }))
    renderApp('/institutions')

    expect(await screen.findByRole('heading', { name: 'Not allowed' })).toBeInTheDocument()
    expect(org.fetchInstitutions).not.toHaveBeenCalled()
  })

  it('lists departments, marking central ones', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['departments.view'] }))
    org.fetchDepartments.mockResolvedValue({
      data: [{ id: 1, organization_id: 10, institution_id: null, institution: null, is_central: true, name: 'IT', code: 'IT', category: null, is_active: true }],
      meta,
    })
    renderApp('/departments')

    const table = await screen.findByRole('table')
    expect(within(table).getByText('IT', { selector: 'td' })).toBeInTheDocument()
    expect(within(table).getByText('Central')).toBeInTheDocument()
  })

  it('shows a retry button when loading fails', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['departments.view'] }))
    org.fetchDepartments.mockRejectedValueOnce(new Error('down')).mockResolvedValue({ data: [], meta: { ...meta, total: 0 } })
    renderApp('/departments')

    await userEvent.click(await screen.findByRole('button', { name: 'Try again' }))
    expect(await screen.findByText('No departments yet.')).toBeInTheDocument()
  })

  it('hides the switcher for single-organization users', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    renderApp('/')
    await screen.findByRole('heading', { name: /Hello/ })
    expect(screen.queryByRole('combobox', { name: 'Switch organization' })).not.toBeInTheDocument()
  })

  it('switches organization and shows the new one', async () => {
    const orgs = [
      { id: 10, name: 'Shivaji Education Trust', short_name: 'SET' },
      { id: 20, name: 'Sanjeevani Hospital Trust', short_name: 'SHT' },
    ]
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ organizations: orgs }))
    auth.switchOrganization.mockResolvedValue(makeUser({ organizations: orgs, current_organization: orgs[1] }))
    renderApp('/')

    await userEvent.selectOptions(await screen.findByRole('combobox', { name: 'Switch organization' }), '20')

    await waitFor(() => expect(auth.switchOrganization.mock.calls[0][0]).toBe(20))
    expect(await screen.findByText('Organization: Sanjeevani Hospital Trust')).toBeInTheDocument()
  })

  it('explains when the user has no organization', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ current_organization: null, organizations: [] }))
    renderApp('/')
    expect(await screen.findByText(/not been given a role/)).toBeInTheDocument()
  })
})
