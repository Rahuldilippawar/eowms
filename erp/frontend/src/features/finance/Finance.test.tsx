import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import * as orgApi from '@/features/org/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as financeApi from './api'
import type { PurchaseDetail } from './api'

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
  fetchBudgets: vi.fn(),
  allocateBudget: vi.fn(),
  checkBudget: vi.fn(),
  fetchPurchases: vi.fn(),
  fetchPurchase: vi.fn(),
  savePurchase: vi.fn(),
  payPurchase: vi.fn(),
}))
vi.mock('@/features/org/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/org/api')>()),
  fetchDepartmentOptions: vi.fn(),
}))

const auth = vi.mocked(authApi)
const finance = vi.mocked(financeApi)
const org = vi.mocked(orgApi)
const page = { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 }

const purchase = (over: Partial<PurchaseDetail> = {}): PurchaseDetail => ({
  id: 4, department: { id: 1, name: 'Physics', code: 'PHY' }, material_name: 'Glass beakers', vendor_name: 'Lab Supplies', vendor_contact: null,
  quantity: 10, unit: 'pcs', rate: 150, base_amount: 1500, transport_cost: 100, gst_percent: 18, gst_amount: 270, total_amount: 1870,
  paid_amount: 1000, due_amount: 870, payment_status: 'partial', purchase_date: '2026-06-05', remarks: null, created_by: 'Dev Admin', can_manage: true,
  payments: [{ id: 1, amount: 1000, payment_date: '2026-06-06', payment_type: 'advance', payment_mode: 'UPI', remarks: null, recorded_by: 'Dev Admin' }],
  ...over,
})

describe('department finance', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['finance.view', 'finance.manage', 'finance.budget'] }))
  })

  it('shows each department’s budget use and lets an allocator set it', async () => {
    finance.fetchBudgets.mockResolvedValue({
      data: [{ department: { id: 1, name: 'Physics', code: 'PHY' }, allocated: 10000, spent: 11870, remaining: -1870, percent_used: 119, by_category: { purchase: 11870 }, by_source: { material_purchase: 1870, letter: 10000 }, purchases_billed: 1870, purchases_paid: 1000 }],
      meta: { fy: 2026, current_fy: 2026, can_allocate: true },
    })
    finance.allocateBudget.mockResolvedValue({})
    renderApp('/finance/budgets')
    expect(await screen.findByText('Over by ₹1,870')).toBeInTheDocument()
    expect(screen.getByText(/Purchases ₹1,870 · Approved letters ₹10,000/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Set the budget of Physics' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.clear(within(dialog).getByLabelText('Budget'))
    await userEvent.type(within(dialog).getByLabelText('Budget'), '15000')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(finance.allocateBudget).toHaveBeenCalledWith({ department_id: 1, fy: 2026, allocated_amount: 15000 }))
  })

  it('a new purchase shows its total and warns when the budget would be exceeded', async () => {
    finance.fetchPurchases.mockResolvedValue({ data: [], meta: { ...page, total: 0, fy: 2026, current_fy: 2026, can_manage: true } })
    org.fetchDepartmentOptions.mockResolvedValue([{ id: 1, name: 'Physics', code: 'PHY', institution_id: null }] as never)
    finance.checkBudget.mockResolvedValue({ fy: 2026, remaining: 500, exceeds: true })
    finance.savePurchase.mockResolvedValue(purchase())
    renderApp('/finance/purchases')
    await userEvent.click(await screen.findByRole('button', { name: 'New purchase' }))
    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByRole('option', { name: 'Physics' })
    await userEvent.selectOptions(within(dialog).getByLabelText('Department'), '1')
    await userEvent.type(within(dialog).getByLabelText('Material'), 'Glass beakers')
    await userEvent.type(within(dialog).getByLabelText('Vendor'), 'Lab Supplies')
    await userEvent.type(within(dialog).getByLabelText('Quantity'), '10')
    await userEvent.type(within(dialog).getByLabelText('Rate (₹)'), '150')
    await userEvent.type(within(dialog).getByLabelText('GST %'), '18')
    await userEvent.type(within(dialog).getByLabelText('Transport (₹)'), '100')
    expect(within(dialog).getByText('₹1,500 + GST ₹270 + transport ₹100 = ₹1,870')).toBeInTheDocument()
    expect(await within(dialog).findByText(/takes the department over its budget \(left: ₹500\)/)).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() =>
      expect(finance.savePurchase).toHaveBeenCalledWith(null, expect.objectContaining({ department_id: 1, quantity: 10, rate: 150, gst_percent: 18, transport_cost: 100 })),
    )
  })

  it('records a payment against what is due', async () => {
    finance.fetchPurchases.mockResolvedValue({ data: [purchase()], meta: { ...page, fy: 2026, current_fy: 2026, can_manage: true } })
    finance.fetchPurchase.mockResolvedValue(purchase())
    finance.payPurchase.mockResolvedValue(purchase({ paid_amount: 1870, due_amount: 0, payment_status: 'paid' }))
    renderApp('/finance/purchases')
    await userEvent.click(await screen.findByRole('button', { name: /Glass beakers/ }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('₹870')).toBeInTheDocument()
    await userEvent.type(within(dialog).getByLabelText('Amount (₹)'), '870')
    await userEvent.selectOptions(within(dialog).getByLabelText('Type'), 'final')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Record payment' }))
    await waitFor(() => expect(finance.payPurchase).toHaveBeenCalledWith(4, expect.objectContaining({ amount: 870, payment_type: 'final' })))
  })
})
