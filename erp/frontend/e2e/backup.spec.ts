import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

// The shared end-to-end database must not be restored or cleared here: only the guarded steps before that.
test('a super admin backs up, and a restore is refused without the right password', async ({ page, isMobile }) => {
  await signIn(page, 'admin@example.com')
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: 'Backups', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Backups' })).toBeVisible()
  // Backup files outlive the database between runs, so the list is not counted: the new one is announced.
  await page.getByRole('button', { name: 'Back up now' }).click()
  await expect(page.getByText('Backup made.')).toBeVisible()
  await expect(page.getByRole('listitem').filter({ hasText: 'Manual' }).first()).toBeVisible()

  await page.getByRole('listitem').filter({ hasText: 'Manual' }).first().getByRole('button', { name: /^Restore / }).click()
  const dialog = page.getByRole('dialog', { name: 'Restore the whole database' })
  await dialog.getByLabel('Type RESTORE to confirm').fill('RESTORE')
  await dialog.getByLabel('Your password').fill('not-my-password')
  await dialog.getByRole('button', { name: 'Restore' }).click()
  await expect(dialog.getByText('The password is not correct.')).toBeVisible()
})

test('an organization admin can download the export and reach clearing', async ({ page, isMobile }) => {
  await signIn(page, 'orgadmin@example.com')
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: 'Export / clear data', exact: true }).click()
  await expect(page.getByRole('link', { name: 'Download the export' })).toHaveAttribute('href', '/api/v1/organization/export')
  await page.getByRole('button', { name: 'Clear data' }).click()
  const dialog = page.getByRole('dialog', { name: "Clear this organization's data" })
  await dialog.getByLabel('Your password').fill('password')
  await expect(dialog.getByRole('button', { name: 'Clear data' })).toBeDisabled()
  await dialog.getByRole('button', { name: 'Cancel' }).click()
})
