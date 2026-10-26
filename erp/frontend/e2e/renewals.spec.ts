import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('a renewal is created, paid (charged to the budget) and renewed', async ({ page, isMobile }, testInfo) => {
  const name = `E2E fire NOC ${Date.now().toString().slice(-6)} ${testInfo.project.name}`
  const soon = new Date()
  soon.setDate(soon.getDate() + 10)
  const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  const later = new Date(soon)
  later.setFullYear(later.getFullYear() + 1)

  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'Renewals')
  await page.getByRole('button', { name: 'New renewal' }).click()
  const form = page.getByRole('dialog')
  await form.getByLabel('Name').fill(name)
  await form.getByLabel('Type').fill('Fire NOC')
  await form.getByLabel('Department').selectOption({ label: 'Physics' })
  await form.getByLabel('Regulatory body').selectOption('Other Government')
  await form.getByLabel('Expires on').fill(ymd(soon))
  await form.getByLabel('Renewal cost (₹)').fill('3000')
  await form.getByRole('button', { name: 'Save' }).click()

  await expect(page.getByRole('heading', { name })).toBeVisible()
  await expect(page.getByText('10 days left')).toBeVisible()
  await page.getByLabel('Amount (₹)').fill('3000')
  await page.getByLabel('Type', { exact: true }).selectOption('final')
  await page.getByRole('button', { name: 'Record payment' }).click()
  await expect(page.getByText('This cycle: ₹3,000 paid, ₹0 still due.')).toBeVisible()

  await page.getByRole('button', { name: 'Renew', exact: true }).click()
  const renew = page.getByRole('dialog')
  await renew.getByLabel('New expiry date').fill(ymd(later))
  await renew.getByRole('button', { name: 'Renew', exact: true }).click()
  await expect(renew).toBeHidden()
  await expect(page.getByText('Earlier cycles')).toBeVisible()
  await expect(page.getByText('This cycle: ₹0 paid, ₹3,000 still due.')).toBeVisible()

  // The payment shows on the Physics budget as a renewal spend.
  await go(page, isMobile, 'Department budgets')
  await expect(page.getByRole('listitem').filter({ hasText: 'Physics' })).toContainText(/Renewals ₹[\d,]+/)
})
