import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import * as lettersApi from '@/features/letters/api'
import { notificationText } from '@/features/notifications/text'
import i18n, { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as assetsApi from './api'

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
  fetchAllocationTypes: vi.fn(),
  fetchAllocationItems: vi.fn(),
  fetchAllocations: vi.fn(),
  giveItem: vi.fn(),
}))
vi.mock('@/features/letters/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/letters/api')>()),
  searchRecipients: vi.fn(),
}))

const auth = vi.mocked(authApi)
const assets = vi.mocked(assetsApi)
const letters = vi.mocked(lettersApi)
const meta = { current_page: 1, last_page: 1, per_page: 50, total: 0, from: 0, to: 0, can_manage: true, overdue: 0 }

describe('issue register', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['assets.view', 'assets.manage'] }))
  })

  it('giving a laptop asks for its serial number first', async () => {
    assets.fetchAllocations.mockResolvedValue({ data: [], meta })
    assets.fetchAllocationTypes.mockResolvedValue({
      data: [{ id: 1, name: 'Laptop', is_loan: false, is_active: true, fields: [{ key: 'serial_no', label: 'Serial no.', type: 'text', options: [], required: true }] }],
      meta: { can_manage: true },
    })
    assets.fetchAllocationItems.mockResolvedValue([{ id: 5, name: 'Dell #12', default_location: null, type: { id: 1, name: 'Laptop', is_loan: false }, held_by: null }])
    letters.searchRecipients.mockResolvedValue([{ user_id: 9, name: 'Amol Patil', designation: null, department: null }])
    assets.giveItem.mockResolvedValue({} as never)
    renderApp('/assets/allocations')

    await userEvent.click(await screen.findByRole('button', { name: 'Give an item' }))
    const dialog = within(await screen.findByRole('dialog'))
    await userEvent.selectOptions(dialog.getByLabelText('Type'), '1')
    await waitFor(() => expect(dialog.getByRole('option', { name: 'Dell #12' })).toBeInTheDocument())
    await userEvent.selectOptions(dialog.getByLabelText('Item'), '5')
    await userEvent.type(dialog.getByLabelText('Give to'), 'Amol')
    await userEvent.click(await dialog.findByRole('button', { name: /Amol Patil/ }))
    await userEvent.click(dialog.getByRole('button', { name: 'Give' }))
    expect(await dialog.findByText('This is required')).toBeInTheDocument()
    expect(assets.giveItem).not.toHaveBeenCalled()

    await userEvent.type(dialog.getByLabelText('Serial no. *'), 'DL-7781')
    await userEvent.click(dialog.getByRole('button', { name: 'Give' }))
    await waitFor(() => expect(assets.giveItem).toHaveBeenCalledWith({ item_id: 5, user_id: 9, purpose: null, due_at: null, custom: { serial_no: 'DL-7781' } }))
  })

  it('a borrowed item reads with its return time in both languages', async () => {
    const n = { type: 'assets.allocated', data: { item: 'Lab 2 key', due: '2026-07-10T17:30:00+05:30' } } as never
    expect(notificationText(n, i18n.t)).toMatch(/^Lab 2 key was given to you — return by 10 Jul 2026 /)
    expect(notificationText({ type: 'assets.allocated', data: { item: 'Laptop', due: '' } } as never, i18n.t)).toBe('Laptop was given to you.')
    await i18n.changeLanguage('mr')
    expect(notificationText(n, i18n.t)).toContain('परत करा')
  })
})
