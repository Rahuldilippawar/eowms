import { api } from '@/lib/api'
import type { CustomFieldDef } from '@/features/people/profileApi'

export type FieldEntity = 'employee' | 'student' | 'work_entry'
export type ManagedField = CustomFieldDef & { entity: FieldEntity; sort_order: number; is_active: boolean }

export const fetchAllFields = async (): Promise<ManagedField[]> => (await api.get('/custom-fields')).data.data
export const saveField = async (id: number | null, input: Partial<ManagedField>): Promise<ManagedField> =>
  id ? (await api.put(`/custom-fields/${id}`, input)).data.data : (await api.post('/custom-fields', input)).data.data

export const fetchModules = async (): Promise<{ toggleable: string[]; disabled: string[] }> => (await api.get('/organization/modules')).data.data
export const saveModules = async (disabled: string[]): Promise<{ toggleable: string[]; disabled: string[] }> => (await api.put('/organization/modules', { disabled })).data.data

export const changePassword = async (input: { current_password: string; password: string; password_confirmation: string }) => (await api.post('/auth/change-password', input)).data.data
