import { expect, test, type Browser, type Page, type TestInfo } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

async function as(browser: Browser, testInfo: TestInfo, email: string) {
  const context = await browser.newContext(testInfo.project.use)
  const page = await context.newPage()
  await signIn(page, email)
  return { context, page }
}

test('a file goes teacher → HOD → principal, is signed, and the QR check confirms it', async ({ page, browser, isMobile }, testInfo) => {
  const subject = `E2E microscopes ${Date.now().toString().slice(-6)} ${testInfo.project.name}`

  // The teacher writes the file with the two-step workflow and sends it.
  await signIn(page, 'teacher@example.com')
  await go(page, isMobile, 'Correspondence')
  await page.getByRole('link', { name: 'New file' }).click()
  await page.getByLabel('Subject').fill(subject)
  await page.getByLabel('Text').fill('Two microscopes for the physics lab.')
  await page.getByLabel('Approval workflow').selectOption({ label: 'विभाग-प्रमुख → प्राचार्य' })
  await page.getByRole('button', { name: 'Save draft' }).click()
  await expect(page.getByRole('heading', { name: subject })).toBeVisible()
  await page.getByRole('button', { name: 'Send', exact: true }).click()
  await expect(page.getByRole('dialog').getByText('Next step: विभाग-प्रमुखाकडे → HOD Physics')).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: 'Send', exact: true }).click()
  await expect(page.getByText('With HOD Physics since', { exact: false })).toBeVisible()
  await expect(page.getByText(/Outward no\. PHY\/\d{2}-\d{2}\/\d{4}/)).toBeVisible()

  // The HOD finds it in the inbox and sends it on to the principal.
  const hod = await as(browser, testInfo, 'hod@example.com')
  await go(hod.page, isMobile, 'Correspondence')
  await hod.page.getByRole('link', { name: new RegExp(subject) }).click()
  await hod.page.getByRole('button', { name: 'Send on' }).click()
  await hod.page.getByRole('dialog').getByLabel('Note (optional)').fill('Recommended')
  await hod.page.getByRole('dialog').getByRole('button', { name: 'Send on' }).click()
  await expect(hod.page.getByText('With Principal (SAC) since', { exact: false })).toBeVisible()
  await hod.context.close()

  // The principal signs with their password; the file is closed and gets an inward number in their department.
  const principal = await as(browser, testInfo, 'principal@example.com')
  await go(principal.page, isMobile, 'Correspondence')
  await principal.page.getByRole('link', { name: new RegExp(subject) }).click()
  await principal.page.getByRole('button', { name: 'Sign and close' }).click()
  const sign = principal.page.getByRole('dialog')
  await sign.getByLabel('Password').fill('wrong-password')
  await sign.getByRole('button', { name: 'Sign and close' }).click()
  await expect(sign.getByText('Wrong password: the file was not signed.')).toBeVisible()
  await sign.getByLabel('Password').fill('password')
  await sign.getByRole('button', { name: 'Sign and close' }).click()
  await expect(principal.page.getByText('Signed and closed').first()).toBeVisible()
  await expect(principal.page.getByText(/LIB\/IN\/\d{2}-\d{2}\/\d{4}/)).toBeVisible()
  const code = (await principal.page.getByRole('link', { name: /^#[A-Z0-9]{12}$/ }).textContent())?.slice(1) ?? ''

  await principal.page.getByRole('link', { name: 'Print / PDF' }).click()
  await expect(principal.page.getByRole('img', { name: `QR code to check signature ${code}` })).toBeVisible()
  await principal.context.close()

  // Anyone with the QR link can check it, without signing in.
  const guest = await browser.newContext(testInfo.project.use)
  const check = await guest.newPage()
  await check.addInitScript(() => localStorage.setItem('erp.language', 'en'))
  await check.goto(`/verify/signature/${code}`)
  await expect(check.getByText('Genuine: the file is as it was signed')).toBeVisible()
  await expect(check.getByText('Principal (SAC)', { exact: false })).toBeVisible()
  await expect(check.getByText(subject)).toHaveCount(0)
  await guest.close()
})
