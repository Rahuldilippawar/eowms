import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

// WebAuthn needs a domain name (not an IP address), so this spec uses localhost. Chromium's virtual
// authenticator stands in for the phone's fingerprint sensor.
test.use({ baseURL: 'http://localhost:8000' })

test('add a fingerprint, then sign in with it', async ({ page, isMobile }) => {
  const cdp = await page.context().newCDPSession(page)
  await cdp.send('WebAuthn.enable')
  await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true },
  })

  // The shared end-to-end database keeps earlier runs' devices, so each run names its own.
  const device = `${isMobile ? 'Phone' : 'Laptop'} ${Date.now()}`
  await signIn(page, 'teacher@example.com')
  await page.goto('/account/fingerprint')
  await page.getByLabel('Name of this device').fill(device)
  await page.getByLabel('Your password').fill('password')
  await page.getByRole('button', { name: 'Add fingerprint' }).click()
  await expect(page.getByText('Fingerprint added.')).toBeVisible()
  await expect(page.getByRole('listitem').filter({ hasText: device })).toBeVisible()

  await page.getByRole('button', { name: 'Physics Teacher' }).click()
  await page.getByRole('menuitem', { name: 'Sign out' }).click()
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible()
  await page.getByRole('button', { name: 'Sign in with fingerprint' }).click()
  await expect(page.getByRole('heading', { name: /^Hello,/ })).toBeVisible()
})
