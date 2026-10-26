import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchDepartmentOptions } from '@/features/org/api'
import { actionErrorMessage } from '@/lib/forms'
import { fetchCategories, fetchWorkflows, saveCategory, saveWorkflow, seedCategories, type ApproverType, type Recipient, type Workflow, type WorkflowStepInput } from './api'
import { RecipientPicker } from './LetterBits'

type StepDraft = WorkflowStepInput & { person: Recipient | null }

function Categories() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const key = ['letter-categories', user?.current_organization?.id]
  const q = useQuery({ queryKey: key, queryFn: fetchCategories })
  const [name, setName] = useState('')
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: key })
    void queryClient.invalidateQueries({ queryKey: ['letter-options'] })
  }
  const add = useMutation({
    mutationFn: () => saveCategory(null, { name: name.trim(), is_active: true }),
    onSuccess: () => {
      setName('')
      refresh()
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const seed = useMutation({
    mutationFn: seedCategories,
    onSuccess: (r) => {
      refresh()
      toast.success(t('letters.settings.seeded', { count: r.added }))
    },
  })

  return (
    <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-semibold">{t('letters.settings.categories')}</h2>
        <Button variant="secondary" disabled={seed.isPending} onClick={() => seed.mutate()}>
          {t('letters.settings.addDefaults')}
        </Button>
      </div>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      <ul className="flex flex-wrap gap-2">
        {(q.data ?? []).map((c) => (
          <li key={c.id}>
            <button
              type="button"
              title={c.is_active ? t('letters.settings.clickToHide') : t('letters.settings.clickToShow')}
              onClick={() => void saveCategory(c.id, { name: c.name, is_active: !c.is_active }).then(refresh)}
              className={`rounded-full px-3 py-1 text-sm ring-1 ring-inset ${c.is_active ? 'bg-brand-50 text-brand-800 ring-brand-200' : 'bg-slate-50 text-slate-400 line-through ring-slate-200'}`}
            >
              {c.name}
            </button>
          </li>
        ))}
      </ul>
      <form
        className="flex gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          if (name.trim()) add.mutate()
        }}
      >
        <Input aria-label={t('letters.settings.newCategory')} placeholder={t('letters.settings.newCategory')} value={name} maxLength={150} onChange={(e) => setName(e.target.value)} />
        <Button type="submit" disabled={add.isPending || !name.trim()}>
          {t('letters.settings.add')}
        </Button>
      </form>
    </section>
  )
}

function WorkflowDialog({ workflow, onClose }: { workflow: Workflow | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const orgKey = user?.current_organization?.id
  const departments = useQuery({ queryKey: ['department-options', orgKey], queryFn: fetchDepartmentOptions })
  const categories = useQuery({ queryKey: ['letter-categories', orgKey], queryFn: fetchCategories })
  const [name, setName] = useState(workflow?.name ?? '')
  const [category, setCategory] = useState(workflow?.letter_category_id?.toString() ?? '')
  const [active, setActive] = useState(workflow?.is_active ?? true)
  const [steps, setSteps] = useState<StepDraft[]>(
    workflow?.steps.map((s) => ({ name: s.name, approver_type: s.approver_type, department_id: s.department_id, user_id: s.user_id, person: s.user_id ? { user_id: s.user_id, name: s.user ?? '', designation: null, department: null } : null })) ?? [
      { name: '', approver_type: 'origin_department_head', department_id: null, user_id: null, person: null },
    ],
  )
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const setStep = (i: number, patch: Partial<StepDraft>) => setSteps(steps.map((s, j) => (j === i ? { ...s, ...patch } : s)))
  const swap = (i: number, j: number) => {
    const next = [...steps]
    ;[next[i], next[j]] = [next[j], next[i]]
    setSteps(next)
  }

  const save = useMutation({
    mutationFn: () =>
      saveWorkflow(workflow?.id ?? null, {
        name: name.trim(),
        letter_category_id: category ? Number(category) : null,
        is_active: active,
        steps: steps.map((s) => ({ name: s.name.trim(), approver_type: s.approver_type, department_id: s.department_id, user_id: s.person?.user_id ?? null })),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['letter-workflows'] })
      void queryClient.invalidateQueries({ queryKey: ['letter-options'] })
      toast.success(t('form.saved'))
      onClose()
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['name', 'letter_category_id', 'steps', ...steps.flatMap((_, i) => [`steps.${i}.name`, `steps.${i}.department_id`, `steps.${i}.user_id`])], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={workflow ? t('letters.settings.editWorkflow') : t('letters.settings.newWorkflow')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={() => save.mutate()}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {general ? (
          <p role="alert" className="text-sm text-red-600">
            {general}
          </p>
        ) : null}
        {workflow?.files_on_the_way ? <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{t('letters.settings.inUse', { count: workflow.files_on_the_way })}</p> : null}
        <Field id="wf-name" label={t('org.name')} error={errors.name}>
          <Input id="wf-name" value={name} maxLength={150} onChange={(e) => setName(e.target.value)} />
        </Field>
        <Field id="wf-cat" label={t('letters.settings.suggestFor')}>
          <Select id="wf-cat" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">—</option>
            {(categories.data ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
        </Field>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
          {t('org.active')}
        </label>
        <ol className="space-y-3">
          {steps.map((s, i) => (
            <li key={i} className="space-y-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
              <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-semibold">{t('letters.settings.stepN', { n: i + 1 })}</p>
                <div className="flex gap-1">
                  <Button variant="ghost" className="min-h-8 px-2" aria-label={t('letters.settings.moveUp', { n: i + 1 })} disabled={i === 0} onClick={() => swap(i, i - 1)}>
                    <ArrowUp aria-hidden className="size-4" />
                  </Button>
                  <Button variant="ghost" className="min-h-8 px-2" aria-label={t('letters.settings.moveDown', { n: i + 1 })} disabled={i === steps.length - 1} onClick={() => swap(i, i + 1)}>
                    <ArrowDown aria-hidden className="size-4" />
                  </Button>
                  <Button variant="ghost" className="min-h-8 px-2 text-red-700" aria-label={t('letters.settings.removeStep', { n: i + 1 })} disabled={steps.length === 1} onClick={() => setSteps(steps.filter((_, j) => j !== i))}>
                    <Trash2 aria-hidden className="size-4" />
                  </Button>
                </div>
              </div>
              <Field id={`wf-s${i}-name`} label={t('letters.settings.stepName')} error={errors[`steps.${i}.name`]}>
                <Input id={`wf-s${i}-name`} value={s.name} maxLength={150} onChange={(e) => setStep(i, { name: e.target.value })} />
              </Field>
              <Field id={`wf-s${i}-type`} label={t('letters.settings.approver')}>
                <Select id={`wf-s${i}-type`} value={s.approver_type} onChange={(e) => setStep(i, { approver_type: e.target.value as ApproverType })}>
                  {(['origin_department_head', 'department_head', 'user'] as const).map((k) => (
                    <option key={k} value={k}>
                      {t(`letters.settings.approverTypes.${k}`)}
                    </option>
                  ))}
                </Select>
              </Field>
              {s.approver_type === 'department_head' ? (
                <Field id={`wf-s${i}-dept`} label={t('people.department')} error={errors[`steps.${i}.department_id`]}>
                  <Select id={`wf-s${i}-dept`} value={s.department_id ?? ''} onChange={(e) => setStep(i, { department_id: e.target.value ? Number(e.target.value) : null })}>
                    <option value="">—</option>
                    {(departments.data ?? []).map((d) => (
                      <option key={d.id} value={d.id}>
                        {d.name}
                      </option>
                    ))}
                  </Select>
                </Field>
              ) : null}
              {s.approver_type === 'user' ? <RecipientPicker label={t('letters.settings.person')} value={s.person} onChange={(person) => setStep(i, { person })} error={errors[`steps.${i}.user_id`]} /> : null}
            </li>
          ))}
        </ol>
        {steps.length < 15 ? (
          <Button variant="secondary" onClick={() => setSteps([...steps, { name: '', approver_type: 'department_head', department_id: null, user_id: null, person: null }])}>
            <Plus aria-hidden className="size-4" />
            {t('letters.settings.addStep')}
          </Button>
        ) : null}
      </div>
    </Modal>
  )
}

function Workflows() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['letter-workflows', user?.current_organization?.id], queryFn: fetchWorkflows })
  const [editing, setEditing] = useState<Workflow | null | 'new'>(null)
  return (
    <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-semibold">{t('letters.settings.workflows')}</h2>
        <Button onClick={() => setEditing('new')}>
          <Plus aria-hidden className="size-4" />
          {t('letters.settings.newWorkflow')}
        </Button>
      </div>
      <p className="text-sm text-slate-600 dark:text-slate-400">{t('letters.settings.workflowHint')}</p>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      <ul className="divide-y divide-slate-100 dark:divide-slate-800">
        {(q.data ?? []).map((w) => (
          <li key={w.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p className="font-medium">
                {w.name} {!w.is_active ? <Badge tone="slate">{t('org.inactive')}</Badge> : null}
              </p>
              <ol className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                {w.steps.map((s) => (
                  <li key={s.step_order}>
                    {s.step_order}. {s.name} — {s.approver_type === 'origin_department_head' ? t('letters.settings.approverTypes.origin_department_head') : (s.resolves_to ?? <span className="text-red-700">{t('letters.nobodySet')}</span>)}
                  </li>
                ))}
              </ol>
            </div>
            <Button variant="ghost" className="min-h-8 px-2 py-1 text-xs" aria-label={t('common.editNamed', { name: w.name })} onClick={() => setEditing(w)}>
              {t('common.edit')}
            </Button>
          </li>
        ))}
      </ul>
      {editing ? <WorkflowDialog workflow={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </section>
  )
}

export function LetterSettingsPage() {
  const { t } = useTranslation()
  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <h1 className="text-2xl font-semibold">{t('letters.settingsTitle')}</h1>
      <Categories />
      <Workflows />
    </div>
  )
}
