import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('an order is issued, the goods come into the store, and the invoice is recorded and paid', async ({ page, isMobile }, testInfo) => {
  const invoiceNo = `E2E-${Date.now().toString().slice(-6)}-${testInfo.project.name}`
  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'Purchasing')
  await page.getByRole('link', { name: 'New order' }).click()
  await page.getByLabel('Department').selectOption({ label: 'Maintenance' })
  await page.getByLabel('Vendor').selectOption({ label: 'Shree Traders' })
  await page.getByLabel('Line 1: store item').selectOption({ label: 'A4 paper' })
  await page.getByLabel('Line 1: quantity').fill('4')
  await page.getByLabel('Line 1: rate').fill('250')
  await page.getByLabel('Line 1: GST %').fill('12')
  await expect(page.getByText('Total with GST: ₹1,120')).toBeVisible()
  await page.getByRole('button', { name: 'Save draft' }).click()
  await expect(page.getByRole('heading', { name: /Draft order/ })).toBeVisible()

  await page.getByRole('button', { name: 'Issue order' }).click()
  await expect(page.getByRole('heading', { name: /PO\/\d\d-\d\d\/\d+/ })).toBeVisible()

  await page.getByRole('button', { name: 'Receive goods' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByLabel(/A4 paper — received now/)).toHaveValue('4')
  await dialog.getByRole('button', { name: 'Save goods receipt' }).click()
  await expect(page.getByRole('heading', { name: 'Goods receipts' })).toBeVisible()
  await expect(page.getByText('Received', { exact: true }).first()).toBeVisible()

  await page.getByRole('button', { name: 'Record invoice' }).click()
  await dialog.getByLabel("Vendor's invoice number").fill(invoiceNo)
  await expect(dialog.getByLabel('Goods with GST (₹)')).toHaveValue('1120')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText(invoiceNo)).toBeVisible()
  await page.getByRole('button', { name: 'Pay', exact: true }).click()
  await dialog.getByRole('button', { name: 'Record payment' }).click()
  await expect(page.getByText(/paid ₹1,120 · due ₹0/)).toBeVisible()

  // The paper came into the store from the order.
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: 'Store', exact: true }).click()
  await page.getByRole('link', { name: 'A4 paper' }).click()
  await expect(page.getByText('from a purchase order').first()).toBeVisible()
})

test('the store issues stock and cannot give out more than it has', async ({ page, isMobile }) => {
  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'Store')
  await page.getByRole('link', { name: 'Cement' }).click()
  await page.getByRole('button', { name: 'Issue', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Quantity (bag)').fill('100000')
  await dialog.getByRole('button', { name: 'Issue', exact: true }).click()
  await expect(dialog.getByText(/in stock/)).toBeVisible()
  await dialog.getByRole('button', { name: 'Cancel' }).click()
  await page.getByRole('button', { name: 'Receive' }).click()
  await dialog.getByLabel('Quantity (bag)').fill('2')
  await dialog.getByRole('button', { name: 'Receive' }).click()
  await expect(page.getByText('+2').first()).toBeVisible()
})
