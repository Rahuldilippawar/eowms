import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { parseApiError } from '@/lib/api'
import { AuthCard } from './AuthCard'
import { useAuth } from './AuthProvider'

const schema = z.object({
  email: z.string().trim().min(1, 'auth.errors.emailRequired').email('auth.errors.emailInvalid'),
  password: z.string().min(1, 'auth.errors.passwordRequired'),
  remember: z.boolean(),
})

type FormValues = z.infer<typeof schema>

export function LoginPage() {
  const { t } = useTranslation()
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { email: '', password: '', remember: false } })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await login(values)
      const from = (location.state as { from?: string } | null)?.from
      navigate(from && from !== '/login' ? from : '/', { replace: true })
    } catch (error) {
      const { status, errors: fieldErrors, message } = parseApiError(error)
      if (status === 429) setFormError(t('auth.errors.throttled'))
      else if (status === 422) setFormError(fieldErrors?.email?.[0] ?? message ?? t('auth.errors.generic'))
      else if (status === undefined) setFormError(t('auth.errors.network'))
      else setFormError(t('auth.errors.generic'))
    }
  })

  return (
    <AuthCard title={t('auth.title')} subtitle={t('auth.subtitle')}>
        {formError ? (
          <div role="alert" className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {formError}
          </div>
        ) : null}

        <form onSubmit={onSubmit} noValidate className="space-y-4">
          <Field id="email" label={t('auth.email')} error={errors.email?.message && t(errors.email.message)}>
            <Input
              id="email"
              type="email"
              autoComplete="username"
              inputMode="email"
              aria-invalid={errors.email ? true : undefined}
              aria-describedby={errors.email ? 'email-error' : undefined}
              {...register('email')}
            />
          </Field>
          <Field id="password" label={t('auth.password')} error={errors.password?.message && t(errors.password.message)}>
            <Input
              id="password"
              type="password"
              autoComplete="current-password"
              aria-invalid={errors.password ? true : undefined}
              aria-describedby={errors.password ? 'password-error' : undefined}
              {...register('password')}
            />
          </Field>
          <div className="flex items-center justify-between gap-2">
            <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
              <input type="checkbox" className="size-4 rounded border-slate-300" {...register('remember')} />
              {t('auth.remember')}
            </label>
            <Link to="/forgot-password" className="text-sm font-medium text-brand-700 hover:underline">
              {t('auth.forgotLink')}
            </Link>
          </div>
          <Button type="submit" className="w-full" disabled={isSubmitting}>
            {isSubmitting ? <Spinner label={t('auth.submitting')} /> : t('auth.submit')}
          </Button>
        </form>
    </AuthCard>
  )
}
