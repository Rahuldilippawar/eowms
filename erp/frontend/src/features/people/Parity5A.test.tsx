import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as settingsApi from '@/features/admin/settingsApi'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as profileApi from './profileApi'
import type { EmployeeProfile } from './profileApi'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('@/features/admin/settingsApi', () => ({ changePassword: vi.fn(), fetchModules: vi.fn(), saveModules: vi.fn(), fetchAllFields: vi.fn(), saveField: vi.fn() }))
vi.mock('./profileApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./profileApi')>()),
  fetchProfile: vi.fn(),
  revealPrivate: vi.fn(),
  previewImport: vi.fn(),
  runImport: vi.fn(),
  fetchPublicEmployee: vi.fn(),
  fetchActiveFields: vi.fn(),
}))

const auth = vi.mocked(authApi)
const settings = vi.mocked(settingsApi)
const profiles = vi.mocked(profileApi)

const profile = (over: Partial<EmployeeProfile> = {}): EmployeeProfile =>
  ({
    id: 3,
    employee_code: 'EMP-003',
    name: 'Physics Teacher',
    designation: 'Assistant Professor',
    phone: null,
    email: null,
    institution: null,
    department: { id: 1, name: 'Physics' },
    additional_departments: [],
    reporting_officer: null,
    user: null,
    joining_date: null,
    is_active: true,
    ...Object.fromEntries(profileApi.PROFILE_FIELDS.map((f) => [f, null])),
    is_disabled: false,
    custom: {},
    has_photo: false,
    documents: [],
    family_members: [],
    achievements: [],
    can: { edit: false, edit_private: false, self: true },
    private: {
      aadhaar_masked: '••••••••9012',
      bank_account_masked: null,
      ration_card: null,
      ifsc_code: null,
      bank_name: null,
      bank_branch: null,
      pf_applicable: false,
      uan_no: null,
      esic_applicable: false,
      esic_no: null,
      pt_applicable: false,
      monthly_salary: null,
    },
    ...over,
  }) as EmployeeProfile

describe('old-app parity: people', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    profiles.fetchActiveFields.mockResolvedValue([])
  })

  it('an account that must change its password sees only the change form', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ must_change_password: true }))
    settings.changePassword.mockResolvedValue(makeUser({ must_change_password: false }))
    renderApp('/')
    expect(await screen.findByRole('heading', { name: 'Change password' })).toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Main' })).not.toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Current password'), 'Temp1234')
    await userEvent.type(screen.getByLabelText('New password'), 'NewPass123')
    await userEvent.type(screen.getByLabelText('Repeat new password'), 'NewPass12')
    await userEvent.click(screen.getByRole('button', { name: 'Save password' }))
    expect(await screen.findByText('The two passwords do not match.')).toBeInTheDocument()
    expect(settings.changePassword).not.toHaveBeenCalled()

    await userEvent.clear(screen.getByLabelText('Repeat new password'))
    await userEvent.type(screen.getByLabelText('Repeat new password'), 'NewPass123')
    await userEvent.click(screen.getByRole('button', { name: 'Save password' }))
    await waitFor(() => expect(settings.changePassword).toHaveBeenCalledWith({ current_password: 'Temp1234', password: 'NewPass123', password_confirmation: 'NewPass123' }))
    expect(await screen.findByRole('navigation', { name: 'Main' })).toBeInTheDocument()
  })

  it('hides switched-off modules from the menu', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ disabled_modules: ['tasks', 'attendance'] }))
    renderApp('/')
    const nav = await screen.findByRole('navigation', { name: 'Main' })
    expect(within(nav).queryByRole('link', { name: 'Tasks' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'My attendance' })).not.toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'My leave' })).toBeInTheDocument()
  })

  it('own profile: contact fields editable, the rest read-only, private numbers masked until revealed', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    profiles.fetchProfile.mockResolvedValue(profile())
    profiles.revealPrivate.mockResolvedValue({ aadhaar_number: '123456789012', bank_account_no: null })
    renderApp('/my/profile')
    expect(await screen.findByRole('heading', { name: 'Physics Teacher' })).toBeInTheDocument()
    expect(screen.getByLabelText('WhatsApp number')).toBeEnabled()
    expect(screen.getByLabelText("Father's name")).toBeDisabled()

    await userEvent.click(screen.getByRole('tab', { name: 'Private' }))
    expect(screen.getByText('••••••••9012')).toBeInTheDocument()
    expect(screen.queryByLabelText('New Aadhaar number')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Show full numbers' }))
    expect(await screen.findByText('123456789012')).toBeInTheDocument()
  })

  it('import shows row problems and does not allow importing until they are fixed', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['employees.view', 'employees.create'] }))
    profiles.previewImport.mockResolvedValue({
      total: 2,
      with_errors: 1,
      rows: [
        { line: 2, data: { employee_code: 'E-2', name: 'Asha' }, errors: {} },
        { line: 3, data: { employee_code: 'E-3', name: 'Ravi' }, errors: { department_code: 'No department with this code.' } },
      ],
    })
    renderApp('/employees/import')
    fireEvent.change(await screen.findByLabelText('CSV file'), { target: { files: [new File(['x'], 'staff.csv', { type: 'text/csv' })] } })
    expect(await screen.findByText(/No department with this code/)).toBeInTheDocument()
    expect(screen.getByText(/2 rows, 1 with problems/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Import 2 rows' })).toBeDisabled()
  })

  it('public ID-card check needs no login', async () => {
    auth.fetchCurrentUser.mockResolvedValue(null)
    profiles.fetchPublicEmployee.mockResolvedValue({ name: 'Physics Teacher', employee_code: 'EMP-003', designation: 'Assistant Professor', department: 'Physics', institution: 'College', organization: 'Trust', is_active: false, has_photo: false })
    renderApp('/verify/employee/01ARZ3NDEKTSV4RRFFQ69G5FAV')
    expect(await screen.findByText('Physics Teacher')).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Not working here any more')
  })
})
