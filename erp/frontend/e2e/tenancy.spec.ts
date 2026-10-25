import { expect, test, type Page } from '@playwright/test'
import { apiGet, signIn } from './helpers'

async function openNav(page: Page, isMobile: boolean, name: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name }).click()
}

test('principal sees only their college’s departments, and the API refuses others by id', async ({ page, isMobile }) => {
  await signIn(page, 'principal@example.com')
  await openNav(page, isMobile, 'Departments')

  await expect(page.getByRole('heading', { name: 'Departments' })).toBeVisible()
  const list = isMobile ? page.getByRole('list').last() : page.getByRole('table')
  await expect(list.getByText('Physics', { exact: true })).toBeVisible()
  await expect(list.getByText('Library', { exact: true })).toBeVisible()
  await expect(list.getByText('Primary Section', { exact: true })).toHaveCount(0)
  await expect(list.getByText('IT', { exact: true })).toHaveCount(0)

  // Manipulated id: a department of the same organization outside the principal's college.
  const all = await apiGet(page, '/api/v1/departments?per_page=100')
  expect(all.status()).toBe(200)
  const visibleIds: number[] = (await all.json()).data.map((d: { id: number }) => d.id)
  expect(visibleIds.length).toBe(2)
  for (let id = 1; id <= 10; id++) {
    if (visibleIds.includes(id)) continue
    const res = await apiGet(page, `/api/v1/departments/${id}`)
    expect(res.status(), `department ${id}`).toBe(404)
  }
})

test('staff without the institutions permission has no such menu entry and the API says 403', async ({ page, isMobile }) => {
  await signIn(page, 'staff@example.com')
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await expect(page.getByRole('link', { name: 'Departments' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Institutions' })).toHaveCount(0)

  const res = await apiGet(page, '/api/v1/institutions')
  expect(res.status()).toBe(403)
})

test('super admin switches organization and sees only that organization’s data', async ({ page, isMobile }) => {
  await signIn(page, 'admin@example.com')
  await page.getByRole('combobox', { name: 'Switch organization' }).selectOption({ label: 'SHT — Sanjeevani Hospital Trust' })
  await expect(page.getByText('Organization: Sanjeevani Hospital Trust')).toBeVisible()

  await openNav(page, isMobile, 'Departments')
  const list = isMobile ? page.getByRole('list').last() : page.getByRole('table')
  await expect(list.getByText('Nursing', { exact: true })).toBeVisible()
  await expect(list.getByText('Physics', { exact: true })).toHaveCount(0)
})

test('forgot password gives the same answer for any email', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('erp.language', 'en'))
  await page.goto('/login')
  await page.getByRole('link', { name: 'Forgot password?' }).click()
  await page.getByLabel('Email').fill('nobody-here@example.com')
  await page.getByRole('button', { name: 'Send link' }).click()
  await expect(page.getByRole('status')).toContainText('If an active account exists')
})
