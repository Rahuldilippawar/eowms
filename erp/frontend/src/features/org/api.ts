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
