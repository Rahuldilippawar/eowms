import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as orgApi from './api'

vi.mock('@/features/auth/api', () => ({
  fetchCurrentUser: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  switchOrganization: vi.fn(),
  requestPasswordReset: vi.fn(),
  resetPassword: vi.fn(),
}))
vi.mock('./api', () => ({
  fetchInstitutions: vi.fn(),
  fetchDepartments: vi.fn(),
  setInstitutionActive: vi.fn(),
  setDepartmentActive: vi.fn(),
  createInstitution: vi.fn(),
  updateInstitution: vi.fn(),
  createDepartment: vi.fn(),
  updateDepartment: vi.fn(),
  fetchInstitutionOptions: vi.fn(),
  fetchDepartmentOptions: vi.fn(),
}))

const auth = vi.mocked(authApi)
const org = vi.mocked(orgApi)
const meta = { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 }
const college = { id: 5, organization_id: 10, type: 'college' as const, name: 'Arts College', code: 'AC', address: null, phone: null, email: null, is_active: true, departments_count: 2 }

function validation(errors: Record<string, string[]>) {
  return new AxiosError('422', '422', undefined, undefined, {
    status: 422,
    statusText: '',
    data: { message: 'invalid', errors },
    headers: {},
    config: { headers: new AxiosHeaders() },
  })
}

describe('institution management', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    org.fetchInstitutions.mockResolvedValue({ data: [college], meta })
  })

  it('hides create/edit controls from view-only users', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['institutions.view'] }))
    renderApp('/institutions')
    await screen.findByRole('table')
    expect(screen.queryByRole('button', { name: 'New institution' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit Arts College' })).not.toBeInTheDocument()
  })

  it('creates an institution and shows server validation on the field', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['institutions.view', 'institutions.create'] }))
    org.createInstitution.mockRejectedValueOnce(validation({ code: ['The code has already been taken.'] })).mockResolvedValueOnce(college)
    renderApp('/institutions')

    await userEvent.click(await screen.findByRole('button', { name: 'New institution' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Name'), 'Science College')
    await userEvent.type(within(dialog).getByLabelText('Code'), 'AC')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(await within(dialog).findByText('The code has already been taken.')).toBeInTheDocument()

    await userEvent.clear(within(dialog).getByLabelText('Code'))
    await userEvent.type(within(dialog).getByLabelText('Code'), 'SC')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(org.createInstitution.mock.calls[1][0]).toMatchObject({ name: 'Science College', code: 'SC', type: 'college' })
    expect(await screen.findByText('Saved')).toBeInTheDocument()
  })

  it('validates required fields before calling the API', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['institutions.view', 'institutions.create'] }))
    renderApp('/institutions')
    await userEvent.click(await screen.findByRole('button', { name: 'New institution' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Code'), 'bad code!')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await within(dialog).findByText('This is required')).toBeInTheDocument()
    expect(within(dialog).getByText('Letters, numbers, - and _ only')).toBeInTheDocument()
    expect(org.createInstitution).not.toHaveBeenCalled()
  })

  it('deactivates after confirmation, sending the reason', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['institutions.view', 'institutions.activate'] }))
    org.setInstitutionActive.mockResolvedValue({ ...college, is_active: false })
    renderApp('/institutions')

    await userEvent.click(await within(await screen.findByRole('table')).findByRole('button', { name: 'Deactivate' }))
    const dialog = await screen.findByRole('dialog', { name: 'Deactivate Arts College?' })
    await userEvent.type(within(dialog).getByLabelText(/Reason/), 'Merged into another')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Deactivate' }))

    await waitFor(() => expect(org.setInstitutionActive).toHaveBeenCalledWith(5, false, 'Merged into another'))
  })

  it('keeps the dialog open and shows the server message when deactivation is refused', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['institutions.view', 'institutions.activate'] }))
    org.setInstitutionActive.mockRejectedValue(validation({ institution: ['Not allowed for this institution.'] }))
    renderApp('/institutions')

    await userEvent.click(await within(await screen.findByRole('table')).findByRole('button', { name: 'Deactivate' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Deactivate' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Not allowed for this institution.')
  })
})
