import { readFileSync } from 'node:fs'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
// @ts-expect-error plain ES module script without types
import { notificationTexts } from '../../../scripts/export-notification-texts.mjs'
import * as settingsApi from './settingsApi'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./settingsApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./settingsApi')>()),
  fetchNotificationSettings: vi.fn(),
  saveNotificationSettings: vi.fn(),
}))

const auth = vi.mocked(authApi)
const api = vi.mocked(settingsApi)
const groups = ['tasks', 'renewals', 'other']
const settings: settingsApi.NotificationSettings = {
  locale: 'en', groups, push_key: null, devices: [],
  push: { tasks: true, renewals: true, other: true }, email: { tasks: false, renewals: true, other: false },
}

describe('Notification settings', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('the texts the server pushes and e-mails are the same as the app’s', () => {
    for (const lang of ['en', 'mr']) {
      const exported = JSON.parse(readFileSync(`${process.cwd()}/../backend/resources/notification-texts/${lang}.json`, 'utf8'))
      expect(exported, `run npm run export:notification-texts (${lang})`).toEqual(notificationTexts(lang))
    }
  })

  it('shows when push is not set up and saves an e-mail choice', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    api.fetchNotificationSettings.mockResolvedValue(settings)
    api.saveNotificationSettings.mockResolvedValue({ ...settings, email: { ...settings.email, tasks: true } })
    renderApp('/notifications/settings')
    expect(await screen.findByText('Push notifications are not set up on the server yet.')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Renewals: E-mail' })).toBeChecked()
    await userEvent.click(screen.getByRole('checkbox', { name: 'Tasks: E-mail' }))
    await waitFor(() => expect(api.saveNotificationSettings).toHaveBeenCalledWith({ email: { tasks: true } }))
    expect(await screen.findByRole('checkbox', { name: 'Tasks: E-mail' })).toBeChecked()
  })
})
