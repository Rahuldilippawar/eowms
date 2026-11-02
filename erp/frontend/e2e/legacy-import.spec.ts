import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

// The end-to-end server has no old database copy, so the page explains how to set one up.
test('a super admin finds the old data import and how to connect the copy', async ({ page }) => {
  await signIn(page, 'admin@example.com')
  await page.goto('/admin/legacy-import')
  await expect(page.getByRole('heading', { name: 'Old data import' })).toBeVisible()
  await expect(page.getByText(/Not set up: put the copy/)).toBeVisible()
})
