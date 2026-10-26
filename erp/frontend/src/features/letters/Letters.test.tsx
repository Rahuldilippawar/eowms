import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '@/features/auth/api'
import { setLanguage } from '@/i18n'
import { makeUser } from '@/test/fixtures'
import { renderApp } from '@/test/renderApp'
import * as lettersApi from './api'
import type { LetterDetail } from './api'

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
  fetchLetters: vi.fn(),
  fetchLetter: vi.fn(),
  fetchLetterOptions: vi.fn(),
  searchRecipients: vi.fn(),
  createLetter: vi.fn(),
  sendLetter: vi.fn(),
  signLetter: vi.fn(),
  fetchPublicSignature: vi.fn(),
}))

const auth = vi.mocked(authApi)
const letters = vi.mocked(lettersApi)
const meta = { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1, with_me: 1 }

const letter = (over: Partial<LetterDetail> = {}): LetterDetail => ({
  id: 5,
  file_number: 'FILE-26-27-0001',
  outward_no: 'PHY/26-27/0001',
  reference_no: null,
  subject: 'New microscopes',
  category: null,
  priority: 'high',
  status: 'in_progress',
  department: { id: 1, name: 'Physics', code: 'PHY' },
  creator: { id: 3, name: 'Physics Teacher' },
  holder: { id: 1, name: 'Dev Admin' },
  holder_since: '2026-06-10T11:00:00+05:30',
  holder_seen_at: null,
  workflow: { id: 2, name: 'Head → Principal' },
  current_step_order: 1,
  is_budget_expense: true,
  budget_category: 'purchase',
  requested_amount: 50000,
  approved_amount: null,
  dispatch_mode: null,
  dispatch_tracking_no: null,
  dispatch_date: null,
  completed_at: null,
  created_at: '2026-06-10T10:00:00+05:30',
  description: 'Two for the lab',
  letter_category_id: null,
  approval_workflow_id: 2,
  actions: ['forward', 'return', 'remark', 'attach', 'dispatch'],
  steps: [
    { step_order: 1, name: 'Head', approver: { id: 1, name: 'Dev Admin' } },
    { step_order: 2, name: 'Principal', approver: { id: 9, name: 'Principal (SAC)' } },
  ],
  movements: [{ id: 1, type: 'submitted', from: { id: 3, name: 'Physics Teacher' }, to: { id: 1, name: 'Dev Admin' }, step_order: 1, remark: 'Please approve', created_at: '2026-06-10T11:00:00+05:30' }],
  attachments: [],
  inward_numbers: [],
  signatures: [],
  ...over,
})

describe('correspondence', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setLanguage('en')
    auth.fetchCurrentUser.mockResolvedValue(makeUser())
  })

  it('lists the files waiting with me, with the count on the tab', async () => {
    letters.fetchLetters.mockResolvedValue({ data: [letter()], meta })
    renderApp('/letters')
    expect(await screen.findByText('New microscopes')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /With me/ })).toHaveTextContent('1')
    await userEvent.click(screen.getByRole('tab', { name: 'Created by me' }))
    await waitFor(() => expect(letters.fetchLetters).toHaveBeenLastCalledWith(expect.objectContaining({ box: 'created' })))
  })

  it('forwarding along a workflow names the next step; only the server’s actions are offered', async () => {
    letters.fetchLetter.mockResolvedValue(letter())
    letters.sendLetter.mockResolvedValue(letter({ holder: { id: 9, name: 'Principal (SAC)' }, current_step_order: 2, actions: [] }))
    renderApp('/letters/5')
    const group = await screen.findByRole('group', { name: 'Actions' })
    expect(within(group).queryByRole('button', { name: 'Sign and close' })).not.toBeInTheDocument()
    expect(screen.getByText('sent it to Dev Admin', { exact: false })).toBeInTheDocument()

    await userEvent.click(within(group).getByRole('button', { name: 'Send on' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('Next step: Principal → Principal (SAC)')).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send on' }))
    await waitFor(() => expect(letters.sendLetter).toHaveBeenCalledWith(5, 'forward', null, ''))
  })

  it('without a workflow the sender must pick a person', async () => {
    letters.fetchLetter.mockResolvedValue(letter({ status: 'draft', holder: null, workflow: null, approval_workflow_id: null, steps: [], actions: ['edit', 'submit'] }))
    letters.searchRecipients.mockResolvedValue([{ user_id: 7, name: 'HOD Physics', designation: 'Head of Department', department: 'Physics' }])
    letters.sendLetter.mockResolvedValue(letter())
    renderApp('/letters/5')
    await userEvent.click(await screen.findByRole('button', { name: 'Send' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send' }))
    expect(await within(dialog).findByText('Choose who gets the file.')).toBeInTheDocument()
    await userEvent.type(within(dialog).getByLabelText('Send to'), 'HOD')
    await userEvent.click(await within(dialog).findByRole('button', { name: /Choose HOD Physics/ }))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(letters.sendLetter).toHaveBeenCalledWith(5, 'submit', 7, ''))
  })

  it('signing asks for the password and the approved amount', async () => {
    letters.fetchLetter.mockResolvedValue(letter({ current_step_order: 2, actions: ['return', 'sign', 'remark'] }))
    letters.signLetter.mockResolvedValue(letter({ status: 'completed', holder: null, actions: [] }))
    renderApp('/letters/5')
    await userEvent.click(await screen.findByRole('button', { name: 'Sign and close' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText('Approved amount (₹)')).toHaveValue(50000)
    await userEvent.click(within(dialog).getByRole('button', { name: 'Sign and close' }))
    expect(await within(dialog).findByText('Enter your password to sign.')).toBeInTheDocument()
    await userEvent.clear(within(dialog).getByLabelText('Approved amount (₹)'))
    await userEvent.type(within(dialog).getByLabelText('Approved amount (₹)'), '45000')
    await userEvent.type(within(dialog).getByLabelText('Password'), 'secret-pass')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Sign and close' }))
    await waitFor(() => expect(letters.signLetter).toHaveBeenCalledWith(5, { password: 'secret-pass', remark: null, approved_amount: 45000 }))
  })

  it('a new file needs a subject, and an amount when it is a budget expense', async () => {
    letters.fetchLetterOptions.mockResolvedValue({ categories: [{ id: 1, name: 'खरेदी मागणी' }], workflows: [{ id: 2, name: 'Head → Principal', letter_category_id: 1 }] })
    letters.createLetter.mockResolvedValue(letter({ status: 'draft' }))
    letters.fetchLetter.mockResolvedValue(letter({ status: 'draft' }))
    renderApp('/letters/new')
    await userEvent.click(await screen.findByRole('button', { name: 'Save draft' }))
    expect(await screen.findByText('This is required')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Subject'), 'New microscopes')
    await userEvent.click(screen.getByLabelText('Budget expense'))
    await userEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    expect(await screen.findByText('Enter an amount above 0.')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Amount asked (₹)'), '50000')
    await userEvent.selectOptions(screen.getByLabelText('Approval workflow'), '2')
    await userEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    await waitFor(() =>
      expect(letters.createLetter).toHaveBeenCalledWith(expect.objectContaining({ subject: 'New microscopes', approval_workflow_id: 2, is_budget_expense: true, budget_category: 'purchase', requested_amount: 50000 })),
    )
  })

  it('the public signature page warns when the file changed after signing', async () => {
    letters.fetchPublicSignature.mockResolvedValue({
      signer_name: 'Principal (SAC)', signer_designation: 'Principal', organization: 'Shivaji Education Trust', file_number: 'FILE-26-27-0001',
      outward_no: 'PHY/26-27/0001', signed_at: '2026-06-12T10:00:00+05:30', method: 'password', intact: false,
    })
    renderApp('/verify/signature/ABCDEF123456')
    expect(await screen.findByText('Warning: the file changed after it was signed')).toBeInTheDocument()
    expect(screen.getByText('Principal (SAC), Principal')).toBeInTheDocument()
    expect(screen.queryByText('New microscopes')).not.toBeInTheDocument()
  })
})
