import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'
import '@/i18n'

afterEach(() => cleanup())

// The dashboard is the index route of most tests; keep it off the network unless a test sets a value.
vi.mock('@/features/dashboard/api', () => ({ fetchDashboard: vi.fn(() => new Promise(() => {})) }))

// jsdom has no ResizeObserver; recharts' ResponsiveContainer needs one.
globalThis.ResizeObserver ??= class {
  observe() {}
  unobserve() {}
  disconnect() {}
}
