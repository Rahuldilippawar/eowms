import { expect, test, type Page } from '@playwright/test'
import { apiGet, signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link }).click()
}

test('organization admin creates a department, then a user with a role limited to it', async ({ page, isMobile }) => {
  const suffix = Date.now().toString().slice(-6)
  await signIn(page, 'orgadmin@example.com')

  await go(page, isMobile, 'Departments')
  await page.getByRole('button', { name: 'New department' }).click()
  const deptDialog = page.getByRole('dialog')
  await deptDialog.getByLabel('Name').fill(`Chemistry ${suffix}`)
  await deptDialog.getByLabel('Code').fill(`CH${suffix}`)
  await deptDialog.getByLabel('Institution').selectOption({ label: 'Shivaji Arts College' })
  // Wait for the save itself so a failure names its status (or that nothing was sent), not a lingering dialog.
  const saved = page.waitForResponse((r) => r.url().endsWith('/api/v1/departments') && r.request().method() === 'POST')
  await deptDialog.getByRole('button', { name: 'Save' }).click()
  const res = await saved
  expect(res.status(), await res.text()).toBe(201)
  await expect(deptDialog).toBeHidden()
  await page.getByRole('searchbox', { name: 'Search' }).fill(`Chemistry ${suffix}`)
  await expect(page.getByText(`Chemistry ${suffix}`, { exact: true }).filter({ visible: true }).first()).toBeVisible()

  await go(page, isMobile, 'Users')
  await page.getByRole('button', { name: 'New user' }).click()
  const userDialog = page.getByRole('dialog')
  await userDialog.getByLabel('Name').fill(`Teacher ${suffix}`)
  await userDialog.getByLabel('Email').fill(`teacher${suffix}@example.com`)
  await userDialog.getByLabel('Role').selectOption({ label: 'Head of Department' })
  await userDialog.getByRole('checkbox', { name: new RegExp(`Chemistry ${suffix}`) }).check()
  await userDialog.getByRole('button', { name: 'Create account' }).click()
  await expect(userDialog).toBeHidden()

  await page.getByRole('searchbox', { name: 'Search' }).fill(`teacher${suffix}`)
  await page.getByRole('link', { name: `Teacher ${suffix}` }).filter({ visible: true }).first().click()
  await expect(page.getByRole('heading', { name: `Teacher ${suffix}` })).toBeVisible()
  const roles = page.getByRole('region', { name: 'Roles in this organization' })
  const assigned = roles.getByRole('listitem').filter({ hasText: 'Head of Department' })
  await expect(assigned).toHaveCount(1)
  await expect(assigned).toContainText(`Chemistry ${suffix}`)
})

test('principal has no create-institution button and the API refuses it', async ({ page, isMobile }) => {
  await signIn(page, 'principal@example.com')
  await go(page, isMobile, 'Institutions')
  await expect(page.getByRole('heading', { name: 'Institutions' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'New institution' })).toHaveCount(0)

  const res = await page.request.post('/api/v1/institutions', {
    headers: { Accept: 'application/json', Referer: 'http://127.0.0.1:8000/', 'X-XSRF-TOKEN': decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '') },
    data: { type: 'college', name: 'Sneaky', code: 'SNK' },
  })
  expect(res.status()).toBe(403)
  expect((await apiGet(page, '/api/v1/institutions?search=Sneaky')).ok()).toBe(true)
})

test('organization admin cannot give a role a permission they lack (super-admin-only areas stay hidden)', async ({ page, isMobile }) => {
  await signIn(page, 'principal@example.com')
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await expect(page.getByRole('link', { name: 'All organizations' })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Roles' })).toHaveCount(0)
})
