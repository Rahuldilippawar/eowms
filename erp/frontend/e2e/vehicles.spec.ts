import { expect, test, type Page } from '@playwright/test'
import { orgDate, signIn } from './helpers'

async function go(page: Page, isMobile: boolean, link: string) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click()
  await page.getByRole('link', { name: link, exact: true }).click()
}

test('a vehicle with its insurance date, fuel charged; the driver logs a trip and a correction is approved', async ({ page, browser, isMobile }, testInfo) => {
  const suffix = `${Date.now().toString().slice(-6)}${testInfo.project.name === 'mobile' ? 'M' : 'D'}`
  const number = `E2E${suffix}`

  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'Vehicles')
  await expect(page.getByRole('heading', { name: 'Vehicles' })).toBeVisible()
  await page.getByRole('button', { name: 'New vehicle' }).click()
  const form = page.getByRole('dialog')
  await form.getByLabel('Vehicle number').fill(number)
  await form.getByLabel('Department').selectOption({ label: 'Transport' })
  await form.getByLabel('Type').fill('Van')
  await form.getByLabel('Odometer now (km)').fill('100')
  await form.getByLabel('Insurance valid till').fill(orgDate(10))
  await form.getByRole('button', { name: 'Save' }).click()

  await expect(page.getByRole('heading', { name: number })).toBeVisible()
  await expect(page.getByRole('listitem').filter({ hasText: 'Insurance' })).toContainText('10 days left')
  await page.getByRole('tab', { name: 'Fuel' }).click()
  await page.getByRole('button', { name: 'Log fuel' }).click()
  const fuel = page.getByRole('dialog')
  await expect(fuel.getByLabel('Odometer (km)')).toHaveValue('100')
  await fuel.getByLabel('Litres').fill('10')
  await fuel.getByLabel('Cost (₹)').fill('900')
  await fuel.getByRole('button', { name: 'Save' }).click()
  await expect(fuel).toBeHidden()
  await expect(page.getByText('10 L at 100 km')).toBeVisible()
  await expect(page.getByText('₹900').first()).toBeVisible()

  // The seeded driver reaches their bus without any vehicle permission, and logs a trip from its odometer.
  const driverContext = await browser.newContext(testInfo.project.use)
  const driver = await driverContext.newPage()
  await signIn(driver, 'driver@example.com')
  await go(driver, isMobile, 'Vehicles')
  await driver.getByRole('link', { name: /MH12AB1234/ }).click()
  await expect(driver.getByRole('heading', { name: 'MH12AB1234' })).toBeVisible()
  await driver.getByRole('button', { name: 'Log trip' }).click()
  const trip = driver.getByRole('dialog')
  const start = Number(await trip.getByLabel('Start odometer (km)').inputValue())
  await trip.getByLabel('From').fill('College')
  await trip.getByLabel('To').fill(`Station ${suffix}`)
  await trip.getByLabel('End odometer (km)').fill(String(start + 25))
  await expect(trip.getByText('Distance: 25 km')).toBeVisible()
  await trip.getByRole('button', { name: 'Save' }).click()
  await expect(trip).toBeHidden()
  const row = driver.getByRole('listitem').filter({ hasText: `Station ${suffix}` })
  await expect(row).toContainText('25 km')
  await row.getByRole('button', { name: 'Request correction' }).click()
  const ask = driver.getByRole('dialog')
  await ask.getByLabel(/What is wrong/).fill(`End reading off ${suffix}`)
  await ask.getByRole('button', { name: 'Send request' }).click()
  await expect(row).toContainText('Correction requested')
  await driverContext.close()

  // The vehicle manager fixes the end reading and approves.
  await go(page, isMobile, 'Vehicle corrections')
  const request = page.getByRole('listitem').filter({ hasText: `End reading off ${suffix}` })
  await request.getByRole('button', { name: 'Fix and approve' }).click()
  const fix = page.getByRole('dialog')
  await fix.getByLabel('End odometer (km)').fill(String(start + 20))
  await fix.getByRole('button', { name: 'Fix and approve' }).click()
  await expect(fix).toBeHidden()
  await expect(request).toBeHidden()
})

test('maintenance work is logged against a location and paid', async ({ page, isMobile }, testInfo) => {
  const item = `E2E switchboard ${Date.now().toString().slice(-6)} ${testInfo.project.name}`
  await signIn(page, 'orgadmin@example.com')
  await go(page, isMobile, 'Maintenance register')
  await expect(page.getByRole('heading', { name: 'Maintenance register' })).toBeVisible()
  await page.getByRole('button', { name: 'Log work' }).click()
  const form = page.getByRole('dialog')
  await form.getByLabel('Location').selectOption({ label: 'Main Building · Physics Lab · (MB-101)' })
  await expect(form.getByLabel('Department')).toHaveValue(/\d+/)
  await form.getByLabel('Work', { exact: true }).selectOption('replaced')
  await form.getByLabel('Item / work').fill(item)
  await form.getByLabel('Cost (₹)').fill('500')
  await form.getByRole('button', { name: 'Save' }).click()
  await expect(form).toBeHidden()

  await page.getByRole('button', { name: new RegExp(item) }).click()
  const detail = page.getByRole('dialog')
  await expect(detail).toContainText('₹500')
  await detail.getByLabel('Amount (₹)').fill('500')
  await detail.getByLabel('Type', { exact: true }).selectOption('final')
  await detail.getByRole('button', { name: 'Record payment' }).click()
  await expect(detail.getByRole('button', { name: 'Record payment' })).toBeHidden()

  await page.keyboard.press('Escape')
  await page.getByRole('tab', { name: 'Locations' }).click()
  await page.getByRole('link', { name: 'Physics Lab' }).click()
  await expect(page.getByRole('heading', { name: 'Physics Lab' })).toBeVisible()
  await expect(page.getByText(item)).toBeVisible()
})
