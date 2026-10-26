import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import * as maintenanceApi from '@/features/maintenance/api'
import { notificationText } from '@/features/notifications/text'
import i18n, { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as vehiclesApi from './api'
import type { VehicleDetail } from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchVehicle: vi.fn(),
  addEntry: vi.fn(),
  requestCorrection: vi.fn(),
}))
vi.mock('@/features/maintenance/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/maintenance/api')>()),
  fetchLocations: vi.fn(),
  fetchLogs: vi.fn(),
  saveLog: vi.fn(),
}))

const auth = vi.mocked(authApi)
const vehicles = vi.mocked(vehiclesApi)
const maintenance = vi.mocked(maintenanceApi)

const bus = (over: Partial<VehicleDetail> = {}): VehicleDetail => ({
  id: 7, vehicle_number: 'MH12AB1234', vehicle_type: 'Bus', model: 'Starbus', department: { id: 1, name: 'Transport' }, driver: { id: 9, name: 'Bus Driver' },
  fuel_type: 'diesel', current_odometer: 45200, is_active: true, documents_status: 'due_soon', next_document: { field: 'insurance', expiry_date: '2026-06-30', days_left: 20 },
  next_service: { date: '2026-06-17', days_left: 7, km: null, km_left: null, due: true }, chassis_no: null, engine_no: null, vehicle_class: null, passenger_capacity: 40,
  registration_date: null, documents: [{ field: 'insurance', renewal_id: 3, expiry_date: '2026-06-30', days_left: 20, status: 'due_soon' }],
  stats: { total_km: 120, total_liters: 20, km_per_liter: 6, fuel_cost: 1800, service_cost: 0 }, trend: [{ month: '2026-06', km: 120, liters: 20 }],
  trips: [{ id: 11, type: 'trip', date: '2026-06-09', from_location: 'College', to_location: 'Station', start_time: null, end_time: null, start_odometer: 45080, end_odometer: 45200, distance_km: 120, purpose: null, created_by: 'Bus Driver', created_by_id: 9 }],
  fuel: [], services: [], pending_corrections: [], can_manage: false, can_log: true, ...over,
})

describe('vehicles', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: [], drives_vehicle: true }))
  })

  it('a driver sees the menu entry, logs a trip from the current odometer and asks for a correction', async () => {
    vehicles.fetchVehicle.mockResolvedValue(bus())
    vehicles.addEntry.mockResolvedValue(bus())
    vehicles.requestCorrection.mockResolvedValue({ id: 1, status: 'pending' })
    renderApp('/vehicles/7')
    expect(await screen.findByRole('heading', { name: 'MH12AB1234' })).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Vehicles' }).length).toBeGreaterThan(0)
    expect(screen.getByText('Service in 7 days')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Log trip' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText('Start odometer (km)')).toHaveValue(45200)
    await userEvent.type(within(dialog).getByLabelText('From'), 'College')
    await userEvent.type(within(dialog).getByLabelText('To'), 'Station')
    await userEvent.type(within(dialog).getByLabelText('End odometer (km)'), '45100')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await within(dialog).findByText('End reading cannot be below the start.')).toBeInTheDocument()
    await userEvent.clear(within(dialog).getByLabelText('End odometer (km)'))
    await userEvent.type(within(dialog).getByLabelText('End odometer (km)'), '45242.5')
    expect(within(dialog).getByText('Distance: 42.5 km')).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(vehicles.addEntry).toHaveBeenCalledWith(7, 'trip', expect.objectContaining({ start_odometer: 45200, end_odometer: 45242.5, from_location: 'College', start_time: null })))

    await userEvent.click(screen.getByRole('button', { name: 'Request correction' }))
    const ask = await screen.findByRole('dialog')
    await userEvent.type(within(ask).getByLabelText(/What is wrong/), 'Wrong end reading')
    await userEvent.click(within(ask).getByRole('button', { name: 'Send request' }))
    await waitFor(() => expect(vehicles.requestCorrection).toHaveBeenCalledWith(7, 'trip', 11, { request_type: 'edit', reason: 'Wrong end reading' }))
  })

  it('service reminders read well in both languages', async () => {
    const n = { type: 'vehicle.service_due', data: { vehicle: 'MH12AB1234', service: 'General service', days: 3, due_date: '2026-06-17' } } as never
    expect(notificationText(n, i18n.t)).toBe('MH12AB1234: General service due in 3 days (17 Jun 2026).')
    await i18n.changeLanguage('mr')
    expect(notificationText(n, i18n.t)).toContain('3 दिवसांत')
  })

  it('maintenance work is logged for a register department', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['maintenance.view', 'maintenance.log'] }))
    maintenance.fetchLocations.mockResolvedValue({
      data: [{ id: 5, name: 'Physics Lab', location_code: 'MB-101', floor: 1, capacity: null, is_residential: false, is_active: true, institution_id: null, building: { id: 1, name: 'Main Building' }, total_logs: 0, recent_issues: 0, last_work_date: null }],
      meta: { can_manage: false, can_log: true, can_export: false, log_departments: [{ id: 2, name: 'Maintenance' }] },
    })
    maintenance.fetchLogs.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null } })
    maintenance.saveLog.mockResolvedValue({} as never)
    renderApp('/maintenance')
    await userEvent.click(await screen.findByRole('button', { name: 'Log work' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.selectOptions(within(dialog).getByLabelText('Location'), '5')
    await userEvent.type(within(dialog).getByLabelText('Item / work'), 'Ceiling fan')
    await userEvent.type(within(dialog).getByLabelText('Cost (₹)'), '1200')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(maintenance.saveLog).toHaveBeenCalledWith(null, expect.objectContaining({ location_id: 5, department_id: 2, item_description: 'Ceiling fan', work_type: 'repaired', cost: 1200 })))
  })
})
