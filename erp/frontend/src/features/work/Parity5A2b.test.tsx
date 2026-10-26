import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '@/features/attendance/api'
import * as authApi from '@/features/auth/api'
import * as orgApi from '@/features/org/api'
import * as peopleApi from '@/features/people/api'
import * as tasksApi from '@/features/tasks/api'
import type { Task } from '@/features/tasks/api'
import { setLanguage } from '@/i18n'
import { todayYmd } from '@/lib/format'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as workApi from './api'
import type { WorkEntry } from './api'

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
  fetchMyWork: vi.fn(),
  fetchMyOpenTasks: vi.fn(),
  saveWork: vi.fn(),
  searchColleagues: vi.fn(),
  previewWorkImport: vi.fn(),
  runWorkImport: vi.fn(),
}))
vi.mock('@/features/tasks/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/tasks/api')>()),
  fetchTask: vi.fn(),
  taskTransition: vi.fn(),
}))
vi.mock('@/features/people/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/people/api')>()),
  fetchStudents: vi.fn(),
  createStudent: vi.fn(),
}))
vi.mock('@/features/org/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/org/api')>()),
  fetchInstitutionOptions: vi.fn(),
  fetchDepartmentOptions: vi.fn(),
}))
vi.mock('@/features/attendance/api', () => ({ fetchMyEmployee: vi.fn() }))

const auth = vi.mocked(authApi)
const work = vi.mocked(workApi)
const tasks = vi.mocked(tasksApi)
const people = vi.mocked(peopleApi)
const org = vi.mocked(orgApi)
const att = vi.mocked(attendanceApi)
const meta = { current_page: 1, last_page: 1, per_page: 25, total: 0, from: 0, to: 0 }

const entry = (over: Partial<WorkEntry> = {}): WorkEntry => ({
  id: 9,
  work_date: todayYmd(),
  title: 'Pipe repair',
  description: null,
  hours: null,
  status: 'completed',
  task: null,
  reviewed_at: null,
  reviewer: null,
  review_remarks: null,
  requested_by: "Principal's office",
  estimated_cost: 400,
  actual_cost: 500,
  team: [{ id: 4, name: 'Suresh Patil', employee_code: 'EMP-004' }],
  has_before_photo: true,
  has_after_photo: false,
  can_edit: true,
  ...over,
})

describe('students and daily work (old-app parity)', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
    att.fetchMyEmployee.mockResolvedValue({ id: 3, name: 'Physics Teacher', employee_code: 'EMP-003', department: { id: 1, name: 'Physics' }, is_active: true })
  })

  it('logs work with a team, who asked for it and the costs', async () => {
    work.fetchMyWork.mockResolvedValue({ data: [], meta: { edit_days: 3, today: todayYmd() } })
    work.fetchMyOpenTasks.mockResolvedValue([])
    work.searchColleagues.mockResolvedValue([{ id: 4, name: 'Suresh Patil', employee_code: 'EMP-004', department: 'Maintenance' }])
    work.saveWork.mockResolvedValue(entry())
    renderApp('/my/work')

    await userEvent.type(await screen.findByLabelText('What did you do?'), 'Pipe repair')
    await userEvent.type(screen.getByLabelText('Requested by'), "Principal's office")
    await userEvent.type(screen.getByLabelText('Actual cost (₹)'), '-5')
    await userEvent.type(screen.getByLabelText('Done together with'), 'Sur')
    await userEvent.click(await screen.findByRole('button', { name: /Add Suresh Patil/ }))
    expect(within(screen.getByRole('list', { name: 'Done together with' })).getByText('Suresh Patil')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Enter an amount of 0 or more.')).toBeInTheDocument()
    expect(work.saveWork).not.toHaveBeenCalled()

    await userEvent.clear(screen.getByLabelText('Actual cost (₹)'))
    await userEvent.type(screen.getByLabelText('Actual cost (₹)'), '500')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() =>
      expect(work.saveWork).toHaveBeenCalledWith(null, expect.objectContaining({ requested_by: "Principal's office", estimated_cost: null, actual_cost: 500, team_employee_ids: [4] })),
    )
  })

  it('shows the team, costs and photos of an entry, with photo controls while it can be edited', async () => {
    work.fetchMyWork.mockResolvedValue({ data: [entry()], meta: { edit_days: 3, today: todayYmd() } })
    work.fetchMyOpenTasks.mockResolvedValue([])
    renderApp('/my/work')
    expect(await screen.findByText(/Requested by Principal's office · With Suresh Patil/)).toBeInTheDocument()
    expect(screen.getByText(/Spent ₹500/)).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Before photo: Pipe repair' })).toHaveAttribute('src', expect.stringContaining('/api/v1/work/9/photos/before'))
    expect(screen.getByText('After: add photo')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Before: remove photo' })).toBeInTheDocument()
  })

  it('records the money spent when the task is handed in', async () => {
    const t: Task = {
      id: 7, code: 'TSK-00007', title: 'Fix projector', description: null, priority: 'high', due_date: '2026-05-06', status: 'in_progress', is_overdue: false,
      assignee: { id: 3, name: 'Physics Teacher', employee_code: 'EMP-003' }, department: null, assigned_by: { id: 2, name: 'HOD Physics' },
      submitted_at: null, completed_at: null, cancelled_at: null, created_at: '', actions: ['submit', 'comment'], updates: [],
    }
    tasks.fetchTask.mockResolvedValue(t)
    tasks.taskTransition.mockResolvedValue({ ...t, status: 'submitted', cost_incurred: 250, actions: ['comment'] })
    renderApp('/tasks/7')
    await userEvent.click(await screen.findByRole('button', { name: 'Mark done' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Money spent (₹)'), '250')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Mark done' }))
    await waitFor(() => expect(tasks.taskTransition).toHaveBeenCalledWith(7, 'submit', '', 250))
    expect(await screen.findByText('₹250')).toBeInTheDocument()
  })

  it('student form checks the academic year and sends the new fields', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['students.view', 'students.create'] }))
    people.fetchStudents.mockResolvedValue({ data: [], meta })
    org.fetchInstitutionOptions.mockResolvedValue([{ id: 1, name: 'College', code: 'COL' }] as never)
    org.fetchDepartmentOptions.mockResolvedValue([])
    people.createStudent.mockResolvedValue({} as never)
    renderApp('/students')
    expect(await screen.findByRole('link', { name: 'Import students' })).toHaveAttribute('href', '/students/import')
    await userEvent.click(screen.getByRole('button', { name: 'New student' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Name'), 'Asha')
    await userEvent.type(within(dialog).getByLabelText('Student code'), 'S-1')
    await within(dialog).findByRole('option', { name: 'College' })
    await userEvent.selectOptions(within(dialog).getByLabelText('Institution'), '1')
    await userEvent.type(within(dialog).getByLabelText('Roll no.'), '12')
    await userEvent.selectOptions(within(dialog).getByLabelText('Year of study'), '2')
    await userEvent.type(within(dialog).getByLabelText('Academic year'), '2026')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await within(dialog).findByText('Write it like 2026-27.')).toBeInTheDocument()

    await userEvent.type(within(dialog).getByLabelText('Academic year'), '-27')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(people.createStudent).toHaveBeenCalledWith(expect.objectContaining({ roll_no: '12', year_of_study: 2, academic_year: '2026-27', mobile: null })))
  })

  it('old work records import lists row problems first', async () => {
    auth.fetchCurrentUser.mockResolvedValue(makeUser({ permissions: ['daily_work.view', 'daily_work.import'] }))
    work.previewWorkImport.mockResolvedValue({
      rows: [
        { line: 2, data: { work_date: '2025-01-15', title: 'Cleaning' }, errors: {} },
        { line: 3, data: { work_date: null, title: 'Repair' }, errors: { employee_code: 'No employee with this code that you may import work for.' } },
      ],
    })
    renderApp('/work/import')
    expect(await screen.findByRole('heading', { name: 'Import old work records' })).toBeInTheDocument()
    const file = new File(['date,employee_code,title\n'], 'w.csv', { type: 'text/csv' })
    await userEvent.upload(screen.getByLabelText('CSV file'), file)
    expect(await screen.findByText('No employee with this code that you may import work for.', { exact: false })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Import 2 rows' })).toBeDisabled()
  })
})
