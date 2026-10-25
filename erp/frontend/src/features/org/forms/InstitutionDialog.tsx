import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
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
import { applyServerErrors } from '@/lib/forms'
import { createInstitution, updateInstitution, type Institution } from '../api'

const CODE = /^[A-Za-z0-9_-]+$/
const schema = z.object({
  type: z.enum(['college', 'school', 'other']),
  name: z.string().trim().min(1, 'form.required').max(255),
  code: z.string().trim().min(1, 'form.required').max(30).regex(CODE, 'form.codeFormat'),
  address: z.string().max(1000).optional(),
  phone: z.string().max(30).optional(),
  email: z.union([z.literal(''), z.string().email('auth.errors.emailInvalid')]).optional(),
})
type Values = z.infer<typeof schema>
const FIELDS = ['type', 'name', 'code', 'address', 'phone', 'email'] as const

export function InstitutionDialog({ open, onOpenChange, institution }: { open: boolean; onOpenChange: (o: boolean) => void; institution?: Institution }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState<string | null>(null)
  const form = useForm<Values>({ resolver: zodResolver(schema) })
  const { register, handleSubmit, reset, setError, formState } = form

  useEffect(() => {
    if (open) {
      setFormError(null)
      reset({
        type: institution?.type ?? 'college',
        name: institution?.name ?? '',
        code: institution?.code ?? '',
        address: institution?.address ?? '',
        phone: institution?.phone ?? '',
        email: institution?.email ?? '',
      })
    }
  }, [open, institution, reset])

  const save = useMutation({
    mutationFn: (v: Values) => {
      const input = { ...v, address: v.address || null, phone: v.phone || null, email: v.email || null }
      return institution ? updateInstitution(institution.id, input) : createInstitution(input)
    },
    onSuccess: () => {
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
      title={institution ? t('org.editInstitution') : t('org.newInstitution')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" form="institution-form" disabled={save.isPending}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <form id="institution-form" noValidate onSubmit={handleSubmit((v) => save.mutate(v))} className="grid gap-4 sm:grid-cols-2">
        {formError ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {formError}
          </p>
        ) : null}
        <div className="sm:col-span-2">
          <Field id="inst-name" label={t('org.name')} error={err('name')}>
            <Input id="inst-name" aria-invalid={!!formState.errors.name || undefined} {...register('name')} />
          </Field>
        </div>
        <Field id="inst-code" label={t('org.code')} error={err('code')}>
          <Input id="inst-code" aria-invalid={!!formState.errors.code || undefined} {...register('code')} />
        </Field>
        <Field id="inst-type" label={t('org.type')} error={err('type')}>
          <Select id="inst-type" {...register('type')}>
            <option value="college">{t('org.types.college')}</option>
            <option value="school">{t('org.types.school')}</option>
            <option value="other">{t('org.types.other')}</option>
          </Select>
        </Field>
        <Field id="inst-phone" label={t('org.phone')} error={err('phone')}>
          <Input id="inst-phone" type="tel" {...register('phone')} />
        </Field>
        <Field id="inst-email" label={t('auth.email')} error={err('email')}>
          <Input id="inst-email" type="email" aria-invalid={!!formState.errors.email || undefined} {...register('email')} />
        </Field>
        <div className="sm:col-span-2">
          <Field id="inst-address" label={t('org.address')} error={err('address')}>
            <Input id="inst-address" {...register('address')} />
          </Field>
        </div>
      </form>
    </Modal>
  )
}
