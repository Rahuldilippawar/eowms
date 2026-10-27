import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('a teacher sees the laptop given to them', async ({ page, isMobile }) => {
  await signIn(page, 'teacher@example.com')
  await go(page, isMobile, 'Items with me')
  await expect(page.getByText('Dell Latitude #12')).toBeVisible()
  await expect(page.getByText('Serial no.: DL-7781')).toBeVisible()
})

test('a department records dead stock bought new', async ({ page, isMobile }) => {
  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'Dead stock')
  await page.getByRole('button', { name: 'New entry' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Department', { exact: true }).selectOption({ label: 'Maintenance' })
  await dialog.getByLabel('Item', { exact: true }).selectOption({ label: 'Projector (Electronics)' })
  await dialog.getByLabel('New purchase — charged to the department budget').check()
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(dialog.getByText('Enter the cost of a new purchase.')).toBeVisible()
  await dialog.getByLabel('Cost (₹)').fill('15000')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText(/Recorded as DS-MNT-\d{4}\./)).toBeVisible()
  await expect(page.getByText('Projector').first()).toBeVisible()
})

test('a key is added, lent with a return time and taken back', async ({ page, isMobile }, testInfo) => {
  const key = `E2E key ${Date.now().toString().slice(-6)} ${testInfo.project.name}`
  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'Issue register')
  await page.getByRole('link', { name: 'Types and items' }).click()
  await page.getByLabel('New Room keys item', { exact: true }).fill(key)
  await page.getByRole('listitem').filter({ hasText: 'Room keys' }).getByRole('button', { name: 'Add' }).click()
  await expect(page.getByText(key)).toBeVisible()

  await page.getByRole('link', { name: 'Who holds what' }).click()
  await page.getByRole('button', { name: 'Give an item' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Type', { exact: true }).selectOption({ label: 'Room keys (borrowed)' })
  await dialog.getByLabel('Item', { exact: true }).selectOption({ label: key })
  await dialog.getByLabel('Give to').fill('Physics')
  await dialog.getByRole('button', { name: /Physics Teacher/ }).click()
  const due = new Date(Date.now() + 2 * 86400000)
  const local = `${due.getFullYear()}-${String(due.getMonth() + 1).padStart(2, '0')}-${String(due.getDate()).padStart(2, '0')}T17:00`
  await dialog.getByLabel('Return by').fill(local)
  await dialog.getByRole('button', { name: 'Give', exact: true }).click()
  const row = page.getByRole('listitem').filter({ hasText: key })
  await expect(row).toContainText('Physics Teacher')
  await expect(row).toContainText('return by')

  await row.getByRole('button', { name: 'Take back' }).click()
  await dialog.getByRole('button', { name: 'Take back' }).click()
  await expect(page.getByRole('listitem').filter({ hasText: key })).toHaveCount(0)
})
