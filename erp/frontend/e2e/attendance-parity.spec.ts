import { expect, test, type Page } from '@playwright/test'
import { BASE, signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

test('holiday is skipped in leave days; a machine punch shows up in the in/out register', async ({ page, browser, isMobile }, testInfo) => {
  const suffix = Date.now().toString().slice(-6)
  // A Monday-to-Wednesday span far ahead, unique per project and run; Tuesday becomes a holiday.
  const monday = new Date()
  monday.setDate(monday.getDate() + 60 + (testInfo.project.name === 'mobile' ? 700 : 0) + (Number(suffix) % 300) * 7)
  monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7))
  const tuesday = new Date(monday)
  tuesday.setDate(monday.getDate() + 1)
  const wednesday = new Date(monday)
  wednesday.setDate(monday.getDate() + 2)

  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'Holidays')
  await page.getByRole('button', { name: 'New holiday' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Date').fill(ymd(tuesday))
  await dialog.getByLabel('Name').fill(`E2E holiday ${suffix}`)
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(dialog).toBeHidden()

  // Machine PIN on the teacher's profile, and a registered machine.
  const pin = `9${suffix}`
  await go(page, isMobile, 'Employees')
  await page.getByRole('searchbox', { name: 'Search' }).fill('EMP-003')
  await page.getByRole('link', { name: 'Physics Teacher' }).filter({ visible: true }).first().click()
  await page.getByRole('tab', { name: 'Attendance' }).click()
  await page.getByLabel('Machine PIN').fill(pin)
  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Saved' }).first()).toBeVisible()

  const serial = `E2E-${suffix}-${testInfo.project.name}`
  await go(page, isMobile, 'Attendance machines')
  await page.getByRole('button', { name: 'Add machine' }).click()
  await page.getByRole('dialog').getByLabel('Name').fill(`Gate ${suffix}`)
  await page.getByRole('dialog').getByLabel('Serial number').fill(serial)
  await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText(serial)).toBeVisible()

  // The machine pushes a punch, as a ZKTeco device does (no session, no CSRF).
  const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }))
  const stamp = `${ymd(now)} ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:00`
  const device = await page.context().request.post(`${BASE}/iclock/cdata?SN=${encodeURIComponent(serial)}&table=ATTLOG`, { data: `${pin}\t${stamp}\t0\t1\n`, headers: { 'Content-Type': 'text/plain' } })
  expect(await device.text()).toBe('OK: 1')

  await go(page, isMobile, 'Team attendance')
  await page.getByRole('tab', { name: 'In / out' }).click()
  await expect(page.getByRole('row').filter({ hasText: 'Physics Teacher' }).filter({ hasText: 'machine' }).first()).toBeVisible()

  // The teacher's leave over Mon–Wed counts 2 days: Tuesday is the holiday.
  const teacherContext = await browser.newContext(testInfo.project.use)
  const teacher = await teacherContext.newPage()
  await signIn(teacher, 'teacher@example.com')
  await go(teacher, isMobile, 'My leave')
  await teacher.getByLabel('Leave type').selectOption({ label: 'Leave Without Pay' })
  await teacher.getByLabel('From', { exact: true }).fill(ymd(monday))
  await teacher.getByLabel('To', { exact: true }).fill(ymd(wednesday))
  await teacher.getByLabel('Reason', { exact: true }).fill(`E2E ${suffix}`)
  await teacher.getByRole('button', { name: 'Submit' }).click()
  await expect(teacher.getByRole('listitem').filter({ hasText: `E2E ${suffix}` })).toContainText('2 days')
  await teacherContext.close()

  // Salary report is there for the admin.
  await go(page, isMobile, 'Salary report')
  await expect(page.getByRole('rowheader', { name: /Physics Teacher/ })).toBeVisible()
})
