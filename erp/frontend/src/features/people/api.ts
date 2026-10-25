import { api } from '@/lib/api'
import type { Paginated } from '@/lib/pagination'

export interface Ref {
  id: number
  name: string
}

export interface Employee {
  id: number
  employee_code: string
  name: string
  designation: string | null
  phone: string | null
  email: string | null
  institution: Ref | null
  department: Ref
  additional_departments: Ref[]
  reporting_officer: Ref | null
  user: { id: number; email: string } | null
  joining_date: string | null
  is_active: boolean
}

export interface EmployeeInput {
  employee_code: string
  name: string
  designation: string | null
  phone: string | null
  email: string | null
  department_id: number
  additional_department_ids: number[]
  reporting_officer_id: number | null
  user_id: number | null
  joining_date: string | null
}

export interface Student {
  id: number
  student_code: string
  name: string
  institution: Ref
  department: Ref | null
  class_name: string | null
  section: string | null
  gender: 'male' | 'female' | 'other' | null
  date_of_birth: string | null
  admission_date: string | null
  guardian_name: string | null
  guardian_phone: string | null
  is_active: boolean
}

export type StudentInput = Omit<Student, 'id' | 'institution' | 'department' | 'is_active'> & { institution_id: number; department_id: number | null }

type ListParams = { search?: string; page?: number }
const q = (p: ListParams & Record<string, unknown>) => ({ ...p, search: p.search || undefined })

export const fetchEmployees = async (p: ListParams): Promise<Paginated<Employee>> => (await api.get('/employees', { params: q(p) })).data
export const fetchEmployeeOptions = async (): Promise<Employee[]> => (await api.get('/employees', { params: { per_page: 100, is_active: 1 } })).data.data
export const createEmployee = async (i: EmployeeInput): Promise<Employee> => (await api.post('/employees', i)).data.data
export const updateEmployee = async (id: number, i: EmployeeInput): Promise<Employee> => (await api.put(`/employees/${id}`, i)).data.data
export const setEmployeeActive = async (id: number, v: boolean, reason?: string) => (await api.post(`/employees/${id}/active`, { is_active: v, reason: reason || undefined })).data.data

export const fetchStudents = async (p: ListParams): Promise<Paginated<Student>> => (await api.get('/students', { params: q(p) })).data
export const createStudent = async (i: StudentInput): Promise<Student> => (await api.post('/students', i)).data.data
export const updateStudent = async (id: number, i: StudentInput): Promise<Student> => (await api.put(`/students/${id}`, i)).data.data
export const setStudentActive = async (id: number, v: boolean, reason?: string) => (await api.post(`/students/${id}/active`, { is_active: v, reason: reason || undefined })).data.data
