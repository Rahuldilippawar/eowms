import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setLanguage } from '@/i18n'
import { renderApp } from '@/test/renderApp'
import * as authApi from './api'

vi.mock('./api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
}))

const api = vi.mocked(authApi)
const user = { id: 1, name: 'Dev Admin', email: 'admin@example.com', last_login_at: null }

function httpError(status: number, data: unknown) {
  return new AxiosError('fail', String(status), undefined, undefined, {
    status,
    statusText: '',
    data,
    headers: {},
    config: { headers: new AxiosHeaders() },
  })
}

describe('login flow', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('sends guests to the login page', async () => {
    api.fetchCurrentUser.mockResolvedValue(null)
    renderApp('/')
    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  })

  it('validates the form before calling the API', async () => {
    api.fetchCurrentUser.mockResolvedValue(null)
    renderApp('/login')
    await userEvent.click(await screen.findByRole('button', { name: 'Sign in' }))
    expect(await screen.findByText('Email is required')).toBeInTheDocument()
    expect(screen.getByText('Password is required')).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true')
    expect(api.login).not.toHaveBeenCalled()
  })

  it('signs in and shows the dashboard', async () => {
    api.fetchCurrentUser.mockResolvedValue(null)
    api.login.mockResolvedValue(user)
    renderApp('/login')

    await userEvent.type(await screen.findByLabelText('Email'), 'admin@example.com')
    await userEvent.type(screen.getByLabelText('Password'), 'password')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('heading', { name: 'Hello, Dev Admin' })).toBeInTheDocument()
    expect(api.login.mock.calls[0][0]).toEqual({ email: 'admin@example.com', password: 'password', remember: false })
  })

  it('shows the server message when credentials are rejected', async () => {
    api.fetchCurrentUser.mockResolvedValue(null)
    api.login.mockRejectedValue(httpError(422, { message: 'x', errors: { email: ['These credentials do not match our records.'] } }))
    renderApp('/login')

    await userEvent.type(await screen.findByLabelText('Email'), 'admin@example.com')
    await userEvent.type(screen.getByLabelText('Password'), 'wrong')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('These credentials do not match our records.')
    expect(screen.queryByRole('heading', { name: /Hello/ })).not.toBeInTheDocument()
  })

  it('explains rate limiting', async () => {
    api.fetchCurrentUser.mockResolvedValue(null)
    api.login.mockRejectedValue(httpError(429, { message: 'Too Many Attempts.' }))
    renderApp('/login')

    await userEvent.type(await screen.findByLabelText('Email'), 'admin@example.com')
    await userEvent.type(screen.getByLabelText('Password'), 'x')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts')
  })

  it('signs out from the user menu', async () => {
    api.fetchCurrentUser.mockResolvedValue(user)
    api.logout.mockResolvedValue()
    renderApp('/')

    await userEvent.click(await screen.findByRole('button', { name: /Dev Admin/ }))
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Sign out' }))

    await waitFor(() => expect(api.logout).toHaveBeenCalled())
    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  })

  it('renders in Marathi by default language', async () => {
    setLanguage('mr')
    api.fetchCurrentUser.mockResolvedValue(null)
    renderApp('/login')
    expect(await screen.findByRole('heading', { name: 'लॉगिन करा' })).toBeInTheDocument()
  })
})
