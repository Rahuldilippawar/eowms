import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'
import '@/i18n'

afterEach(() => cleanup())

// The dashboard is the index route of most tests; keep it off the network unless a test sets a value.
vi.mock('@/features/dashboard/api', () => ({ fetchDashboard: vi.fn(() => new Promise(() => {})) }))
// Forms ask which organization-defined fields to show; none unless a test says so.
vi.mock('@/features/people/profileApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/people/profileApi')>()),
  fetchActiveFields: vi.fn(() => Promise.resolve([])),
}))
// The header bell polls the unread count on every page.
vi.mock('@/features/notifications/api', () => ({
  fetchUnreadCount: vi.fn(() => Promise.resolve(0)),
  fetchNotifications: vi.fn(() => new Promise(() => {})),
  markRead: vi.fn(),
  markAllRead: vi.fn(),
}))

// jsdom has no ResizeObserver; recharts' ResponsiveContainer needs one.
globalThis.ResizeObserver ??= class {
  observe() {}
  unobserve() {}
  disconnect() {}
}
