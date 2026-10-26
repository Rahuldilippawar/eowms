import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('a teacher sees their quarters and family', async ({ page, isMobile }) => {
  await signIn(page, 'teacher@example.com')
  await go(page, isMobile, 'My residence')
  await expect(page.getByText('Q-101 · Staff Quarters A')).toBeVisible()
  await expect(page.getByText('Asha · Mother · 62 yrs')).toBeVisible()
})

test('the estate office records an item, bills the room and marks a share paid', async ({ page, isMobile }, testInfo) => {
  const stamp = Date.now()
  const item = `E2E Cupboard ${stamp.toString().slice(-6)} ${testInfo.project.name}`
  // A month no other run has billed (runs share one database; one bill per room and month).
  const month = `${2000 + Math.floor(stamp / 1000) % 25}-${String((Math.floor(stamp / 60000) % 12) + 1).padStart(2, '0')}`
  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'Staff housing')
  await page.getByRole('link', { name: /Q-101/ }).click()
  await expect(page.getByRole('heading', { name: 'Q-101' })).toBeVisible()

  await page.getByRole('tab', { name: 'Items' }).click()
  await page.getByRole('button', { name: 'Add item' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Item', { exact: true }).fill(item)
  await dialog.getByLabel('Quantity').fill('2')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText(`${item} × 2`)).toBeVisible()

  await page.getByRole('tab', { name: 'Bills' }).click()
  await page.getByRole('button', { name: 'New bill' }).click()
  await dialog.getByLabel('Month').fill(month)
  await dialog.getByLabel('Rent').fill('1000')
  await dialog.getByLabel('Electricity').fill('350')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('heading', { name: month })).toBeVisible()
  // The box follows the saved state, so it ticks once the server answers.
  const share = page.getByRole('checkbox', { name: new RegExp(`paid for ${month}`) }).first()
  await share.click()
  await expect(share).toBeChecked()

  await page.getByRole('link', { name: 'All rooms' }).click()
  await page.getByRole('navigation', { name: 'Staff housing' }).getByRole('link', { name: 'Bills' }).click()
  await page.getByLabel('Month').fill(month)
  await expect(page.getByRole('link', { name: 'Staff Quarters A · Q-101' })).toBeVisible()
})
