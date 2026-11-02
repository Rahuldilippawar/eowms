import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { api } from '@/lib/api'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as aboutApi from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', async (importOriginal) => ({ ...(await importOriginal<typeof import('./api')>()), fetchAbout: vi.fn(), fetchTvScreen: vi.fn() }))

const auth = vi.mocked(authApi)
const about = vi.mocked(aboutApi)

describe('About page, TV screen and the welcome tour', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('shows the sections in order and offers editing only to admins', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    about.fetchAbout.mockResolvedValue({
      data: [
        { id: 1, kind: 'sadguru', title: 'Sadguru', subtitle: null, body: 'Teachings', sort_order: 0, is_active: true, has_photo: false, updated_at: null },
        { id: 2, kind: 'contributor', title: 'Asha Patil', subtitle: 'Built the library', body: null, sort_order: 0, is_active: true, has_photo: true, updated_at: '2026-11-01T00:00:00+00:00' },
      ],
      meta: { can_manage: false },
    })
    renderApp('/about')
    expect(await screen.findByRole('heading', { name: 'Inspiration' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Contributors' })).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Asha Patil' })).toHaveAttribute('src', expect.stringContaining('/api/v1/about/2/photo'))
    expect(screen.queryByRole('button', { name: 'Edit page' })).not.toBeInTheDocument()
  })

  it('the TV screen shows counts and birthdays, and says when the link is not valid', async () => {
    about.fetchTvScreen.mockResolvedValue({ organization: 'Shivaji Trust', now: '2026-11-02T10:00:00+05:30', timezone: 'Asia/Kolkata', staff: 40, present_today: 35, on_leave_today: 2, open_requests: null, birthdays: ['Asha'], anniversaries: [] })
    const { unmount } = renderApp('/tv/' + 'a'.repeat(40))
    expect(await screen.findByRole('heading', { name: 'Shivaji Trust' })).toBeInTheDocument()
    expect(screen.getByText('Present today').nextSibling).toHaveTextContent('35')
    expect(screen.queryByText('Open work requests')).not.toBeInTheDocument()
    expect(screen.getByText('Asha')).toBeInTheDocument()
    unmount()
    about.fetchTvScreen.mockRejectedValue(new Error('404'))
    renderApp('/tv/' + 'b'.repeat(40))
    expect(await screen.findByText('This screen link is not valid any more.', {}, { timeout: 5000 })).toBeInTheDocument()
  })

  it('the welcome tour appears once for a newcomer and is recorded when skipped', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ onboarding_seen: false }))
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: makeUser({ onboarding_seen: true }) } })
    renderApp('/')
    expect(await screen.findByRole('dialog', { name: /Welcome/ })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(screen.getByText('Step 2 of 6')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Skip' }))
    await waitFor(() => expect(post).toHaveBeenCalledWith('/me/onboarding-seen'))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})
