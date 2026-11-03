import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as legacyApi from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', async (importOriginal) => ({ ...(await importOriginal<typeof import('./api')>()), fetchImporter: vi.fn(), fetchRun: vi.fn(), startRun: vi.fn() }))

const auth = vi.mocked(authApi)
const legacy = vi.mocked(legacyApi)
const step = (o: Partial<legacyApi.StepReport>): legacyApi.StepReport => ({ read: 0, created: 0, existing: 0, matched: 0, skipped: 0, skips: [], warnings: [], ...o })

describe('Old data import', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ is_super_admin: true }))
  })

  it('says how to set up the copy when it is not connected', async () => {
    legacy.fetchImporter.mockResolvedValue({ source: { connected: false, error: 'not_configured' }, runs: [] })
    renderApp('/admin/legacy-import')
    expect(await screen.findByText(/Not set up: put the copy/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Dry run' })).not.toBeInTheDocument()
  })

  it('a dry run shows the report with skipped rows and notes', async () => {
    legacy.fetchImporter.mockResolvedValue({ source: { connected: true, tables: { users: 16 } }, runs: [] })
    const report = { users: step({ read: 16, created: 15, skipped: 1, skips: [{ table: 'users', id: 7, reason: 'email_invalid' }], warnings: ['users #9: password is not bcrypt'] }) }
    legacy.startRun.mockResolvedValue({ id: 3, mode: 'dry_run', status: 'done', error: null, safety_backup: null, started_at: '2026-11-02T08:00:00Z', finished_at: null, report })
    legacy.fetchRun.mockResolvedValue({ id: 3, mode: 'dry_run', status: 'done', error: null, safety_backup: null, started_at: '2026-11-02T08:00:00Z', finished_at: null, report })
    renderApp('/admin/legacy-import')
    await userEvent.click(await screen.findByRole('button', { name: 'Dry run' }))
    expect(await screen.findByRole('heading', { name: 'Report #3' })).toBeInTheDocument()
    expect(screen.getByText('Dry run: nothing was written. This is what an import would do now.')).toBeInTheDocument()
    expect(screen.getByRole('rowheader', { name: 'Accounts' }).closest('tr')).toHaveTextContent('16150')
    await userEvent.click(screen.getByText(/1 skipped, 1 notes/))
    expect(screen.getByText('users #7: no valid e-mail')).toBeInTheDocument()
    await waitFor(() => expect(legacy.startRun).toHaveBeenCalledWith('dry_run'))
  })
})
