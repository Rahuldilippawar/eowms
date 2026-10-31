import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

test('the app is installable and each person chooses push and e-mail per group', async ({ page }) => {
  for (const [path, type] of [['/manifest.webmanifest', 'manifest'], ['/sw.js', 'javascript'], ['/offline.html', 'html']]) {
    const res = await page.request.get(path)
    expect(res.status(), path).toBe(200)
    expect(res.headers()['content-type'] ?? '', path).toContain(type)
  }

  await signIn(page, 'teacher@example.com')
  await page.goto('/notifications')
  await page.getByRole('link', { name: 'Settings' }).click()
  await expect(page.getByRole('heading', { name: 'Notification settings' })).toBeVisible()
  await expect(page.getByText('Push notifications are not set up on the server yet.')).toBeVisible()
  const tasksEmail = page.getByRole('checkbox', { name: 'Tasks: E-mail' })
  const want = !(await tasksEmail.isChecked())
  // The box turns as soon as React applies the change, a moment after the click.
  await tasksEmail.click()
  await expect(tasksEmail).toBeChecked({ checked: want })
  await expect(page.getByText('Settings saved.')).toBeVisible()
  await page.reload()
  await expect(page.getByRole('checkbox', { name: 'Tasks: E-mail' })).toBeChecked({ checked: want })
})
