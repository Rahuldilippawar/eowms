import { todayYmd } from '@/lib/format'
import type { EntryInput, EntryType } from './api'

export type Values = Record<string, string>

export const FIELDS: Record<EntryType, { key: string; label: string; kind: 'date' | 'text' | 'number' | 'time' | 'textarea'; required?: boolean; wide?: boolean }[]> = {
  trip: [
    { key: 'trip_date', label: 'vehicles.date', kind: 'date', required: true },
    { key: 'purpose', label: 'vehicles.purpose', kind: 'text' },
    { key: 'from_location', label: 'vehicles.from', kind: 'text', required: true },
    { key: 'to_location', label: 'vehicles.to', kind: 'text', required: true },
    { key: 'start_time', label: 'vehicles.startTime', kind: 'time' },
    { key: 'end_time', label: 'vehicles.endTime', kind: 'time' },
    { key: 'start_odometer', label: 'vehicles.startOdometer', kind: 'number', required: true },
    { key: 'end_odometer', label: 'vehicles.endOdometer', kind: 'number', required: true },
  ],
  fuel: [
    { key: 'fill_date', label: 'vehicles.date', kind: 'date', required: true },
    { key: 'odometer_reading', label: 'vehicles.odometerReading', kind: 'number', required: true },
    { key: 'liters', label: 'vehicles.liters', kind: 'number', required: true },
    { key: 'cost', label: 'vehicles.costRs', kind: 'number' },
    { key: 'fuel_station', label: 'vehicles.station', kind: 'text', wide: true },
  ],
  service: [
    { key: 'service_date', label: 'vehicles.date', kind: 'date', required: true },
    { key: 'type', label: 'vehicles.serviceType', kind: 'text', required: true },
    { key: 'cost', label: 'vehicles.costRs', kind: 'number' },
    { key: 'odometer_reading', label: 'vehicles.odometerReading', kind: 'number' },
    { key: 'next_due_date', label: 'vehicles.nextDueDate', kind: 'date' },
    { key: 'next_due_km', label: 'vehicles.nextDueKm', kind: 'number' },
    { key: 'vendor', label: 'vehicles.vendor', kind: 'text', wide: true },
    { key: 'description', label: 'vehicles.details', kind: 'textarea', wide: true },
  ],
}

/** Blank values for a new entry; trips and fills start from the vehicle's odometer. */
export function blankEntry(type: EntryType, odometer: number): Values {
  const v: Values = Object.fromEntries(FIELDS[type].map((f) => [f.key, '']))
  v[FIELDS[type][0].key] = todayYmd()
  if (type === 'trip') v.start_odometer = String(odometer)
  if (type === 'fuel') v.odometer_reading = String(odometer)
  return v
}

/** Values from an entry as the API returns it (detail page) or as stored (correction request). */
export function entryValues(type: EntryType, entry: Record<string, unknown>): Values {
  const pick = (k: string): string => {
    const raw = entry[k] ?? (k === FIELDS[type][0].key ? entry.date : undefined) ?? (k === 'type' ? entry.service_type : undefined)
    if (raw === null || raw === undefined) return ''
    const s = String(raw)
    return k.endsWith('_time') ? s.slice(0, 5) : k.endsWith('_date') ? s.slice(0, 10) : s
  }
  return Object.fromEntries(FIELDS[type].map((f) => [f.key, pick(f.key)]))
}

/** The typed payload (numbers as numbers, blanks as null). */
export function toInput(type: EntryType, v: Values): EntryInput {
  return Object.fromEntries(
    FIELDS[type].map((f) => [f.key, v[f.key].trim() === '' ? null : f.kind === 'number' ? Number(v[f.key]) : v[f.key].trim()]),
  ) as unknown as EntryInput
}
