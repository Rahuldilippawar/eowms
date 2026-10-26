import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { notificationText } from '@/features/notifications/text'
import i18n, { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as renewalsApi from './api'
import type { RenewalDetail } from './api'

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
  fetchRenewals: vi.fn(),
  fetchRenewal: vi.fn(),
  renewItem: vi.fn(),
  payRenewal: vi.fn(),
}))

const auth = vi.mocked(authApi)
const renewals = vi.mocked(renewalsApi)
const page = { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 }

const item = (over: Partial<RenewalDetail> = {}): RenewalDetail => ({
  id: 3, department: { id: 1, name: 'IT' }, responsible: null, item_type: 'Software License', regulatory_body: null, item_name: 'Antivirus',
  reference_no: 'LIC-1', issued_date: '2025-06-30', expiry_date: '2026-06-30', days_left: 20, status: 'due_soon', renewal_cost: 12000,
  is_recurring_monthly: false, monthly_amount: null, vendor_name: 'Quick Heal', is_active: true, cycle_paid: 5000, vendor_contact: null,
  recurring_start_month: null, can_manage: true, months: [], history: [], payments: [], attachments: [], ...over,
})

describe('renewals', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['renewals.view', 'renewals.manage'] }))
  })

  it('shows the counts and the days left; a count filters the list', async () => {
    renewals.fetchRenewals.mockResolvedValue({
      data: [item(), item({ id: 4, item_name: 'Fire NOC', days_left: -3, status: 'expired' })],
      meta: { ...page, summary: { expired: 1, due_soon: 1, active: 4 }, by_regulatory_body: [], can_manage: true, regulatory_bodies: [] },
    })
    renderApp('/renewals')
    expect(await screen.findByText('20 days left')).toBeInTheDocument()
    expect(screen.getByText('Expired 3 days ago')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /^1\s*Expired$/ }))
    await waitFor(() => expect(renewals.fetchRenewals).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'expired' })))
  })

  it('renewing needs a later expiry date', async () => {
    renewals.fetchRenewal.mockResolvedValue(item())
    renewals.renewItem.mockResolvedValue(item({ expiry_date: '2027-06-30', days_left: 385, status: 'active', cycle_paid: 0 }))
    renderApp('/renewals/3')
    expect(await screen.findByText('This cycle: ₹5,000 paid, ₹7,000 still due.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Renew' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('New expiry date'), '2026-06-01')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Renew' }))
    expect(await within(dialog).findByText('Choose a date after the current expiry.')).toBeInTheDocument()
    await userEvent.clear(within(dialog).getByLabelText('New expiry date'))
    await userEvent.type(within(dialog).getByLabelText('New expiry date'), '2027-06-30')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Renew' }))
    await waitFor(() => expect(renewals.renewItem).toHaveBeenCalledWith(3, expect.objectContaining({ new_expiry_date: '2027-06-30', renewal_cost: 12000 })))
  })

  it('a monthly bill is paid for a chosen month', async () => {
    renewals.fetchRenewal.mockResolvedValue(item({ is_recurring_monthly: true, monthly_amount: 8000, renewal_cost: null, months: [{ month: '2026-04', paid: true }, { month: '2026-05', paid: false }] }))
    renewals.payRenewal.mockResolvedValue(item())
    renderApp('/renewals/3')
    expect(await screen.findByText(/a month · 1 month not paid yet/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Record payment' }))
    await waitFor(() => expect(renewals.payRenewal).toHaveBeenCalledWith(3, expect.objectContaining({ amount: 8000, for_month: '2026-05', payment_type: 'final' })))
  })

  it('reminder texts carry the date', () => {
    const n = { id: 1, type: 'renewal.due', data: { item: 'Fire NOC', days: 7, expiry_date: '2026-06-30' }, link: null, read_at: null, created_at: '' }
    expect(notificationText(n, i18n.t)).toBe('Fire NOC expires in 7 days (30 Jun 2026).')
  })
})
