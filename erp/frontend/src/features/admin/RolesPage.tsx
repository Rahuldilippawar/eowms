import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { actionErrorMessage } from '@/lib/forms'
import { deleteRole, fetchRoles, type Role } from './api'

function DeleteRole({ role }: { role: Role }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button variant="ghost" className="min-h-8 px-2 py-1 text-xs text-red-700" onClick={() => setOpen(true)}>
        {t('common.delete')}
      </Button>
      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        title={t('admin.deleteRoleTitle', { role: role.name })}
        body={t('admin.deleteRoleBody')}
        confirmLabel={t('common.delete')}
        danger
        onConfirm={async () => {
          try {
            await deleteRole(role.id)
          } catch (e) {
            throw new Error(actionErrorMessage(e, t('common.actionFailed')))
          }
          void queryClient.invalidateQueries({ queryKey: ['roles'] })
          toast.success(t('admin.roleDeleted'))
        }}
      />
    </>
  )
}

export function RolesPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const query = useQuery({ queryKey: ['roles', user?.current_organization?.id], queryFn: fetchRoles })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold">{t('nav.roles')}</h1>
        {can('roles.create') ? (
          <Button asChild>
            <Link to="/roles/new">
              <Plus aria-hidden className="size-4" />
              {t('admin.newRole')}
            </Link>
          </Button>
        ) : null}
      </div>
      <section className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        {query.isPending ? (
          <LoadingState />
        ) : query.isError ? (
          <ErrorState onRetry={() => void query.refetch()} />
        ) : query.data.length === 0 ? (
          <EmptyState message={t('admin.noRoles')} />
        ) : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {query.data.map((role) => (
              <li key={role.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="font-medium">
                    {role.name} {role.is_system ? <Badge tone="blue">{t('admin.builtIn')}</Badge> : null}
                  </p>
                  <p className="text-sm text-slate-500">{t('admin.assignedTo', { count: role.assignments_count ?? 0 })}</p>
                </div>
                <div className="flex gap-1">
                  <Button asChild variant="ghost" className="min-h-8 px-2 py-1 text-xs">
                    <Link to={`/roles/${role.id}`} aria-label={t('common.editNamed', { name: role.name })}>
                      {can('roles.edit') ? t('common.edit') : t('common.view')}
                    </Link>
                  </Button>
                  {can('roles.delete') && !role.is_system ? <DeleteRole role={role} /> : null}
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}
