import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { createLetter, fetchLetter, fetchLetterOptions, updateLetter, type BudgetCategory, type LetterDetail, type LetterPriority } from './api'

const FIELDS = ['subject', 'description', 'reference_no', 'letter_category_id', 'priority', 'approval_workflow_id', 'budget_category', 'requested_amount'] as const

interface Form {
  subject: string
  description: string
  reference_no: string
  letter_category_id: string
  priority: LetterPriority
  approval_workflow_id: string
  is_budget_expense: boolean
  budget_category: BudgetCategory
  requested_amount: string
}

const toForm = (l?: LetterDetail): Form => ({
  subject: l?.subject ?? '',
  description: l?.description ?? '',
  reference_no: l?.reference_no ?? '',
  letter_category_id: l?.letter_category_id?.toString() ?? '',
  priority: l?.priority ?? 'normal',
  approval_workflow_id: l?.approval_workflow_id?.toString() ?? '',
  is_budget_expense: l?.is_budget_expense ?? false,
  budget_category: l?.budget_category ?? 'purchase',
  requested_amount: l?.requested_amount?.toString() ?? '',
})

/** New file, or a draft being edited (only drafts can change). */
export function LetterFormPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const orgKey = user?.current_organization?.id
  const { id } = useParams()
  const letterId = id ? Number(id) : null
  const options = useQuery({ queryKey: ['letter-options', orgKey], queryFn: fetchLetterOptions })
  const existing = useQuery({ queryKey: ['letter', letterId], queryFn: () => fetchLetter(letterId ?? 0), enabled: letterId !== null })
  const [v, setV] = useState<Form>(toForm())
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  useEffect(() => {
    if (existing.data) setV(toForm(existing.data))
  }, [existing.data])

  const save = useMutation({
    mutationFn: () => {
      const input = {
        subject: v.subject.trim(),
        description: v.description.trim() || null,
        reference_no: v.reference_no.trim() || null,
        letter_category_id: v.letter_category_id ? Number(v.letter_category_id) : null,
        priority: v.priority,
        approval_workflow_id: v.approval_workflow_id ? Number(v.approval_workflow_id) : null,
        is_budget_expense: v.is_budget_expense,
        budget_category: v.is_budget_expense ? v.budget_category : null,
        requested_amount: v.is_budget_expense && v.requested_amount ? Number(v.requested_amount) : null,
      }
      return letterId ? updateLetter(letterId, input) : createLetter(input)
    },
    onSuccess: (saved) => {
      queryClient.setQueryData(['letter', saved.id], saved)
      void queryClient.invalidateQueries({ queryKey: ['letters'] })
      toast.success(t('form.saved'))
      navigate(`/letters/${saved.id}`)
    },
    onError: (e) => {
      const r = splitServerErrors(e, FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.subject.trim()) local.subject = t('form.required')
    if (v.is_budget_expense && !(Number(v.requested_amount) > 0)) local.requested_amount = t('letters.amountRule')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }

  if (letterId !== null && existing.isPending) return <LoadingState />
  if (existing.isError || options.isError) return <ErrorState onRetry={() => void (existing.isError ? existing.refetch() : options.refetch())} />
  // Workflows suggested for the chosen category come first.
  const workflows = [...(options.data?.workflows ?? [])].sort((a, b) => Number(b.letter_category_id === Number(v.letter_category_id)) - Number(a.letter_category_id === Number(v.letter_category_id)))

  return (
    <div className="mx-auto max-w-3xl space-y-5">
      <h1 className="text-2xl font-semibold">{letterId ? t('letters.editDraft', { number: existing.data?.file_number }) : t('letters.new')}</h1>
      <form
        noValidate
        onSubmit={(e) => {
          e.preventDefault()
          submit()
        }}
        className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2 dark:border-slate-800 dark:bg-slate-900"
      >
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <div className="sm:col-span-2">
          <Field id="lf-subject" label={t('letters.subject')} error={errors.subject}>
            <Input id="lf-subject" value={v.subject} maxLength={255} aria-invalid={!!errors.subject || undefined} onChange={(e) => setV({ ...v, subject: e.target.value })} />
          </Field>
        </div>
        <Field id="lf-category" label={t('letters.category')} error={errors.letter_category_id}>
          <Select id="lf-category" value={v.letter_category_id} onChange={(e) => setV({ ...v, letter_category_id: e.target.value })}>
            <option value="">—</option>
            {(options.data?.categories ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="lf-priority" label={t('tasks.priorityLabel')} error={errors.priority}>
          <Select id="lf-priority" value={v.priority} onChange={(e) => setV({ ...v, priority: e.target.value as LetterPriority })}>
            {(['low', 'normal', 'high', 'urgent'] as const).map((p) => (
              <option key={p} value={p}>
                {t(`tasks.priority.${p}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="lf-workflow" label={t('letters.workflow')} error={errors.approval_workflow_id}>
          <Select id="lf-workflow" value={v.approval_workflow_id} onChange={(e) => setV({ ...v, approval_workflow_id: e.target.value })}>
            <option value="">{t('letters.noWorkflow')}</option>
            {workflows.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="lf-ref" label={t('letters.referenceNo')} error={errors.reference_no}>
          <Input id="lf-ref" value={v.reference_no} maxLength={100} onChange={(e) => setV({ ...v, reference_no: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="lf-desc" label={t('letters.description')} error={errors.description}>
            <Textarea id="lf-desc" rows={8} value={v.description} maxLength={10000} onChange={(e) => setV({ ...v, description: e.target.value })} />
          </Field>
        </div>
        <label className="flex items-center gap-2 text-sm sm:col-span-2">
          <input type="checkbox" checked={v.is_budget_expense} onChange={(e) => setV({ ...v, is_budget_expense: e.target.checked })} />
          {t('letters.budgetExpense')}
        </label>
        {v.is_budget_expense ? (
          <>
            <Field id="lf-bcat" label={t('letters.budgetCategory')} error={errors.budget_category}>
              <Select id="lf-bcat" value={v.budget_category} onChange={(e) => setV({ ...v, budget_category: e.target.value as BudgetCategory })}>
                {(['purchase', 'maintenance', 'fuel', 'other'] as const).map((c) => (
                  <option key={c} value={c}>
                    {t(`letters.budget.${c}`)}
                  </option>
                ))}
              </Select>
            </Field>
            <Field id="lf-amount" label={t('letters.requestedAmount')} error={errors.requested_amount}>
              <Input id="lf-amount" type="number" inputMode="decimal" min={1} step="0.01" value={v.requested_amount} aria-invalid={!!errors.requested_amount || undefined} onChange={(e) => setV({ ...v, requested_amount: e.target.value })} />
            </Field>
          </>
        ) : null}
        <div className="flex gap-2 sm:col-span-2">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('letters.saveDraft')}
          </Button>
          <Button type="button" variant="secondary" onClick={() => navigate(letterId ? `/letters/${letterId}` : '/letters')}>
            {t('common.cancel')}
          </Button>
        </div>
        <p className="text-xs text-slate-500 sm:col-span-2">{t('letters.draftHint')}</p>
      </form>
    </div>
  )
}
