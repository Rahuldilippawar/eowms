import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('the daily check of a recorder records a camera that is down', async ({ page, isMobile }) => {
  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'CCTV')
  const card = page.getByRole('listitem').filter({ hasText: 'Main building DVR' })
  await card.getByRole('link', { name: 'Daily check' }).click()
  await expect(page.getByRole('heading', { name: 'Daily check' })).toBeVisible()
  await page.getByLabel('Storage used (%)').fill('64')
  await page.getByLabel('Days of footage kept').fill('18')
  await page.getByRole('radiogroup', { name: 'Cam-02' }).getByRole('radio', { name: 'Not working' }).click()
  await page.getByLabel('What is wrong with Cam-02').fill('No picture')
  await page.getByRole('button', { name: 'Save check' }).click()
  await expect(page.getByText(/Already checked by .+: saving again replaces it\./)).toBeVisible()

  await page.getByRole('link', { name: 'Main building DVR' }).click()
  await expect(page.getByText('1 not working · 0 need attention').first()).toBeVisible()
  await page.getByRole('link', { name: 'CCTV', exact: true }).first().click()
  await expect(page.getByRole('listitem').filter({ hasText: 'Main building DVR' })).toContainText('storage 64% used')
})
