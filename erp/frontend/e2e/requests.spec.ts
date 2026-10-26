import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('a teacher raises a request, the department completes it, the teacher rates it', async ({ page, browser, isMobile }, testInfo) => {
  const title = `E2E tap ${Date.now().toString().slice(-6)} ${testInfo.project.name}`
  await signIn(page, 'teacher@example.com')
  await go(page, isMobile, 'Work requests')
  await expect(page.getByRole('heading', { name: 'Work requests' })).toBeVisible()
  await page.getByRole('button', { name: 'New request' }).click()
  const form = page.getByRole('dialog')
  await form.getByLabel('Kind of work').selectOption('maintenance')
  await form.getByLabel('To department').selectOption({ label: 'Maintenance' })
  await form.getByLabel('Priority').selectOption('high')
  await form.getByLabel('Short title').fill(title)
  await form.getByLabel('Details').fill('The lab sink tap keeps leaking.')
  await form.getByRole('button', { name: 'Send request' }).click()
  await expect(page.getByRole('heading', { name: title })).toBeVisible()
  const code = (await page.getByText(/^REQ-\d{2}-\d{4}$/).textContent())?.trim() ?? ''

  // The organization admin works it from the department queue.
  const adminContext = await browser.newContext(testInfo.project.use)
  const admin = await adminContext.newPage()
  await signIn(admin, 'orgadmin@example.com')
  await go(admin, isMobile, 'Work requests')
  await admin.getByRole('searchbox', { name: 'Search' }).fill(code)
  await admin.getByRole('link', { name: new RegExp(title) }).click()
  await expect(admin.getByRole('heading', { name: title })).toBeVisible()
  await admin.getByLabel('Status', { exact: true }).selectOption('completed')
  await admin.getByLabel('Cost (₹)').fill('150')
  await admin.getByLabel('What was done').fill('Washer replaced')
  await admin.getByRole('button', { name: 'Update' }).click()
  await expect(admin.getByText('Washer replaced').first()).toBeVisible()
  await adminContext.close()

  await page.reload()
  await expect(page.getByText('Washer replaced').first()).toBeVisible()
  await page.getByRole('radio', { name: '4 stars' }).click()
  await page.getByRole('button', { name: 'Close with rating' }).click()
  await expect(page.getByText('Closed', { exact: true }).first()).toBeVisible()
})

test('the public form gives a number that the tracker shows with the phone number', async ({ page }, testInfo) => {
  const phone = `98${Date.now().toString().slice(-7)}${testInfo.project.name === 'mobile' ? 1 : 2}`
  await page.addInitScript(() => localStorage.setItem('erp.language', 'en'))
  await page.goto('/request/SET')
  await page.getByLabel('Your name').fill('E2E Parent')
  await page.getByLabel('Your phone number').fill(phone)
  await page.getByLabel('To department').selectOption({ label: 'Maintenance' })
  await page.getByLabel('What needs doing').fill('The gate light near the bus stop is off.')
  await page.getByRole('button', { name: 'Send request' }).click()
  await expect(page.getByText('Your request has been received.')).toBeVisible()
  const code = (await page.getByText(/^REQ-\d{2}-\d{4}$/).textContent())?.trim() ?? ''

  await page.getByRole('link', { name: 'Check the status of a request' }).click()
  await page.getByLabel('Request number').fill(code)
  await page.getByLabel('Your phone number').fill('9000000000')
  await page.getByRole('button', { name: 'Show status' }).click()
  await expect(page.getByRole('alert')).toContainText('No request matches')
  await page.getByLabel('Your phone number').fill(phone)
  await page.getByRole('button', { name: 'Show status' }).click()
  await expect(page.getByText('Not opened by the department yet')).toBeVisible()
  await expect(page.getByText('Pending', { exact: true })).toBeVisible()
})

test('an anonymous grievance reaches the handler without a name and is resolved', async ({ page, browser, isMobile }, testInfo) => {
  const text = `E2E canteen water ${Date.now().toString().slice(-6)} ${testInfo.project.name}`
  await signIn(page, 'teacher@example.com')
  await go(page, isMobile, 'Grievances')
  await page.getByRole('button', { name: 'New grievance' }).click()
  const form = page.getByRole('dialog')
  await form.getByLabel('Subject').fill('Facilities')
  await form.getByLabel('What happened').fill(text)
  await form.getByLabel(/Send anonymously/).check()
  await form.getByRole('button', { name: 'Send grievance' }).click()
  await expect(page.getByText('You sent this anonymously; only you can see it is yours.')).toBeVisible()

  const adminContext = await browser.newContext(testInfo.project.use)
  const admin = await adminContext.newPage()
  await signIn(admin, 'orgadmin@example.com')
  await go(admin, isMobile, 'Grievances')
  const row = admin.getByRole('listitem').filter({ hasText: text })
  await expect(row).toContainText('Anonymous')
  await row.getByRole('link').click()
  await admin.getByLabel('Status', { exact: true }).selectOption('resolved')
  await admin.getByLabel('Resolution').fill('Filter replaced.')
  await admin.getByRole('button', { name: 'Save' }).click()
  await expect(admin.getByText('Filter replaced.').first()).toBeVisible()
  await adminContext.close()

  await page.reload()
  await expect(page.getByText('Filter replaced.').first()).toBeVisible()
})
