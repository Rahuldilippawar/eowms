import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end tests run against the real Laravel backend serving the built SPA.
 * Prerequisites (CI does these): `npm run build`, then in ../backend
 * `php artisan migrate:fresh --seed` on a throwaway database.
 */
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: 'http://127.0.0.1:8000',
    trace: 'retain-on-failure',
    launchOptions: { executablePath },
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], launchOptions: { executablePath } } },
    { name: 'mobile', use: { ...devices['Pixel 7'], launchOptions: { executablePath } } },
  ],
  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8000',
    cwd: '../backend',
    url: 'http://127.0.0.1:8000/api/v1/health',
    // Production-like settings: debug off, so error responses are checked as users would see them.
    env: { APP_ENV: 'testing', APP_DEBUG: 'false', LOGIN_THROTTLE_PER_EMAIL: '100', LOGIN_THROTTLE_PER_IP: '200', PASSWORD_RESET_THROTTLE_PER_EMAIL: '100', PASSWORD_RESET_THROTTLE_PER_IP: '200', API_THROTTLE_PER_MINUTE: '1000', WEBAUTHN_RP_ID: 'localhost', WEBAUTHN_ORIGINS: 'http://localhost:8000' },
    reuseExistingServer: false,
    timeout: 60_000,
  },
})
