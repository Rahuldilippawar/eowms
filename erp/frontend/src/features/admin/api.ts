import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

export type Scope = 'organization' | 'institutions' | 'departments' | 'own' | 'assigned'
export const SCOPES: Scope[] = ['organization', 'institutions', 'departments', 'own', 'assigned']
const BREADTH: Record<Scope, number> = { organization: 4, institutions: 3, departments: 2, own: 1, assigned: 1 }
export const scopeBreadth = (s: Scope) => BREADTH[s]

export interface Organization {
  id: number
  name: string
  short_name: string | null
  code: string
  address: string | null
  phone: string | null
  email: string | null
  website: string | null
  financial_year_start_month: number
  is_active: boolean
}

export type OrganizationInput = Omit<Organization, 'id' | 'is_active'>

export async function fetchOrganizations(params: { search?: string; page?: number }): Promise<Paginated<Organization>> {
  return (await api.get<Paginated<Organization>>('/organizations', { params: { search: params.search || undefined, page: params.page } })).data
}
export async function fetchOrganization(id: number): Promise<Organization> {
  return (await api.get<{ data: Organization }>(`/organizations/${id}`)).data.data
}
export async function createOrganization(input: OrganizationInput): Promise<Organization> {
  return (await api.post<{ data: Organization }>('/organizations', input)).data.data
}
export async function updateOrganization(id: number, input: OrganizationInput): Promise<Organization> {
  return (await api.put<{ data: Organization }>(`/organizations/${id}`, input)).data.data
}
export async function setOrganizationActive(id: number, isActive: boolean, reason?: string): Promise<Organization> {
  return (await api.post<{ data: Organization }>(`/organizations/${id}/active`, { is_active: isActive, reason: reason || undefined })).data.data
}

export interface RoleGrant {
  key: string
  scope: Scope
}

export interface Role {
  id: number
  key: string
  name: string
  description: string | null
  is_system: boolean
  assignments_count?: number
  permissions?: RoleGrant[]
}

export interface PermissionModule {
  module: string
  permissions: { key: string; action: string; my_scope: Scope | null }[]
}

export async function fetchRoles(): Promise<Role[]> {
  return (await api.get<{ data: Role[] }>('/roles')).data.data
}
export async function fetchRole(id: number): Promise<Role> {
  return (await api.get<{ data: Role }>(`/roles/${id}`)).data.data
}
export async function fetchPermissionCatalog(): Promise<PermissionModule[]> {
  return (await api.get<{ data: PermissionModule[] }>('/permissions')).data.data
}
export interface RoleInput {
  name: string
  description: string | null
  permissions: RoleGrant[]
}
export async function createRole(input: RoleInput): Promise<Role> {
  return (await api.post<{ data: Role }>('/roles', input)).data.data
}
export async function updateRole(id: number, input: RoleInput): Promise<Role> {
  return (await api.put<{ data: Role }>(`/roles/${id}`, input)).data.data
}
export async function deleteRole(id: number): Promise<void> {
  await api.delete(`/roles/${id}`)
}

export interface RoleAssignment {
  id: number
  role: { id: number; name: string; key: string }
  institutions: { id: number; name: string }[]
  departments: { id: number; name: string }[]
}

export interface ManagedUser {
  id: number
  name: string
  email: string
  is_active: boolean
  is_super_admin: boolean
  last_login_at: string | null
  role_assignments: RoleAssignment[]
}

export interface AssignmentInput {
  role_id: number
  institution_ids: number[]
  department_ids: number[]
}

export async function fetchUsers(params: { search?: string; page?: number }): Promise<Paginated<ManagedUser>> {
  return (await api.get<Paginated<ManagedUser>>('/users', { params: { search: params.search || undefined, page: params.page } })).data
}
export async function fetchUser(id: number): Promise<ManagedUser> {
  return (await api.get<{ data: ManagedUser }>(`/users/${id}`)).data.data
}
export async function createUser(input: { name: string; email: string } & AssignmentInput): Promise<ManagedUser> {
  return (await api.post<{ data: ManagedUser }>('/users', input)).data.data
}
export async function updateUser(id: number, input: { name: string; email: string }): Promise<ManagedUser> {
  return (await api.put<{ data: ManagedUser }>(`/users/${id}`, input)).data.data
}
export async function setUserActive(id: number, isActive: boolean, reason?: string): Promise<ManagedUser> {
  return (await api.post<{ data: ManagedUser }>(`/users/${id}/active`, { is_active: isActive, reason: reason || undefined })).data.data
}
export async function sendUserPasswordReset(id: number): Promise<void> {
  await api.post(`/users/${id}/password-reset`)
}
export async function addAssignment(userId: number, input: AssignmentInput): Promise<ManagedUser> {
  return (await api.post<{ data: ManagedUser }>(`/users/${userId}/role-assignments`, input)).data.data
}
export async function removeAssignment(assignmentId: number): Promise<void> {
  await api.delete(`/role-assignments/${assignmentId}`)
}
