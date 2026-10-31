import { expect, type Page } from '@playwright/test'

export const BASE = 'http://127.0.0.1:8000'

/** API call from the signed-in page, as the SPA makes it (first-party Referer, so Sanctum uses the session). */
export function apiGet(page: Page, path: string) {
  return page.request.get(path, { headers: { Accept: 'application/json', Referer: `${BASE}/` } })
}

/** Seeded by DatabaseSeeder (development/test data only); password "password". */
export async function signIn(page: Page, email: string) {
  await page.addInitScript(() => localStorage.setItem('erp.language', 'en'))
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill('password')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await expect(page.getByRole('heading', { name: /^Hello,/ })).toBeVisible()
}

/**
 * "YYYY-MM-DD" in the seeded organizations' time zone (Asia/Kolkata), shifted by whole days and
 * years. The server counts "days left" from that day, which differs from the browser's day for
 * 5½ hours each night on a UTC runner.
 */
export function orgDate(days = 0, years = 0): string {
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
  const [y, m, d] = today.split('-').map(Number)
  return new Date(Date.UTC(y + years, m - 1, d + days)).toISOString().slice(0, 10)
}
