import { afterEach, describe, expect, it, vi } from 'vitest'
import { monthYm, setOrgTimeZone, todayYmd } from './format'

describe('today in the organization time zone', () => {
  afterEach(() => {
    setOrgTimeZone(null)
    vi.useRealTimers()
  })

  it('follows the organization day, not the browser day', () => {
    vi.useFakeTimers()
    // 26 Oct 18:45 UTC is already 27 Oct 00:15 in India.
    vi.setSystemTime(new Date('2026-10-26T18:45:00Z'))
    setOrgTimeZone('Asia/Kolkata')
    expect(todayYmd()).toBe('2026-10-27')
    vi.setSystemTime(new Date('2026-10-31T19:00:00Z'))
    expect(monthYm()).toBe('2026-11')
    setOrgTimeZone('Not/AZone')
    expect(todayYmd()).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })
})
