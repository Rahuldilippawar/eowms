import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { applyServerErrors } from '@/lib/forms'
import { createDepartment, fetchInstitutionOptions, updateDepartment, type Department } from '../api'

const schema = z.object({
  institution_id: z.string(),
  name: z.string().trim().min(1, 'form.required').max(255),
  code: z.string().trim().min(1, 'form.required').max(30).regex(/^[A-Za-z0-9_-]+$/, 'form.codeFormat'),
  category: z.string().max(50).optional(),
})
type Values = z.infer<typeof schema>
const FIELDS = ['institution_id', 'name', 'code', 'category'] as const

export function DepartmentDialog({ open, onOpenChange, department }: { open: boolean; onOpenChange: (o: boolean) => void; department?: Department }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { user, can } = useAuth()
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState<string | null>(null)
  const institutions = useQuery({
    queryKey: ['institution-options', user?.current_organization?.id],
    queryFn: fetchInstitutionOptions,
    enabled: open && can('institutions.view'),
  })
  const { register, handleSubmit, reset, setError, formState } = useForm<Values>({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (open) {
      setFormError(null)
      reset({
        institution_id: department?.institution_id ? String(department.institution_id) : '',
        name: department?.name ?? '',
        code: department?.code ?? '',
        category: department?.category ?? '',
      })
    }
  }, [open, department, reset])

  const save = useMutation({
    mutationFn: (v: Values) => {
      const input = { name: v.name, code: v.code, category: v.category || null, institution_id: v.institution_id ? Number(v.institution_id) : null }
      return department ? updateDepartment(department.id, input) : createDepartment(input)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['departments'] })
      void queryClient.invalidateQueries({ queryKey: ['institutions'] })
      toast.success(t('form.saved'))
      onOpenChange(false)
    },
    onError: (e) => setFormError(applyServerErrors(e, setError, FIELDS, t('common.actionFailed'))),
  })

  const err = (k: keyof Values) => {
    const m = formState.errors[k]?.message
    return m ? t(m) : undefined
  }

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={department ? t('org.editDepartment') : t('org.newDepartment')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" form="department-form" disabled={save.isPending}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <form id="department-form" noValidate onSubmit={handleSubmit((v) => save.mutate(v))} className="grid gap-4 sm:grid-cols-2">
        {formError ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {formError}
          </p>
        ) : null}
        <div className="sm:col-span-2">
          <Field id="dept-name" label={t('org.name')} error={err('name')}>
            <Input id="dept-name" aria-invalid={!!formState.errors.name || undefined} {...register('name')} />
          </Field>
        </div>
        <Field id="dept-code" label={t('org.code')} error={err('code')}>
          <Input id="dept-code" aria-invalid={!!formState.errors.code || undefined} {...register('code')} />
        </Field>
        <Field id="dept-category" label={t('org.category')} error={err('category')}>
          <Input id="dept-category" placeholder="IT, HR, Accounts…" {...register('category')} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="dept-institution" label={t('org.institution')} error={err('institution_id')}>
            <Select id="dept-institution" aria-invalid={!!formState.errors.institution_id || undefined} {...register('institution_id')}>
              <option value="">{t('org.centralOption')}</option>
              {(institutions.data ?? []).map((i) => (
                <option key={i.id} value={i.id}>
                  {i.name}
                </option>
              ))}
            </Select>
          </Field>
        </div>
      </form>
    </Modal>
  )
}
