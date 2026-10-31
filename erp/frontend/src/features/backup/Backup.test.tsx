import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as backupApi from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchBackups: vi.fn(),
  restoreBackup: vi.fn(),
  clearOrganization: vi.fn(),
}))

const auth = vi.mocked(authApi)
const backup = vi.mocked(backupApi)

describe('Backups and clearing data', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('a restore asks for RESTORE and the password before it runs', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ is_super_admin: true }))
    backup.fetchBackups.mockResolvedValue({ data: [{ name: 'erp-backup-20261030-212000-scheduled.sql.gz', kind: 'scheduled', created_at: '2026-10-30T21:20:00+00:00', size: 40960 }], meta: { keep_scheduled: 14 } })
    backup.restoreBackup.mockResolvedValue({ restored: 'x', safety_backup: 'y' })
    renderApp('/admin/backups')
    expect(await screen.findByText(/erp-backup-20261030-212000-scheduled\.sql\.gz · 40 KB/)).toBeInTheDocument()
    expect(screen.getByText('Nightly')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Restore erp-backup-20261030-212000-scheduled.sql.gz' }))
    const go = screen.getByRole('button', { name: 'Restore' })
    await userEvent.type(screen.getByLabelText('Type RESTORE to confirm'), 'restore')
    await userEvent.type(screen.getByLabelText('Your password'), 'secret')
    expect(go).toBeDisabled()
    await userEvent.clear(screen.getByLabelText('Type RESTORE to confirm'))
    await userEvent.type(screen.getByLabelText('Type RESTORE to confirm'), 'RESTORE')
    await userEvent.click(go)
    await waitFor(() => expect(backup.restoreBackup).toHaveBeenCalledWith('erp-backup-20261030-212000-scheduled.sql.gz', { password: 'secret', confirmation: 'RESTORE' }))
  })

  it("clearing needs the organization's exact name and shows a wrong password", async () => {
    const user = makeUser({ permissions: ['organizations.edit'] })
    auth.fetchCurrentUser.mockResolvedValue(user)
    const name = user.current_organization!.name
    backup.clearOrganization.mockRejectedValueOnce(Object.assign(new (await import('axios')).AxiosError('bad'), { response: { status: 422, data: { message: 'x', errors: { password: ['The password is not correct.'] } } } }))
    renderApp('/organization/data')
    await userEvent.click(await screen.findByRole('button', { name: 'Clear data' }))
    await userEvent.type(screen.getByLabelText(`Type ${name} to confirm`), name)
    await userEvent.type(screen.getByLabelText('Your password'), 'nope')
    await userEvent.click(screen.getAllByRole('button', { name: 'Clear data' }).at(-1)!)
    expect(await screen.findByText('The password is not correct.')).toBeInTheDocument()
    expect(backup.clearOrganization).toHaveBeenCalledWith({ password: 'nope', confirmation: name })
  })
})
