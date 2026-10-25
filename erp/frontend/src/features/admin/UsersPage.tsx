import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { ListPage, type Column } from '@/features/org/ListPage'
import { createUser, fetchUsers, type AssignmentInput, type ManagedUser } from './api'
import { AssignmentFields } from './AssignmentFields'
import { splitServerErrors } from './serverErrors'

const FIELDS = ['name', 'email', 'role_id', 'institution_ids', 'department_ids'] as const

function NewUserDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [assignment, setAssignment] = useState<AssignmentInput>({ role_id: 0, institution_ids: [], department_ids: [] })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  const reset = () => {
    setName('')
    setEmail('')
    setAssignment({ role_id: 0, institution_ids: [], department_ids: [] })
    setErrors({})
    setGeneral(null)
  }

  const save = useMutation({
    mutationFn: () => createUser({ name: name.trim(), email: email.trim(), ...assignment }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      toast.success(t('admin.userCreated'))
      reset()
      onOpenChange(false)
    },
    onError: (e) => {
      const { byField, general: g } = splitServerErrors(e, FIELDS, t('common.actionFailed'))
      setErrors(byField)
      setGeneral(g)
    },
  })

  const submit = () => {
    const local: Record<string, string> = {}
    if (!name.trim()) local.name = t('form.required')
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) local.email = t('auth.errors.emailInvalid')
    if (!assignment.role_id) local.role_id = t('form.required')
    setErrors(local)
    setGeneral(null)
    if (Object.keys(local).length === 0) save.mutate()
  }

  return (
    <Modal
      open={open}
      onOpenChange={(o) => {
        if (!o) reset()
        onOpenChange(o)
      }}
      title={t('admin.newUser')}
      description={t('admin.newUserHint')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button onClick={submit} disabled={save.isPending}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('admin.createUser')}
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
        <Field id="user-name" label={t('org.name')} error={errors.name}>
          <Input id="user-name" value={name} aria-invalid={!!errors.name || undefined} onChange={(e) => setName(e.target.value)} />
        </Field>
        <Field id="user-email" label={t('auth.email')} error={errors.email}>
          <Input id="user-email" type="email" value={email} aria-invalid={!!errors.email || undefined} onChange={(e) => setEmail(e.target.value)} />
        </Field>
        <AssignmentFields value={assignment} onChange={setAssignment} errors={errors} />
      </div>
    </Modal>
  )
}

export function UsersPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [open, setOpen] = useState(false)

  const columns: Column<ManagedUser>[] = [
    {
      key: 'name',
      header: t('org.name'),
      cell: (u) => (
        <Link to={`/users/${u.id}`} className="font-medium text-brand-700 hover:underline">
          {u.name}
        </Link>
      ),
    },
    { key: 'email', header: t('auth.email'), cell: (u) => u.email },
    { key: 'roles', header: t('admin.roles'), cell: (u) => u.role_assignments.map((a) => a.role.name).join(', ') || '—' },
    {
      key: 'status',
      header: t('org.status'),
      cell: (u) => <Badge tone={u.is_active ? 'green' : 'slate'}>{u.is_active ? t('org.active') : t('org.inactive')}</Badge>,
    },
  ]

  return (
    <>
      <ListPage
        title={t('nav.users')}
        queryKey="users"
        fetcher={fetchUsers}
        columns={columns}
        emptyMessage={t('admin.noUsers')}
        cardTitle={(u) => (
          <Link to={`/users/${u.id}`} className="text-brand-700 hover:underline">
            {u.name}
          </Link>
        )}
        toolbar={
          can('users.create') && can('roles.assign') ? (
            <Button onClick={() => setOpen(true)}>
              <Plus aria-hidden className="size-4" />
              {t('admin.newUser')}
            </Button>
          ) : null
        }
      />
      <NewUserDialog open={open} onOpenChange={setOpen} />
    </>
  )
}
