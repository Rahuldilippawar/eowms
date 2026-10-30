import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

test('search finds a person and the sign-in log shows who signed in', async ({ page, isMobile }) => {
  await signIn(page, 'orgadmin@example.com')
  if (isMobile) await page.getByRole('button', { name: 'Search (Ctrl+K)' }).click()
  else await page.keyboard.press('Control+k')
  const box = page.getByRole('combobox', { name: 'Search' })
  await box.fill('EMP-003')
  await expect(page.getByRole('option', { name: /Physics Teacher/ })).toBeVisible()
  await box.press('Enter')
  await expect(page.getByRole('heading', { name: 'Physics Teacher' })).toBeVisible()

  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: 'Activity log', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Activity log' })).toBeVisible()
  await page.getByRole('link', { name: 'Sign-ins', exact: true }).click()
  await expect(page.getByRole('row').filter({ hasText: 'Org Admin (SET)' }).filter({ hasText: 'Signed in' }).first()).toBeVisible()
})
