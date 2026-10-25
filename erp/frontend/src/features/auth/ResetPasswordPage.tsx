import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { parseApiError } from '@/lib/api'
import { resetPassword } from './api'
import { AuthCard } from './AuthCard'

const schema = z
  .object({
    password: z
      .string()
      .min(8, 'auth.errors.passwordWeak')
      .regex(/[A-Za-z]/, 'auth.errors.passwordWeak')
      .regex(/[0-9]/, 'auth.errors.passwordWeak'),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'auth.errors.passwordMismatch' })

export function ResetPasswordPage() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const [done, setDone] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { password: '', password_confirmation: '' } })

  const onSubmit = handleSubmit(async (values) => {
    setError(null)
    try {
      setDone(await resetPassword({ token, email, ...values }))
    } catch (e) {
      const { status, errors: fieldErrors } = parseApiError(e)
      if (status === 422) setError(fieldErrors?.email?.[0] ?? fieldErrors?.password?.[0] ?? t('auth.errors.generic'))
      else if (status === 429) setError(t('auth.errors.throttled'))
      else setError(status === undefined ? t('auth.errors.network') : t('auth.errors.generic'))
    }
  })

  if (!token || !email) {
    return (
      <AuthCard title={t('auth.resetTitle')}>
        <p role="alert" className="text-sm text-red-700">
          {t('auth.errors.linkInvalid')}
        </p>
        <p className="mt-4 text-center text-sm">
          <Link to="/forgot-password" className="font-medium text-brand-700 hover:underline">
            {t('auth.requestNewLink')}
          </Link>
        </p>
      </AuthCard>
    )
  }

  return (
    <AuthCard title={t('auth.resetTitle')} subtitle={email}>
      {done ? (
        <div className="space-y-4">
          <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            {done}
          </div>
          <Button asChild className="w-full">
            <Link to="/login">{t('auth.backToLogin')}</Link>
          </Button>
        </div>
      ) : (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
          {error ? (
            <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              {error}
            </div>
          ) : null}
          <Field id="password" label={t('auth.newPassword')} error={errors.password?.message && t(errors.password.message)}>
            <Input id="password" type="password" autoComplete="new-password" aria-invalid={errors.password ? true : undefined} {...register('password')} />
          </Field>
          <Field
            id="password_confirmation"
            label={t('auth.confirmPassword')}
            error={errors.password_confirmation?.message && t(errors.password_confirmation.message)}
          >
            <Input
              id="password_confirmation"
              type="password"
              autoComplete="new-password"
              aria-invalid={errors.password_confirmation ? true : undefined}
              {...register('password_confirmation')}
            />
          </Field>
          <Button type="submit" className="w-full" disabled={isSubmitting}>
            {isSubmitting ? <Spinner label={t('auth.saving')} /> : t('auth.resetSubmit')}
          </Button>
        </form>
      )}
    </AuthCard>
  )
}
