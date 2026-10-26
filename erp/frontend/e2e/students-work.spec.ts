import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}
const csv = (name: string, text: string) => ({ name, mimeType: 'text/csv', buffer: Buffer.from(text) })
// 1×1 PNG.
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64')

test('students import, then show up with their roll number', async ({ page, isMobile }, testInfo) => {
  const code = `E2E-S${Date.now().toString().slice(-6)}${testInfo.project.name === 'mobile' ? 'M' : 'D'}`
  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'Students')
  await page.getByRole('link', { name: 'Import students' }).click()
  await page.getByLabel('CSV file').setInputFiles(csv('students.csv', `student_code,name,institution_code,department_code,roll_no,year_of_study,academic_year\n${code},Imported Student ${code},SAC,PHY,77,1,2026-27\n`))
  await expect(page.getByText('1 rows, all OK.')).toBeVisible()
  await page.getByRole('button', { name: /^Import 1 row/ }).click()
  await expect(page.getByRole('status').filter({ hasText: '1 student imported.' }).first()).toBeVisible()

  await page.getByRole('main').getByRole('link', { name: 'Students' }).click()
  await page.getByRole('searchbox', { name: 'Search' }).fill(code)
  const row = isMobile ? page.getByRole('listitem').filter({ hasText: code }) : page.getByRole('row').filter({ hasText: code })
  await expect(row.first()).toContainText('77')
  await expect(row.first()).toContainText('2026-27')
})

test('daily work with a colleague, cost and a before photo; old records import', async ({ page, browser, isMobile }, testInfo) => {
  const title = `E2E repair ${Date.now().toString().slice(-6)} ${testInfo.project.name}`
  await signIn(page, 'teacher@example.com')
  await go(page, isMobile, 'My work')
  await page.getByLabel('What did you do?').fill(title)
  await page.getByLabel('Requested by').fill('Principal office')
  await page.getByLabel('Actual cost (₹)').fill('450')
  await page.getByLabel('Done together with').fill('IT St')
  await page.getByRole('button', { name: /Add IT Staff/ }).click()
  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Saved' }).first()).toBeVisible()

  const item = page.getByRole('listitem').filter({ hasText: title })
  await expect(item).toContainText('With IT Staff')
  await expect(item).toContainText('₹450')
  await item.getByLabel('Before: add photo').setInputFiles({ name: 'before.png', mimeType: 'image/png', buffer: png })
  await expect(item.getByRole('img', { name: `Before photo: ${title}` })).toBeVisible()

  // An admin brings in an old record for the same teacher, with an old date.
  const adminContext = await browser.newContext(testInfo.project.use)
  const admin = await adminContext.newPage()
  await signIn(admin, 'orgadmin@example.com')
  await go(admin, isMobile, 'Team work log')
  await admin.getByRole('link', { name: 'Import old work records' }).click()
  const oldTitle = `${title} (old)`
  // Well before the 3-day edit window, but inside the one-year history view.
  const old = new Date()
  old.setDate(old.getDate() - 100)
  const oldDmy = `${String(old.getDate()).padStart(2, '0')}/${String(old.getMonth() + 1).padStart(2, '0')}/${old.getFullYear()}`
  const oldYmd = `${old.getFullYear()}-${String(old.getMonth() + 1).padStart(2, '0')}-${String(old.getDate()).padStart(2, '0')}`
  await admin.getByLabel('CSV file').setInputFiles(csv('work.csv', `date,employee_code,title,team_codes,actual_cost\n${oldDmy},EMP-003,${oldTitle},EMP-004,300\n`))
  await admin.getByRole('button', { name: /^Import 1 row/ }).click()
  await expect(admin.getByRole('status').filter({ hasText: '1 work record imported.' }).first()).toBeVisible()
  await adminContext.close()

  await page.getByLabel('From').fill(oldYmd)
  await expect(page.getByRole('listitem').filter({ hasText: oldTitle })).toContainText('Old record')
})
