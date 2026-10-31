import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as authApi from './api'
import * as webauthn from './webauthn'

vi.mock('./api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./webauthn', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./webauthn')>()),
  webauthnSupported: vi.fn(),
  signInWithKey: vi.fn(),
  fetchKeys: vi.fn(),
  addKey: vi.fn(),
}))

const auth = vi.mocked(authApi)
const wa = vi.mocked(webauthn)

describe('Fingerprint sign-in', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('the login page offers the fingerprint only where the browser supports it', async () => {
    auth.fetchCurrentUser.mockResolvedValue(null)
    wa.webauthnSupported.mockReturnValue(false)
    const { unmount } = renderApp('/login')
    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sign in with fingerprint' })).not.toBeInTheDocument()
    unmount()

    wa.webauthnSupported.mockReturnValue(true)
    wa.signInWithKey.mockResolvedValue(makeUser())
    renderApp('/login')
    await userEvent.click(await screen.findByRole('button', { name: 'Sign in with fingerprint' }))
    await waitFor(() => expect(wa.signInWithKey).toHaveBeenCalled())
    expect(await screen.findByRole('heading', { name: /^Hello,/ })).toBeInTheDocument()
  })

  it('adding a device needs a name and the password', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    wa.webauthnSupported.mockReturnValue(true)
    wa.fetchKeys.mockResolvedValue([])
    wa.addKey.mockResolvedValue([{ id: 1, name: 'My phone', created_at: '2026-10-31T06:00:00+00:00', last_used_at: null }])
    renderApp('/account/fingerprint')
    expect(await screen.findByText('No device added yet.')).toBeInTheDocument()
    const add = screen.getByRole('button', { name: 'Add fingerprint' })
    await userEvent.type(screen.getByLabelText('Name of this device'), 'My phone')
    expect(add).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Your password'), 'secret')
    await userEvent.click(add)
    await waitFor(() => expect(wa.addKey).toHaveBeenCalledWith('My phone', 'secret'))
    expect(await screen.findByText('My phone')).toBeInTheDocument()
  })
})
