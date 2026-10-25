import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('HOD assigns a task; the teacher is notified, works it and logs work; the HOD verifies it', async ({ page, browser, isMobile }, testInfo) => {
  const title = `E2E task ${Date.now().toString().slice(-6)}`

  const hodContext = await browser.newContext(testInfo.project.use)
  const hod = await hodContext.newPage()
  await signIn(hod, 'hod@example.com')
  await go(hod, isMobile, 'Tasks')
  await hod.getByRole('button', { name: 'Assign a task' }).click()
  const dialog = hod.getByRole('dialog')
  await dialog.getByLabel('Task', { exact: true }).fill(title)
  await dialog.getByLabel('Assigned to').selectOption({ label: 'Physics Teacher (EMP-003) — Physics' })
  await dialog.getByLabel('Priority').selectOption('high')
  await dialog.getByRole('button', { name: 'Assign' }).click()
  await expect(hod.getByRole('heading', { name: title })).toBeVisible()
  const taskUrl = hod.url()

  // The teacher sees the notification and follows it to the task.
  await signIn(page, 'teacher@example.com')
  await page.getByRole('button', { name: /^Notifications, \d+ unread$/ }).click()
  await page.getByRole('menuitem', { name: new RegExp(`assigned you TSK-\\d+ “${title}”`) }).click()
  await expect(page.getByRole('heading', { name: title })).toBeVisible()
  await page.getByRole('button', { name: 'Start' }).click()
  await expect(page.getByText('Task started.')).toBeVisible()

  // Log today's work against it.
  await go(page, isMobile, 'My work')
  await page.getByLabel('What did you do?').fill(`Worked on ${title}`)
  await page.getByLabel('Hours (optional)').fill('1')
  const taskSelect = page.getByLabel('Related task (optional)')
  const option = taskSelect.locator('option', { hasText: title })
  await expect(option).toHaveCount(1)
  await taskSelect.selectOption((await option.getAttribute('value')) ?? '')
  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('listitem').filter({ hasText: `Worked on ${title}` })).toBeVisible()

  await page.goto(taskUrl.replace(/^https?:\/\/[^/]+/, ''))
  await page.getByRole('button', { name: 'Mark done' }).click()
  const done = page.getByRole('dialog')
  await done.getByLabel('Note (optional)').fill('Projector fixed')
  await done.getByRole('button', { name: 'Mark done' }).click()
  await expect(page.getByText('Sent for checking.')).toBeVisible()
  await expect(page.getByText('Waiting for check')).toBeVisible()

  // The HOD verifies and closes it.
  await hod.reload()
  await hod.getByRole('button', { name: 'Verify & close' }).click()
  await hod.getByRole('dialog').getByRole('button', { name: 'Verify & close' }).click()
  await expect(hod.getByText('Task closed.')).toBeVisible()
  const history = hod.getByRole('list').filter({ hasText: 'verified and closed it' })
  await expect(history).toContainText('Projector fixed')
  await hodContext.close()

  await page.reload()
  await expect(page.getByText('Completed', { exact: true })).toBeVisible()
})
