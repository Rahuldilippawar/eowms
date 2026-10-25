import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

export type InstitutionType = 'college' | 'school' | 'other'

export interface Institution {
  id: number
  organization_id: number
  type: InstitutionType
  name: string
  code: string
  address: string | null
  phone: string | null
  email: string | null
  is_active: boolean
  departments_count?: number
}

export interface Department {
  id: number
  organization_id: number
  institution_id: number | null
  institution: { id: number; name: string; code: string } | null
  is_central: boolean
  name: string
  code: string
  category: string | null
  is_active: boolean
}

export interface ListParams {
  search?: string
  page?: number
}

export async function fetchInstitutions(params: ListParams): Promise<Paginated<Institution>> {
  const { data } = await api.get<Paginated<Institution>>('/institutions', { params: { search: params.search || undefined, page: params.page } })
  return data
}

export async function fetchDepartments(params: ListParams): Promise<Paginated<Department>> {
  const { data } = await api.get<Paginated<Department>>('/departments', { params: { search: params.search || undefined, page: params.page } })
  return data
}

export interface InstitutionInput {
  type: InstitutionType
  name: string
  code: string
  address?: string | null
  phone?: string | null
  email?: string | null
}

export interface DepartmentInput {
  institution_id: number | null
  name: string
  code: string
  category?: string | null
}

export async function createInstitution(input: InstitutionInput): Promise<Institution> {
  return (await api.post<{ data: Institution }>('/institutions', input)).data.data
}

export async function updateInstitution(id: number, input: InstitutionInput): Promise<Institution> {
  return (await api.put<{ data: Institution }>(`/institutions/${id}`, input)).data.data
}

export async function setInstitutionActive(id: number, isActive: boolean, reason?: string): Promise<Institution> {
  return (await api.post<{ data: Institution }>(`/institutions/${id}/active`, { is_active: isActive, reason: reason || undefined })).data.data
}

export async function createDepartment(input: DepartmentInput): Promise<Department> {
  return (await api.post<{ data: Department }>('/departments', input)).data.data
}

export async function updateDepartment(id: number, input: DepartmentInput): Promise<Department> {
  return (await api.put<{ data: Department }>(`/departments/${id}`, input)).data.data
}

export async function setDepartmentActive(id: number, isActive: boolean, reason?: string): Promise<Department> {
  return (await api.post<{ data: Department }>(`/departments/${id}/active`, { is_active: isActive, reason: reason || undefined })).data.data
}

/** Up to 100 institutions/departments the user can see, for pickers. */
export async function fetchInstitutionOptions(): Promise<Institution[]> {
  return (await api.get<Paginated<Institution>>('/institutions', { params: { per_page: 100 } })).data.data
}

export async function fetchDepartmentOptions(): Promise<Department[]> {
  return (await api.get<Paginated<Department>>('/departments', { params: { per_page: 100 } })).data.data
}
