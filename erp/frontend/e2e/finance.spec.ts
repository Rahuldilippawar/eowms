import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('a purchase is charged to the department budget and paid in parts', async ({ page, isMobile }, testInfo) => {
  const material = `E2E chairs ${Date.now().toString().slice(-6)} ${testInfo.project.name}`
  await signIn(page, 'hod@example.com')
  await go(page, isMobile, 'Material purchases')
  await page.getByRole('button', { name: 'New purchase' }).click()
  const form = page.getByRole('dialog')
  await form.getByLabel('Department').selectOption({ label: 'Physics' })
  await form.getByLabel('Material').fill(material)
  await form.getByLabel('Vendor', { exact: true }).fill('Shree Furniture')
  await form.getByLabel('Quantity').fill('4')
  await form.getByLabel('Rate (₹)').fill('1000')
  await form.getByLabel('GST %').fill('18')
  await expect(form.getByText('₹4,000 + GST ₹720 + transport ₹0 = ₹4,720')).toBeVisible()
  await form.getByRole('button', { name: 'Save' }).click()
  await expect(form).toBeHidden()

  await page.getByRole('button', { name: new RegExp(material) }).click()
  const detail = page.getByRole('dialog')
  await detail.getByLabel('Amount (₹)').fill('2000')
  await detail.getByRole('button', { name: 'Record payment' }).click()
  await expect(detail.getByText('₹2,720')).toBeVisible()
  await detail.getByLabel('Amount (₹)').fill('5000')
  await detail.getByRole('button', { name: 'Record payment' }).click()
  await expect(detail.getByText('More than what is still due (₹2,720.00).')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('button', { name: new RegExp(material) })).toContainText('Partly paid')

  // The department budget shows the spend.
  await go(page, isMobile, 'Department budgets')
  // Wait for the budgets page: the purchases list also has rows that mention Physics.
  await expect(page.getByRole('heading', { name: 'Department budgets' })).toBeVisible()
  const physics = page.getByRole('listitem').filter({ hasText: 'Physics' }).filter({ hasText: 'Budget:' })
  await expect(physics).toContainText('₹2,00,000')
  await expect(physics).toContainText('Purchases')
})
