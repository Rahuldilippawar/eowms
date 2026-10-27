import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('a teacher reports a computer problem by its tag and IT fixes it', async ({ page, isMobile, browser }, testInfo) => {
  const issue = `E2E screen stays black ${Date.now().toString().slice(-6)} ${testInfo.project.name}`
  await signIn(page, 'teacher@example.com')
  await go(page, isMobile, 'Report a computer problem')
  await page.getByLabel('Tag').fill('MON-26-0001')
  await page.getByRole('button', { name: 'Find' }).click()
  await expect(page.getByText('Computer Lab 1').first()).toBeVisible()
  await page.getByLabel('What is wrong?').fill(issue)
  await page.getByRole('button', { name: 'Send to IT' }).click()
  await expect(page.getByText('Sent to the IT department.')).toBeVisible()
  await expect(page.getByText(issue)).toBeVisible()

  const admin = await (await browser.newContext(testInfo.project.use)).newPage()
  await signIn(admin, 'orgadmin@example.com')
  await go(admin, isMobile, 'IT department')
  await admin.getByRole('link', { name: 'Problems', exact: true }).click()
  const row = admin.getByRole('listitem').filter({ hasText: issue })
  await row.getByRole('button', { name: 'Start work' }).click()
  await expect(row).toContainText('Being fixed')
  await row.getByRole('button', { name: 'Mark fixed' }).click()
  await row.getByLabel(/What was done on MON-26-0001/).fill('Reseated the cable')
  await row.getByRole('button', { name: 'Save' }).click()
  await expect(admin.getByRole('listitem').filter({ hasText: issue })).toHaveCount(0)
})

test('the lab assistant marks the smart board on', async ({ page, isMobile }) => {
  await signIn(page, 'teacher@example.com')
  await go(page, isMobile, 'IT department')
  await page.getByRole('link', { name: 'Smart boards', exact: true }).click()
  await page.getByRole('link', { name: 'SB-26-0001' }).click()
  const toggle = page.getByRole('button', { name: /Mark power (on|off)/ })
  const before = await toggle.textContent()
  await toggle.click()
  await expect(page.getByRole('button', { name: before === 'Mark power on' ? 'Mark power off' : 'Mark power on' })).toBeVisible()
})
