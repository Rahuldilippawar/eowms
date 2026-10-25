import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/features/auth/AuthProvider'
import { ActiveToggle } from '@/features/org/ActiveToggle'
import { ListPage, type Column } from '@/features/org/ListPage'
import { fetchEmployees, setEmployeeActive, type Employee } from './api'
import { EmployeeDialog } from './EmployeeDialog'

export function EmployeesPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [editing, setEditing] = useState<Employee | undefined>()
  const [open, setOpen] = useState(false)
  const columns: Column<Employee>[] = [
    { key: 'name', header: t('org.name'), cell: (e) => e.name },
    { key: 'code', header: t('people.employeeCode'), cell: (e) => <span className="font-mono text-xs">{e.employee_code}</span> },
    { key: 'designation', header: t('people.designation'), cell: (e) => e.designation ?? '—' },
    { key: 'department', header: t('people.department'), cell: (e) => e.department.name },
    { key: 'status', header: t('org.status'), cell: (e) => <Badge tone={e.is_active ? 'green' : 'slate'}>{e.is_active ? t('org.active') : t('org.inactive')}</Badge> },
  ]
  const canEdit = can('employees.edit')
  const canToggle = can('employees.activate')

  return (
    <>
      <ListPage
        title={t('nav.employees')}
        queryKey="employees"
        fetcher={fetchEmployees}
        columns={columns}
        emptyMessage={t('people.noEmployees')}
        cardTitle={(e) => e.name}
        toolbar={
          can('employees.create') ? (
            <Button
              onClick={() => {
                setEditing(undefined)
                setOpen(true)
              }}
            >
              <Plus aria-hidden className="size-4" />
              {t('people.newEmployee')}
            </Button>
          ) : null
        }
        actions={
          canEdit || canToggle
            ? (e) => (
                <>
                  {canEdit ? (
                    <Button
                      variant="ghost"
                      className="min-h-8 px-2 py-1 text-xs"
                      aria-label={t('common.editNamed', { name: e.name })}
                      onClick={() => {
                        setEditing(e)
                        setOpen(true)
                      }}
                    >
                      {t('common.edit')}
                    </Button>
                  ) : null}
                  {canToggle ? <ActiveToggle name={e.name} isActive={e.is_active} queryKeys={['employees']} onToggle={(next, reason) => setEmployeeActive(e.id, next, reason)} /> : null}
                </>
              )
            : undefined
        }
      />
      <EmployeeDialog open={open} onOpenChange={setOpen} employee={editing} />
    </>
  )
}
