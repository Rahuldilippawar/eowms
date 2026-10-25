import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setLanguage } from '@/i18n'
import { renderApp } from '@/test/renderApp'
import * as authApi from './api'

vi.mock('./api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))

const api = vi.mocked(authApi)

describe('password reset', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    api.fetchCurrentUser.mockResolvedValue(null)
  })

  it('links from sign-in to the forgot-password page and shows the server message', async () => {
    api.requestPasswordReset.mockResolvedValue('If an active account exists for this email, a password reset link has been sent.')
    renderApp('/login')

    await userEvent.click(await screen.findByRole('link', { name: 'Forgot password?' }))
    await userEvent.type(await screen.findByLabelText('Email'), 'someone@example.com')
    await userEvent.click(screen.getByRole('button', { name: 'Send link' }))

    expect(await screen.findByRole('status')).toHaveTextContent('If an active account exists')
    expect(api.requestPasswordReset.mock.calls[0][0]).toBe('someone@example.com')
  })

  it('rejects a weak or mismatched password before calling the API', async () => {
    renderApp('/reset-password?token=abc&email=a%40example.com')

    await userEvent.type(await screen.findByLabelText('New password'), 'short')
    await userEvent.type(screen.getByLabelText('Repeat password'), 'different1')
    await userEvent.click(screen.getByRole('button', { name: 'Change password' }))

    expect(await screen.findByText('At least 8 characters, with letters and numbers')).toBeInTheDocument()
    expect(screen.getByText('The passwords do not match')).toBeInTheDocument()
    expect(api.resetPassword).not.toHaveBeenCalled()
  })

  it('resets with the token and email from the link', async () => {
    api.resetPassword.mockResolvedValue('Your password has been reset.')
    renderApp('/reset-password?token=abc&email=a%40example.com')

    await userEvent.type(await screen.findByLabelText('New password'), 'new-pass-22')
    await userEvent.type(screen.getByLabelText('Repeat password'), 'new-pass-22')
    await userEvent.click(screen.getByRole('button', { name: 'Change password' }))

    expect(await screen.findByRole('status')).toHaveTextContent('Your password has been reset.')
    expect(api.resetPassword.mock.calls[0][0]).toEqual({
      token: 'abc',
      email: 'a@example.com',
      password: 'new-pass-22',
      password_confirmation: 'new-pass-22',
    })
  })

  it('explains an incomplete link', async () => {
    renderApp('/reset-password')
    expect(await screen.findByRole('alert')).toHaveTextContent('This link is incomplete')
  })
})
