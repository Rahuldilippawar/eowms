import { expect, test, type Page } from '@playwright/test'
import { apiGet, signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

test('teacher checks in and applies for leave; their HOD approves it', async ({ page, browser, isMobile }, testInfo) => {
  const suffix = Date.now().toString().slice(-6)
  await signIn(page, 'teacher@example.com')

  // Check-in happens once a day: the second project (or a re-run) finds it already done.
  await go(page, isMobile, 'My attendance')
  const checkIn = page.getByRole('button', { name: 'Check in' })
  const checkOut = page.getByRole('button', { name: 'Check out' })
  // Enabled once today's record has loaded (the teacher never checks out here, so one of the two is usable).
  await expect(checkIn.or(checkOut)).toBeEnabled()
  if (await checkIn.isVisible()) {
    await checkIn.click()
    await expect(page.getByText('Checked in.')).toBeVisible()
  }
  await expect(checkOut).toBeVisible()
  const today = await (await apiGet(page, '/api/v1/me/attendance')).json()
  expect(today.data.some((r: { check_in_at: string | null }) => r.check_in_at)).toBe(true)

  // A future range unique to this project and run, so overlap checks never trip.
  const start = new Date()
  start.setDate(start.getDate() + 30 + (testInfo.project.name === 'mobile' ? 400 : 0) + (Number(suffix) % 300))
  const end = new Date(start)
  end.setDate(end.getDate() + 1)

  await go(page, isMobile, 'My leave')
  await page.getByLabel('Leave type').selectOption({ label: 'Casual Leave' })
  await page.getByLabel('From', { exact: true }).fill(ymd(start))
  await page.getByLabel('To', { exact: true }).fill(ymd(end))
  await page.getByLabel('Reason', { exact: true }).fill(`E2E ${suffix}`)
  await page.getByRole('button', { name: 'Submit' }).click()
  await expect(page.getByText('Leave request sent.')).toBeVisible()
  const mine = page.getByRole('listitem').filter({ hasText: `E2E ${suffix}` })
  await expect(mine).toContainText('Pending')
  await expect(mine).toContainText('2 days')

  // The teacher's reporting officer (HOD Physics) approves it.
  const hodContext = await browser.newContext(testInfo.project.use)
  const hod = await hodContext.newPage()
  await signIn(hod, 'hod@example.com')
  await go(hod, isMobile, 'Approvals')
  const row = hod.getByRole('listitem').filter({ hasText: `E2E ${suffix}` })
  await row.getByRole('button', { name: /^Approve:/ }).click()
  const dialog = hod.getByRole('dialog')
  await dialog.getByLabel('Remarks (optional)').fill('Approved in E2E')
  await dialog.getByRole('button', { name: 'Approve' }).click()
  await expect(dialog).toBeHidden()
  await expect(hod.getByText('Approved.')).toBeVisible()
  await expect(row).toHaveCount(0)
  await hodContext.close()

  await page.reload()
  await expect(page.getByRole('listitem').filter({ hasText: `E2E ${suffix}` })).toContainText('Approved')
})

test('staff without people permissions cannot open the employee list', async ({ page }) => {
  await signIn(page, 'teacher@example.com')
  const res = await apiGet(page, '/api/v1/employees')
  expect(res.status()).toBe(403)
  await page.goto('/employees')
  await expect(page.getByRole('heading', { name: 'Employees' })).toHaveCount(0)
})
