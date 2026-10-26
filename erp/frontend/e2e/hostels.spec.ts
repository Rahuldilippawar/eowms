import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('the warden admits a resident, gives a bed, an out-pass and a fee, and takes the roll call', async ({ page, isMobile }, testInfo) => {
  const name = `E2E Resident ${Date.now().toString().slice(-6)} ${testInfo.project.name}`
  await signIn(page, 'warden@example.com')
  await go(page, isMobile, 'Hostels')
  await page.getByRole('link', { name: /Boys Hostel A/ }).click()
  await expect(page.getByRole('heading', { name: 'Boys Hostel A' })).toBeVisible()

  await page.getByRole('button', { name: 'New resident' }).click()
  const form = page.getByRole('dialog')
  await form.getByLabel('Name', { exact: true }).fill(name)
  await form.getByLabel('School').fill('ZP School No. 2')
  await form.getByLabel('Gender').selectOption('male')
  await form.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('heading', { name })).toBeVisible()

  // Any room with a free bed (runs share one database).
  const free = await page.locator('#rp-room option:not([disabled])').nth(1).getAttribute('value')
  await page.getByLabel('Room', { exact: true }).selectOption(free ?? '')
  await page.getByRole('button', { name: 'Give bed' }).click()
  await expect(page.getByText(/Room \d+, bed/).first()).toBeVisible()

  await page.getByLabel('Reason').fill('Going home for a wedding')
  await page.getByRole('button', { name: 'Give out-pass' }).click()
  await expect(page.getByRole('button', { name: 'Came back today' })).toBeVisible()
  await page.getByRole('button', { name: 'Came back today' }).click()
  await expect(page.getByRole('button', { name: 'Give out-pass' })).toBeVisible()

  await page.getByLabel('For', { exact: true }).fill('July 2026')
  await page.getByLabel('Amount (₹)').fill('1500')
  await page.getByRole('button', { name: 'Add fee' }).click()
  await page.getByPlaceholder('1500').fill('1500')
  await page.getByRole('button', { name: 'Record payment' }).click()
  await expect(page.getByText('Paid', { exact: true })).toBeVisible()

  await page.getByRole('link', { name: 'Boys Hostel A' }).click()
  await page.getByRole('button', { name: 'Roll call' }).click()
  await expect(page.getByRole('heading', { name: 'Roll call' })).toBeVisible()
  await page.getByRole('radiogroup', { name }).getByRole('radio', { name: 'Absent' }).click()
  await page.getByRole('button', { name: 'Save roll call' }).click()
  await expect(page.getByText('Already taken: saving again replaces it.')).toBeVisible()
})
