import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

test('the reports hub opens the printable work report and the campus comparison', async ({ page, isMobile }) => {
  await signIn(page, 'orgadmin@example.com')
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: 'Reports', exact: true }).click()
  await page.getByRole('link', { name: /Work report \(print\)/ }).click()
  await expect(page.getByRole('heading', { name: 'Work report (print)' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'By department' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Print / PDF' })).toBeVisible()

  await page.goBack()
  await page.getByRole('link', { name: /Campus comparison/ }).click()
  await expect(page.getByRole('columnheader', { name: 'Shivaji Arts College' })).toBeVisible()
  await expect(page.getByRole('columnheader', { name: 'Shivaji High School' })).toBeVisible()
  await expect(page.getByRole('rowheader', { name: 'Staff' })).toBeVisible()
})
