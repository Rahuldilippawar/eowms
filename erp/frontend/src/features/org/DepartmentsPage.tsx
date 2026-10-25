import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/features/auth/AuthProvider'
import { ActiveToggle } from './ActiveToggle'
import { fetchDepartments, setDepartmentActive, type Department } from './api'
import { DepartmentDialog } from './forms/DepartmentDialog'
import { ListPage, type Column } from './ListPage'

export function DepartmentsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [editing, setEditing] = useState<Department | undefined>()
  const [dialogOpen, setDialogOpen] = useState(false)

  const columns: Column<Department>[] = [
    { key: 'name', header: t('org.name'), cell: (r) => r.name },
    { key: 'code', header: t('org.code'), cell: (r) => <span className="font-mono text-xs">{r.code}</span> },
    {
      key: 'institution',
      header: t('org.institution'),
      cell: (r) => (r.institution ? r.institution.name : <Badge tone="blue">{t('org.central')}</Badge>),
    },
    {
      key: 'status',
      header: t('org.status'),
      cell: (r) => <Badge tone={r.is_active ? 'green' : 'slate'}>{r.is_active ? t('org.active') : t('org.inactive')}</Badge>,
    },
  ]

  const canEdit = can('departments.edit')
  const canToggle = can('departments.activate')

  return (
    <>
      <ListPage
        title={t('nav.departments')}
        queryKey="departments"
        fetcher={fetchDepartments}
        columns={columns}
        emptyMessage={t('org.noDepartments')}
        cardTitle={(r) => r.name}
        toolbar={
          can('departments.create') ? (
            <Button
              onClick={() => {
                setEditing(undefined)
                setDialogOpen(true)
              }}
            >
              <Plus aria-hidden className="size-4" />
              {t('org.newDepartment')}
            </Button>
          ) : null
        }
        actions={
          canEdit || canToggle
            ? (r) => (
                <>
                  {canEdit ? (
                    <Button
                      variant="ghost"
                      className="min-h-8 px-2 py-1 text-xs"
                      aria-label={t('common.editNamed', { name: r.name })}
                      onClick={() => {
                        setEditing(r)
                        setDialogOpen(true)
                      }}
                    >
                      {t('common.edit')}
                    </Button>
                  ) : null}
                  {canToggle ? (
                    <ActiveToggle name={r.name} isActive={r.is_active} queryKeys={['departments']} onToggle={(next, reason) => setDepartmentActive(r.id, next, reason)} />
                  ) : null}
                </>
              )
            : undefined
        }
      />
      <DepartmentDialog open={dialogOpen} onOpenChange={setDialogOpen} department={editing} />
    </>
  )
}
