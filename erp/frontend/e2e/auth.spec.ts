import { expect, test } from '@playwright/test'
import { apiGet } from './helpers'

// Seeded by DatabaseSeeder (development/test data only).
const ADMIN = { email: 'admin@example.com', password: 'password' }

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('erp.language', 'en'))
})

test('guest is redirected to sign in, signs in, reloads, and signs out', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible()

  await page.getByLabel('Email').fill(ADMIN.email)
  await page.getByLabel('Password').fill(ADMIN.password)
  await page.getByRole('button', { name: 'Sign in' }).click()

  await expect(page.getByRole('heading', { name: 'Hello, Dev Admin' })).toBeVisible()

  // Session survives a full reload (cookie-based, nothing in localStorage).
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Hello, Dev Admin' })).toBeVisible()
  const stored = await page.evaluate(() => JSON.stringify(localStorage))
  expect(stored).not.toMatch(/token/i)

  // While signed in, the session cookie authenticates API calls…
  expect((await apiGet(page, '/api/v1/auth/me')).status()).toBe(200)

  await page.getByRole('button', { name: /Dev Admin/ }).click()
  await page.getByRole('menuitem', { name: 'Sign out' }).click()
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible()

  // …and after sign-out the same cookie jar is no longer accepted.
  expect((await apiGet(page, '/api/v1/auth/me')).status()).toBe(401)
})

test('wrong password shows an error and stays on the sign-in page', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill(ADMIN.email)
  await page.getByLabel('Password').fill('definitely-wrong')
  await page.getByRole('button', { name: 'Sign in' }).click()

  await expect(page.getByRole('alert')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible()
})

test('mobile navigation drawer opens and closes', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'drawer is only used on small screens')
  await page.goto('/login')
  await page.getByLabel('Email').fill(ADMIN.email)
  await page.getByLabel('Password').fill(ADMIN.password)
  await page.getByRole('button', { name: 'Sign in' }).click()

  await page.getByRole('button', { name: 'Open menu' }).click()
  const drawer = page.getByRole('dialog')
  await expect(drawer.getByRole('link', { name: 'Dashboard' })).toBeVisible()
  await drawer.getByRole('link', { name: 'Dashboard' }).click()
  await expect(drawer).toBeHidden()
})

test('unknown API paths return JSON 404 without debug output', async ({ request }) => {
  const res = await request.get('/api/v1/nope')
  expect(res.status()).toBe(404)
  expect(res.headers()['content-type']).toContain('application/json')
  expect(await res.text()).not.toContain('trace')
})
