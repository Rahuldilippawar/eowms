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
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('heading', { name: /^Hello,/ })).toBeVisible()
}
