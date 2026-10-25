import { expect, test, type Page } from '@playwright/test'
import { apiGet, signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

/** A plain grey 120×120 PNG (photos must be at least 100×100). */
function png(): Buffer {
  return Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAHgAAAB4CAIAAAC2BqGFAAAArElEQVR42u3QMQEAAAwCIKMb3RbbAxFIOREFokUjWrRoRItGtGjRiBaNaNGiES0a0aJFI1o0okWLRrRoRIsWjWjRiBYtGtGiES1aNKJFI1q0aESLRrRo0YgWjWjRohEtGtGiRSNaNKJFi0a0aESLFo1o0YgWLRrRohEtWjSiRSNatGhEi0a0aNGIFo1o0aIRLRrRokUjWjSiRYtGtGhEixaNaNGIFi0a0aIR/W+Ne2Tt8gZi7wAAAABJRU5ErkJggg==', 'base64')
}

test('admin imports staff, completes a profile with private data and photo, prints the ID card; the QR page verifies it', async ({ page, isMobile }) => {
  const suffix = Date.now().toString().slice(-6)
  const code = `IMP${suffix}`
  await signIn(page, 'orgadmin@example.com')

  await go(page, isMobile, 'Employees')
  await page.getByRole('link', { name: 'Import staff (CSV)' }).click()
  const csv = `employee_code,name,department_code,institution_code,designation,joining_date,gender\n${code},Imported ${suffix},PHY,SAC,Lab Assistant,01/07/2026,female\n${code}X,Second ${suffix},NOPE,,,,\n`
  await page.getByLabel('CSV file').setInputFiles({ name: 'staff.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) })
  await expect(page.getByText(/2 rows, 1 with problems/)).toBeVisible()
  await expect(page.getByRole('button', { name: 'Import 2 rows' })).toBeDisabled()

  await page.getByLabel('CSV file').setInputFiles({ name: 'staff.csv', mimeType: 'text/csv', buffer: Buffer.from(csv.split('\n').slice(0, 2).join('\n') + '\n') })
  await expect(page.getByText('1 rows, all OK.')).toBeVisible()
  await page.getByRole('button', { name: 'Import 1 row' }).click()
  await expect(page.getByRole('status').filter({ hasText: '1 employee imported.' }).first()).toBeVisible()

  // Open the new profile.
  await page.getByRole('link', { name: 'Employees', exact: true }).first().click()
  await page.getByRole('searchbox', { name: 'Search' }).fill(code)
  await page.getByRole('link', { name: `Imported ${suffix}` }).filter({ visible: true }).first().click()
  await expect(page.getByRole('heading', { name: `Imported ${suffix}` })).toBeVisible()

  await page.getByLabel("Father's name").fill('Ramesh')
  await page.getByLabel('Blood group').selectOption('B+')
  await page.getByLabel('आश्रमात राहतात').check()
  await page.getByRole('button', { name: 'Save' }).first().click()
  await expect(page.getByRole('status').filter({ hasText: 'Saved' }).first()).toBeVisible()

  await page.getByRole('tab', { name: 'Private' }).click()
  await page.getByLabel('New Aadhaar number').fill('1234 5678 9012')
  await page.getByLabel('IFSC').fill('sbin0001234')
  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('••••••••9012')).toBeVisible()
  await page.getByRole('button', { name: 'Show full numbers' }).click()
  await expect(page.getByText('123456789012')).toBeVisible()

  await page.locator('#photo-input').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: png() })
  await expect(page.getByText('Photo saved.')).toBeVisible()
  await expect(page.getByRole('img', { name: `Photo of Imported ${suffix}` })).toBeVisible()

  // ID card with the QR code; the QR link opens the public check.
  await page.getByRole('link', { name: 'ID card' }).click()
  await expect(page.getByRole('img', { name: `Verification QR code for Imported ${suffix}` })).toBeVisible()
  const id = Number(new URL(page.url()).searchParams.get('ids'))
  const token = (await (await apiGet(page, `/api/v1/employees/${id}/profile`)).json()).data.public_token as string

  const visitor = await page.context().browser()!.newPage()
  await visitor.goto(`/verify/employee/${token}`)
  await expect(visitor.getByText(`Imported ${suffix}`)).toBeVisible()
  await expect(visitor.getByRole('status')).toContainText(/Currently working here|सध्या येथे कार्यरत/)
  await visitor.close()
})
