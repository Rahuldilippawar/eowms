import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { addDays, todayYmd } from '@/lib/format'
import { createTask, fetchAssignable, updateTask, type Task, type TaskPriority } from './api'

const FIELDS = ['title', 'description', 'priority', 'due_date', 'assignee_employee_id'] as const

export function TaskDialog({ open, onOpenChange, task }: { open: boolean; onOpenChange: (o: boolean) => void; task?: Task }) {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const assignable = useQuery({ queryKey: ['task-assignable', user?.current_organization?.id], queryFn: fetchAssignable, enabled: open })
  const initial = () => ({
    title: task?.title ?? '',
    description: task?.description ?? '',
    priority: task?.priority ?? ('normal' as TaskPriority),
    due_date: task?.due_date ?? addDays(todayYmd(), 1),
    assignee_employee_id: task?.assignee.id ?? 0,
  })
  const [v, setV] = useState(initial)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  useEffect(() => {
    if (open) {
      setV(initial())
      setErrors({})
      setGeneral(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when the dialog opens
  }, [open, task?.id])

  const save = useMutation({
    mutationFn: () => {
      const input = { ...v, title: v.title.trim(), description: v.description.trim() || null }
      return task ? updateTask(task.id, input) : createTask(input)
    },
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['tasks'] })
      queryClient.setQueryData(['task', saved.id], saved)
      toast.success(task ? t('form.saved') : t('tasks.assignedToast', { name: saved.assignee.name }))
      onOpenChange(false)
      if (!task) navigate(`/tasks/${saved.id}`)
    },
    onError: (e) => {
      const r = splitServerErrors(e, FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.title.trim()) local.title = t('form.required')
    if (!v.assignee_employee_id) local.assignee_employee_id = t('form.required')
    if (!v.due_date) local.due_date = t('form.required')
    else if (!task && v.due_date < todayYmd()) local.due_date = t('tasks.dueInPast')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }

  const options = assignable.data ?? []
  const current = task && !options.some((o) => o.id === task.assignee.id) ? [{ id: task.assignee.id, name: task.assignee.name, employee_code: task.assignee.employee_code, department: null }] : []

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={task ? t('tasks.editTask') : t('tasks.newTask')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={submit}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : task ? t('common.save') : t('tasks.assign')}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <div className="sm:col-span-2">
          <Field id="t-title" label={t('tasks.taskTitle')} error={errors.title}>
            <Input id="t-title" value={v.title} maxLength={255} aria-invalid={!!errors.title || undefined} onChange={(e) => setV({ ...v, title: e.target.value })} />
          </Field>
        </div>
        <div className="sm:col-span-2">
          <Field id="t-assignee" label={t('tasks.assignee')} error={errors.assignee_employee_id}>
            <Select
              id="t-assignee"
              value={v.assignee_employee_id || ''}
              aria-invalid={!!errors.assignee_employee_id || undefined}
              onChange={(e) => setV({ ...v, assignee_employee_id: Number(e.target.value) })}
            >
              <option value="">{t('tasks.chooseAssignee')}</option>
              {[...current, ...options].map((o) => (
                <option key={o.id} value={o.id}>
                  {o.name} ({o.employee_code}){o.department ? ` — ${o.department}` : ''}
                </option>
              ))}
            </Select>
          </Field>
          {assignable.isSuccess && options.length === 0 ? <p className="mt-1 text-sm text-slate-500">{t('tasks.nobodyToAssign')}</p> : null}
        </div>
        <Field id="t-due" label={t('tasks.dueDate')} error={errors.due_date}>
          <Input id="t-due" type="date" value={v.due_date} min={task ? undefined : todayYmd()} aria-invalid={!!errors.due_date || undefined} onChange={(e) => setV({ ...v, due_date: e.target.value })} />
        </Field>
        <Field id="t-priority" label={t('tasks.priorityLabel')} error={errors.priority}>
          <Select id="t-priority" value={v.priority} onChange={(e) => setV({ ...v, priority: e.target.value as TaskPriority })}>
            {(['low', 'normal', 'high', 'urgent'] as const).map((p) => (
              <option key={p} value={p}>
                {t(`tasks.priority.${p}`)}
              </option>
            ))}
          </Select>
        </Field>
        <div className="sm:col-span-2">
          <Field id="t-desc" label={t('work.details')} error={errors.description}>
            <Textarea id="t-desc" rows={4} maxLength={5000} value={v.description} onChange={(e) => setV({ ...v, description: e.target.value })} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}
