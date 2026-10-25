import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { ActiveToggle } from '@/features/org/ActiveToggle'
import { actionErrorMessage } from '@/lib/forms'
import { parseApiError } from '@/lib/api'
import { NotFoundPage } from '@/pages/NotFoundPage'
import { addAssignment, fetchUser, removeAssignment, sendUserPasswordReset, setUserActive, updateUser, type AssignmentInput, type ManagedUser, type RoleAssignment } from './api'
import { AssignmentFields } from './AssignmentFields'
import { splitServerErrors } from './serverErrors'

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  const id = `section-${title.replace(/\W+/g, '-')}`
  return (
    <section aria-labelledby={id} className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <h2 id={id} className="mb-4 font-semibold">{title}</h2>
      {children}
    </section>
  )
}

function AccountForm({ user }: { user: ManagedUser }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [name, setName] = useState(user.name)
  const [email, setEmail] = useState(user.email)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  useEffect(() => {
    setName(user.name)
    setEmail(user.email)
  }, [user])

  const save = useMutation({
    mutationFn: () => updateUser(user.id, { name: name.trim(), email: email.trim() }),
    onSuccess: (u) => {
      queryClient.setQueryData(['user', u.id], u)
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      setErrors({})
      setGeneral(null)
      toast.success(t('form.saved'))
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['name', 'email'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  return (
    <form
      noValidate
      onSubmit={(e) => {
        e.preventDefault()
        save.mutate()
      }}
      className="grid gap-4 sm:grid-cols-2"
    >
      {general ? (
        <p role="alert" className="text-sm text-red-600 sm:col-span-2">
          {general}
        </p>
      ) : null}
      <Field id="acc-name" label={t('org.name')} error={errors.name}>
        <Input id="acc-name" value={name} onChange={(e) => setName(e.target.value)} />
      </Field>
      <Field id="acc-email" label={t('auth.email')} error={errors.email}>
        <Input id="acc-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
      </Field>
      <div className="sm:col-span-2">
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
        </Button>
      </div>
    </form>
  )
}

function AssignmentRow({ userId, assignment, canRemove }: { userId: number; assignment: RoleAssignment; canRemove: boolean }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [confirm, setConfirm] = useState(false)
  const units = [...assignment.institutions.map((i) => i.name), ...assignment.departments.map((d) => d.name)]

  return (
    <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p className="font-medium">{assignment.role.name}</p>
        <p className="text-sm text-slate-500">{units.length ? units.join(', ') : t('admin.wholeOrganization')}</p>
      </div>
      {canRemove ? (
        <>
          <Button variant="secondary" className="self-start" onClick={() => setConfirm(true)}>
            {t('admin.removeRole')}
          </Button>
          <ConfirmDialog
            open={confirm}
            onOpenChange={setConfirm}
            title={t('admin.removeRoleTitle', { role: assignment.role.name })}
            body={t('admin.removeRoleBody')}
            confirmLabel={t('admin.removeRole')}
            danger
            onConfirm={async () => {
              try {
                await removeAssignment(assignment.id)
              } catch (e) {
                throw new Error(actionErrorMessage(e, t('common.actionFailed')))
              }
              void queryClient.invalidateQueries({ queryKey: ['user', userId] })
              void queryClient.invalidateQueries({ queryKey: ['users'] })
              toast.success(t('form.saved'))
            }}
          />
        </>
      ) : null}
    </li>
  )
}

function AddAssignment({ userId }: { userId: number }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const empty: AssignmentInput = { role_id: 0, institution_ids: [], department_ids: [] }
  const [value, setValue] = useState(empty)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: () => addAssignment(userId, value),
    onSuccess: (u) => {
      queryClient.setQueryData(['user', u.id], u)
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      setValue(empty)
      setErrors({})
      setGeneral(null)
      toast.success(t('form.saved'))
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['role_id', 'institution_ids', 'department_ids'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  return (
    <div className="space-y-4 border-t border-slate-200 pt-4 dark:border-slate-800">
      <h3 className="text-sm font-semibold">{t('admin.addRole')}</h3>
      {general ? (
        <p role="alert" className="text-sm text-red-600">
          {general}
        </p>
      ) : null}
      <AssignmentFields value={value} onChange={setValue} errors={errors} />
      <Button disabled={!value.role_id || save.isPending} onClick={() => save.mutate()}>
        {save.isPending ? <Spinner label={t('common.saving')} /> : t('admin.addRole')}
      </Button>
    </div>
  )
}

export function UserDetailPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const userId = Number(id)
  const { user: me, can } = useAuth()
  const toast = useToast()
  const [resetConfirm, setResetConfirm] = useState(false)
  const query = useQuery({ queryKey: ['user', userId], queryFn: () => fetchUser(userId), enabled: Number.isInteger(userId) && userId > 0, retry: false })

  if (query.isError && parseApiError(query.error).status === 404) return <NotFoundPage />
  if (query.isPending) return <LoadingState />
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />

  const user = query.data
  const isSelf = me?.id === user.id

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <Link to="/users" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.users')}
      </Link>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{user.name}</h1>
          <p className="text-sm text-slate-500">{user.email}</p>
          <div className="mt-2 flex gap-2">
            <Badge tone={user.is_active ? 'green' : 'slate'}>{user.is_active ? t('org.active') : t('org.inactive')}</Badge>
            {user.is_super_admin ? <Badge tone="blue">{t('admin.superAdmin')}</Badge> : null}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {can('users.reset_password') && user.is_active ? (
            <>
              <Button variant="secondary" onClick={() => setResetConfirm(true)}>
                {t('admin.sendReset')}
              </Button>
              <ConfirmDialog
                open={resetConfirm}
                onOpenChange={setResetConfirm}
                title={t('admin.sendReset')}
                body={t('admin.sendResetBody', { email: user.email })}
                confirmLabel={t('admin.send')}
                onConfirm={async () => {
                  try {
                    await sendUserPasswordReset(user.id)
                  } catch (e) {
                    throw new Error(actionErrorMessage(e, t('common.actionFailed')))
                  }
                  toast.success(t('admin.resetSent'))
                }}
              />
            </>
          ) : null}
          {can('users.activate') && !isSelf ? (
            <ActiveToggle name={user.name} isActive={user.is_active} queryKeys={['users', 'user']} onToggle={(next, reason) => setUserActive(user.id, next, reason)} />
          ) : null}
        </div>
      </div>

      {can('users.edit') ? (
        <Section title={t('admin.account')}>
          <AccountForm user={user} />
        </Section>
      ) : null}

      <Section title={t('admin.rolesHere')}>
        {user.role_assignments.length === 0 ? (
          <p className="text-sm text-slate-500">{t('admin.noRolesHere')}</p>
        ) : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {user.role_assignments.map((a) => (
              <AssignmentRow key={a.id} userId={user.id} assignment={a} canRemove={can('roles.assign') && !isSelf} />
            ))}
          </ul>
        )}
        {can('roles.assign') && !isSelf ? <AddAssignment userId={user.id} /> : null}
        {isSelf ? <p className="mt-3 text-sm text-slate-500">{t('admin.selfNote')}</p> : null}
      </Section>
    </div>
  )
}
