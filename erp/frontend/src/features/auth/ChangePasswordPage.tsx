import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { changePassword } from '@/features/admin/settingsApi'
import { CURRENT_USER_KEY, useAuth } from './AuthProvider'

/** Own password change; also the only screen of an account that must set a new password. */
export function ChangePasswordPage({ forced = false }: { forced?: boolean }) {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { logout } = useAuth()
  const [v, setV] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    const local: Record<string, string> = {}
    if (!v.current_password) local.current_password = t('form.required')
    if (v.password.length < 8 || !/[A-Za-z]/.test(v.password) || !/\d/.test(v.password)) local.password = t('auth.passwordRule')
    if (v.password !== v.password_confirmation) local.password_confirmation = t('auth.passwordMismatch')
    setErrors(local)
    if (Object.keys(local).length) return
    setBusy(true)
    try {
      queryClient.setQueryData(CURRENT_USER_KEY, await changePassword(v))
      toast.success(t('account.passwordChanged'))
      setV({ current_password: '', password: '', password_confirmation: '' })
      if (forced) navigate('/', { replace: true })
    } catch (e) {
      setErrors(splitServerErrors(e, ['current_password', 'password', 'password_confirmation'], t('common.actionFailed')).byField)
    } finally {
      setBusy(false)
    }
  }

  const input = (k: keyof typeof v, label: string, auto: string) => (
    <Field id={`cp-${k}`} label={label} error={errors[k]}>
      <Input id={`cp-${k}`} type="password" autoComplete={auto} value={v[k]} aria-invalid={!!errors[k] || undefined} onChange={(e) => setV({ ...v, [k]: e.target.value })} />
    </Field>
  )

  return (
    <div className={forced ? 'flex min-h-full items-center justify-center bg-slate-50 p-4 dark:bg-slate-950' : 'mx-auto max-w-md'}>
      <form
        noValidate
        onSubmit={(e) => {
          e.preventDefault()
          void submit()
        }}
        className="w-full max-w-md space-y-4 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
      >
        <h1 className="text-xl font-semibold">{t('account.changePassword')}</h1>
        {forced ? (
          <p role="status" className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
            {t('account.mustChange')}
          </p>
        ) : null}
        {input('current_password', t('account.currentPassword'), 'current-password')}
        {input('password', t('account.newPassword'), 'new-password')}
        {input('password_confirmation', t('account.confirmPassword'), 'new-password')}
        <p className="text-xs text-slate-500">{t('auth.passwordRule')}</p>
        <div className="flex flex-wrap gap-2">
          <Button type="submit" disabled={busy}>
            {busy ? <Spinner label={t('common.saving')} /> : t('account.savePassword')}
          </Button>
          {forced ? (
            <Button type="button" variant="ghost" onClick={() => void logout().then(() => navigate('/login', { replace: true }))}>
              {t('auth.logout')}
            </Button>
          ) : null}
        </div>
      </form>
    </div>
  )
}
