import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { notificationText } from '@/features/notifications/text'
import * as storeApi from '@/features/store/api'
import i18n, { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as purchasingApi from './api'

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
  fetchOrder: vi.fn(),
  receiveGoods: vi.fn(),
}))
vi.mock('@/features/store/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/store/api')>()),
  fetchStoreItem: vi.fn(),
  moveStock: vi.fn(),
}))

const auth = vi.mocked(authApi)
const purchasing = vi.mocked(purchasingApi)
const store = vi.mocked(storeApi)

const order: purchasingApi.OrderDetail = {
  id: 7, po_number: 'PO/26-27/001', status: 'issued', order_date: '2026-06-02', department: { id: 3, name: 'Maintenance' }, vendor_name: 'Shree Traders', total_amount: 5800, invoiced_amount: 0,
  vendor: { id: 1, name: 'Shree Traders', contact_person: null, phone: null, email: null, gstin: null, address: null, is_active: true },
  quotation: null, expected_date: null, terms: null, subtotal: 5200, gst_amount: 600, created_by: 'Org Admin', issued_at: null,
  lines: [
    { id: 11, item_id: 4, description: 'A4 paper', quantity: 20, unit: 'ream', rate: 250, gst_percent: 12, amount: 5600, received_quantity: 15 },
    { id: 12, item_id: null, description: 'Delivery', quantity: 1, unit: null, rate: 200, gst_percent: 0, amount: 200, received_quantity: 0 },
  ],
  receipts: [], invoices: [], received_value: 4200, invoiceable_amount: 4200, can_manage: false, can_receive: true, budget_left: null,
}

describe('store and purchasing', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
  })

  it('the store receives what is still to come on an order', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['store.view', 'store.manage'] }))
    purchasing.fetchOrder.mockResolvedValue(order)
    purchasing.receiveGoods.mockResolvedValue({ ...order, status: 'received' })
    renderApp('/purchasing/orders/7')
    await userEvent.click(await screen.findByRole('button', { name: 'Receive goods' }))
    const paper = screen.getByLabelText('A4 paper — received now (up to 5 ream)')
    expect(paper).toHaveValue(5)
    await userEvent.clear(paper)
    await userEvent.type(paper, '3')
    await userEvent.click(screen.getByRole('button', { name: 'Save goods receipt' }))
    await waitFor(() =>
      expect(purchasing.receiveGoods).toHaveBeenCalledWith(7, expect.objectContaining({ lines: [{ line_id: 11, quantity: 3 }, { line_id: 12, quantity: 1 }] })),
    )
    // Without purchasing.manage there is nothing to invoice or cancel.
    expect(screen.queryByRole('button', { name: 'Record invoice' })).not.toBeInTheDocument()
  })

  it('a correction needs a reason before it is sent', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['store.view', 'store.manage'] }))
    store.fetchStoreItem.mockResolvedValue({
      id: 4, item_name: 'Cement', category: null, unit: 'bag', reorder_level: 5, department: null, is_active: true, balance: 3, low: true, movements: [], can_manage: true,
    })
    renderApp('/store/items/4')
    expect(await screen.findByText('Low stock — time to reorder')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Correct count' }))
    await userEvent.type(screen.getByLabelText('Change by (+ or −)'), '-1')
    await userEvent.click(screen.getAllByRole('button', { name: 'Correct count' }).at(-1)!)
    expect(await screen.findByText('Write why the count changes.')).toBeInTheDocument()
    expect(store.moveStock).not.toHaveBeenCalled()
  })

  it('a low-stock reminder reads in words in both languages', async () => {
    const n = { type: 'store.low_stock', data: { item: 'Cement', balance: '4', unit: 'bag' } } as never
    expect(notificationText(n, i18n.t)).toBe('Cement is low: 4 bag left.')
    await i18n.changeLanguage('mr')
    expect(notificationText(n, i18n.t)).toBe('Cement कमी: 4 bag शिल्लक.')
  })
})
