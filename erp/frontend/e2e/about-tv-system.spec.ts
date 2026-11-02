import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

test('a newcomer sees the welcome tour once', async ({ page }) => {
  // Signed in by hand: while the tour is open the page behind it is hidden from assistive tech.
  await page.addInitScript(() => localStorage.setItem('erp.language', 'en'))
  await page.goto('/login')
  await page.getByLabel('Email').fill('newcomer@example.com')
  await page.getByLabel('Password').fill('password')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  const tour = page.getByRole('dialog', { name: /Welcome, New Staff Member/ })
  // The shared database remembers an earlier run (desktop / mobile, retries): then there is no tour.
  await expect(tour.or(page.getByRole('heading', { name: /^Hello,/ }))).toBeVisible()
  if (await tour.isVisible()) {
    await page.getByRole('button', { name: 'Next' }).click()
    await expect(page.getByText('Step 2 of 6')).toBeVisible()
    await page.getByRole('button', { name: 'Skip' }).click()
    await expect(tour).toBeHidden()
  }
  await page.reload()
  await expect(page.getByRole('heading', { name: /^Hello,/ })).toBeVisible()
  await expect(page.getByRole('dialog')).toHaveCount(0)
})

test('an admin edits the About page and links a notice-board screen', async ({ page, isMobile }) => {
  await signIn(page, 'orgadmin@example.com')
  await page.goto('/about')
  await page.getByRole('button', { name: 'Edit page' }).click()
  await page.getByRole('button', { name: 'Add' }).click()
  const name = `Helper ${Date.now()}`
  await page.getByLabel('Name').fill(name)
  await page.getByLabel('Contribution').fill('Built the library')
  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText(name)).toBeVisible()

  await page.goto('/organization/tv')
  await page.getByLabel('Screen name').fill(isMobile ? 'Gate TV' : 'Office TV')
  await page.getByRole('button', { name: 'Make a link' }).click()
  const link = (await page.locator('code').innerText()).trim()
  expect(link).toMatch(/\/tv\/[A-Za-z0-9]{40}$/)
  const screen = await page.context().newPage()
  await screen.goto(new URL(link).pathname)
  await expect(screen.getByRole('heading', { name: 'Shivaji Education Trust' })).toBeVisible()
  await expect(screen.getByText('Staff', { exact: true })).toBeVisible()
})

test('a super admin sees the system status', async ({ page }) => {
  await signIn(page, 'admin@example.com')
  await page.goto('/admin/system')
  await expect(page.getByText('The database is up to date.')).toBeVisible()
  await expect(page.getByText('Debug mode is off')).toBeVisible()
})
