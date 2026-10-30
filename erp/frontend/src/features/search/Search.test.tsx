import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import * as auditApi from '@/features/audit/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as searchApi from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', async (importOriginal) => ({ ...(await importOriginal<typeof import('./api')>()), searchAll: vi.fn() }))
vi.mock('@/features/audit/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/audit/api')>()),
  fetchLogins: vi.fn(),
  fetchAuditUsers: vi.fn(),
}))

const auth = vi.mocked(authApi)
const search = vi.mocked(searchApi)
const audit = vi.mocked(auditApi)

describe('Global search and the sign-in log', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('Ctrl+K finds a page the user may open and a record, and Enter opens it', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['employees.view', 'audit.view'] }))
    audit.fetchLogins.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0, from: null, to: null, can_export: false, summary: { logins_7d: 0, failed_7d: 0, users_7d: 0 } } })
    audit.fetchAuditUsers.mockResolvedValue([])
    search.searchAll.mockResolvedValue({ employees: [{ id: 9, title: 'Asha Patil', subtitle: 'E-0009', url: '/audit/logins' }] })
    renderApp('/')
    await screen.findByRole('button', { name: 'Search (Ctrl+K)' })
    await userEvent.keyboard('{Control>}k{/Control}')
    const input = await screen.findByRole('combobox', { name: 'Search' })
    await userEvent.type(input, 'activ')
    expect(screen.getByRole('option', { name: /Activity log/ })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Roles/ })).not.toBeInTheDocument()
    await userEvent.clear(input)
    await userEvent.type(input, 'asha')
    await waitFor(() => expect(search.searchAll).toHaveBeenCalledWith('asha'))
    expect(await screen.findByRole('option', { name: /Asha Patil/ })).toBeInTheDocument()
    await userEvent.keyboard('{Enter}')
    expect(await screen.findByRole('heading', { name: 'Sign-in activity' })).toBeInTheDocument()
  })

  it('the sign-in log shows failed sign-ins in words', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['audit.view'] }))
    audit.fetchAuditUsers.mockResolvedValue([{ id: 3, name: 'Ravi' }])
    audit.fetchLogins.mockResolvedValue({
      data: [{ id: 1, created_at: '2026-10-30T05:00:00+00:00', user: { id: 3, name: 'Ravi' }, module: 'auth', action: 'login_failed', subject: null, reason: null, ip_address: '10.0.0.5', user_agent: 'Firefox' }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1, can_export: true, summary: { logins_7d: 4, failed_7d: 1, users_7d: 2 } },
    })
    renderApp('/audit/logins')
    expect((await screen.findByText('Failed sign-ins, last 7 days')).nextSibling).toHaveTextContent('1')
    expect(screen.getByRole('cell', { name: 'Wrong password' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Download CSV/ })).toHaveAttribute('href', expect.stringContaining('/api/v1/audit-logs/logins?'))
    await userEvent.selectOptions(screen.getByLabelText('Event'), 'login_failed')
    await waitFor(() => expect(audit.fetchLogins).toHaveBeenLastCalledWith({ page: 1, action: 'login_failed' }))
  })
})
