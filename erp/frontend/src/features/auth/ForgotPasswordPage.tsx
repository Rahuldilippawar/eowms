import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { parseApiError } from '@/lib/api'
import { requestPasswordReset } from './api'
import { AuthCard } from './AuthCard'

const schema = z.object({ email: z.string().trim().min(1, 'auth.errors.emailRequired').email('auth.errors.emailInvalid') })

export function ForgotPasswordPage() {
  const { t } = useTranslation()
  const [sent, setSent] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { email: '' } })

  const onSubmit = handleSubmit(async ({ email }) => {
    setError(null)
    try {
      setSent(await requestPasswordReset(email))
    } catch (e) {
      const { status } = parseApiError(e)
      setError(status === 429 ? t('auth.errors.throttled') : status === undefined ? t('auth.errors.network') : t('auth.errors.generic'))
    }
  })

  return (
    <AuthCard title={t('auth.forgotTitle')} subtitle={t('auth.forgotSubtitle')}>
      {sent ? (
        <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          {sent}
        </div>
      ) : (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
          {error ? (
            <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              {error}
            </div>
          ) : null}
          <Field id="email" label={t('auth.email')} error={errors.email?.message && t(errors.email.message)}>
            <Input id="email" type="email" autoComplete="username" aria-invalid={errors.email ? true : undefined} {...register('email')} />
          </Field>
          <Button type="submit" className="w-full" disabled={isSubmitting}>
            {isSubmitting ? <Spinner label={t('auth.sending')} /> : t('auth.sendLink')}
          </Button>
        </form>
      )}
      <p className="mt-4 text-center text-sm">
        <Link to="/login" className="font-medium text-brand-700 hover:underline">
          {t('auth.backToLogin')}
        </Link>
      </p>
    </AuthCard>
  )
}
