import { api } from '@/lib/api'
import type { Employee } from './api'

export interface CustomFieldDef {
  id: number
  key: string
  label: string
  type: 'text' | 'textarea' | 'number' | 'date' | 'select' | 'checkbox'
  options: string[] | null
  is_required: boolean
}

export type CustomValues = Record<string, string | number | boolean | null>

export const PROFILE_FIELDS = [
  'secondary_designation',
  'employment_type',
  'staff_group',
  'teaching_mode',
  'father_name',
  'mother_name',
  'gender',
  'date_of_birth',
  'marital_status',
  'blood_group',
  'whatsapp',
  'local_address',
  'permanent_address',
  'religion',
  'caste',
  'caste_validity_no',
  'is_disabled',
  'emergency_contact_name',
  'emergency_contact_relation',
  'emergency_contact_phone',
  'left_date',
] as const
export type ProfileField = (typeof PROFILE_FIELDS)[number]
export const SELF_FIELDS: ProfileField[] = ['whatsapp', 'local_address', 'permanent_address', 'blood_group', 'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone']

export interface PrivateData {
  aadhaar_masked: string | null
  bank_account_masked: string | null
  ration_card: string | null
  ifsc_code: string | null
  bank_name: string | null
  bank_branch: string | null
  pf_applicable: boolean | null
  uan_no: string | null
  esic_applicable: boolean | null
  esic_no: string | null
  pt_applicable: boolean | null
  monthly_salary: number | null
}

export type EmployeeProfile = Employee &
  Record<Exclude<ProfileField, 'is_disabled'>, string | null> & {
    is_disabled: boolean
    custom: CustomValues
    has_photo: boolean
    public_token?: string
    private?: PrivateData
    documents: { id: number; document_type: string; title: string | null; original_name: string; size_bytes: number; uploaded_by: string | null; created_at: string }[]
    family_members: { id: number; name: string; relation: string; date_of_birth: string | null; phone: string | null; lives_on_campus: boolean }[]
    achievements: { id: number; title: string; description: string | null; achieved_on: string | null; added_by: string | null }[]
    can: { edit: boolean; edit_private: boolean; self: boolean }
  }

export const DOCUMENT_TYPES = ['aadhaar', 'pan', 'photo_id', 'education', 'experience', 'appointment', 'medical', 'bank', 'other'] as const

const base = (id: number | 'me') => (id === 'me' ? '/me/profile' : `/employees/${id}/profile`)
const target = (p: EmployeeProfile) => `/employees/${p.id}`

export const fetchProfile = async (id: number | 'me'): Promise<EmployeeProfile> => (await api.get(base(id))).data.data
export const updateProfile = async (id: number | 'me', input: Record<string, unknown>): Promise<EmployeeProfile> => (await api.put(base(id), input)).data.data
export const updatePrivate = async (p: EmployeeProfile, input: Record<string, unknown>): Promise<EmployeeProfile> => (await api.put(`${target(p)}/private`, input)).data.data
export const revealPrivate = async (p: EmployeeProfile): Promise<{ aadhaar_number: string | null; bank_account_no: string | null }> =>
  (await api.post(`${target(p)}/private/reveal`)).data.data
export const photoUrl = (id: number, version = 0) => `/api/v1/employees/${id}/photo?v=${version}`
export async function uploadPhoto(p: EmployeeProfile, file: File): Promise<EmployeeProfile> {
  const form = new FormData()
  form.append('photo', file)
  return (await api.post(`${target(p)}/photo`, form)).data.data
}
export async function uploadDocument(p: EmployeeProfile, input: { document_type: string; title: string; file: File }): Promise<EmployeeProfile> {
  const form = new FormData()
  form.append('document_type', input.document_type)
  if (input.title) form.append('title', input.title)
  form.append('file', input.file)
  return (await api.post(`${target(p)}/documents`, form)).data.data
}
export const documentUrl = (id: number) => `/api/v1/employee-documents/${id}`
export const deleteDocument = async (id: number): Promise<EmployeeProfile> => (await api.delete(`/employee-documents/${id}`)).data.data
export const saveFamily = async (p: EmployeeProfile, memberId: number | null, input: Record<string, unknown>): Promise<EmployeeProfile> =>
  memberId ? (await api.put(`${target(p)}/family/${memberId}`, input)).data.data : (await api.post(`${target(p)}/family`, input)).data.data
export const deleteFamily = async (p: EmployeeProfile, memberId: number): Promise<EmployeeProfile> => (await api.delete(`${target(p)}/family/${memberId}`)).data.data
export const addAchievement = async (p: EmployeeProfile, input: Record<string, unknown>): Promise<EmployeeProfile> => (await api.post(`${target(p)}/achievements`, input)).data.data
export const deleteAchievement = async (p: EmployeeProfile, id: number): Promise<EmployeeProfile> => (await api.delete(`${target(p)}/achievements/${id}`)).data.data

export const fetchActiveFields = async (entity: 'employee' | 'student' | 'work_entry'): Promise<CustomFieldDef[]> => (await api.get('/custom-fields/active', { params: { entity } })).data.data

export interface ImportRow {
  line: number
  data: Record<string, string | number | null>
  errors: Record<string, string>
}
export const csvForm = (file: File) => {
  const form = new FormData()
  form.append('file', file)
  return form
}
export const importTemplateUrl = '/api/v1/employees/import/template'
export const previewImport = async (file: File): Promise<{ rows: ImportRow[]; total: number; with_errors: number }> => (await api.post('/employees/import/preview', csvForm(file))).data.data
export const runImport = async (file: File): Promise<{ imported: number }> => (await api.post('/employees/import', csvForm(file))).data.data

export interface IdCard {
  id: number
  name: string
  employee_code: string
  designation: string | null
  department: string | null
  institution: string
  blood_group: string | null
  phone: string | null
  emergency_contact_phone: string | null
  has_photo: boolean
  public_token: string
}
export const fetchIdCards = async (p: { ids?: number[]; department_id?: number }): Promise<{ data: IdCard[]; organization: { name: string; short_name: string | null } }> =>
  (await api.post('/employees/id-cards', p)).data
export const bulkMove = async (input: { employee_ids: number[]; department_id: number; reason: string }): Promise<{ moved: number }> =>
  (await api.post('/employees/bulk-move', { ...input, reason: input.reason || undefined })).data.data

export interface PublicEmployee {
  name: string
  employee_code: string
  designation: string | null
  department: string | null
  institution: string | null
  organization: string
  is_active: boolean
  has_photo: boolean
}
export const fetchPublicEmployee = async (token: string): Promise<PublicEmployee> => (await api.get(`/public/employees/${token}`)).data.data
export const publicPhotoUrl = (token: string) => `/api/v1/public/employees/${token}/photo`
