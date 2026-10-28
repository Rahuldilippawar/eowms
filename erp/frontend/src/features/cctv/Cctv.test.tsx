import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { notificationText } from '@/features/notifications/text'
import i18n, { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as cctvApi from './api'

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
  fetchSheet: vi.fn(),
  saveSheet: vi.fn(),
}))

const auth = vi.mocked(authApi)
const cctv = vi.mocked(cctvApi)

describe('CCTV daily check', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['cctv.check'] }))
  })

  it('a guard marks a camera down with what is wrong and saves the check', async () => {
    const sheet: cctvApi.Sheet = {
      dvr: { id: 2, name: 'Main building DVR', location: null, channel_capacity: 8, storage_tb: 4, installed_date: null, is_active: true, department: null },
      date: '2026-07-10', saved: false, checked_by: null, storage_used_percent: null, footage_days: null, backup_done: false, backup_location: null, remarks: null,
      cameras: [
        { id: 5, name: 'Cam-01', location: 'Main gate', status: 'working', remarks: null },
        { id: 6, name: 'Cam-02', location: 'Office', status: 'working', remarks: null },
      ],
    }
    cctv.fetchSheet.mockResolvedValue(sheet)
    cctv.saveSheet.mockResolvedValue({ ...sheet, saved: true })
    renderApp('/cctv/dvrs/2/checkup?date=2026-07-10')
    await userEvent.type(await screen.findByLabelText('Storage used (%)'), '82')
    await userEvent.click(within(screen.getByRole('radiogroup', { name: 'Cam-02' })).getByRole('radio', { name: 'Not working' }))
    await userEvent.type(screen.getByLabelText('What is wrong with Cam-02'), 'No picture')
    await userEvent.click(screen.getByRole('button', { name: 'Save check' }))
    await waitFor(() =>
      expect(cctv.saveSheet).toHaveBeenCalledWith(2, expect.objectContaining({
        date: '2026-07-10', storage_used_percent: 82, backup_done: false,
        cameras: [{ camera_id: 5, status: 'working', remarks: null }, { camera_id: 6, status: 'not_working', remarks: 'No picture' }],
      })),
    )
  })

  it('cameras down read in words in both languages', async () => {
    const n = { type: 'cctv.cameras_down', data: { dvr: 'Main building DVR', count: '1', date: '2026-07-10' } } as never
    expect(notificationText(n, i18n.t)).toBe('Main building DVR: 1 camera(s) not working on 10 Jul 2026.')
    await i18n.changeLanguage('mr')
    expect(notificationText(n, i18n.t)).toContain('camera बंद')
  })
})
