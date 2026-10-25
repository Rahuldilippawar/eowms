import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/features/auth/AuthProvider'
import { ActiveToggle } from './ActiveToggle'
import { fetchInstitutions, setInstitutionActive, type Institution } from './api'
import { InstitutionDialog } from './forms/InstitutionDialog'
import { ListPage, type Column } from './ListPage'

export function InstitutionsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [editing, setEditing] = useState<Institution | undefined>()
  const [dialogOpen, setDialogOpen] = useState(false)

  const columns: Column<Institution>[] = [
    { key: 'name', header: t('org.name'), cell: (r) => r.name },
    { key: 'code', header: t('org.code'), cell: (r) => <span className="font-mono text-xs">{r.code}</span> },
    { key: 'type', header: t('org.type'), cell: (r) => t(`org.types.${r.type}`) },
    { key: 'departments', header: t('nav.departments'), cell: (r) => r.departments_count ?? 0 },
    {
      key: 'status',
      header: t('org.status'),
      cell: (r) => <Badge tone={r.is_active ? 'green' : 'slate'}>{r.is_active ? t('org.active') : t('org.inactive')}</Badge>,
    },
  ]

  const canEdit = can('institutions.edit')
  const canToggle = can('institutions.activate')

  return (
    <>
      <ListPage
        title={t('nav.institutions')}
        queryKey="institutions"
        fetcher={fetchInstitutions}
        columns={columns}
        emptyMessage={t('org.noInstitutions')}
        cardTitle={(r) => r.name}
        toolbar={
          can('institutions.create') ? (
            <Button
              onClick={() => {
                setEditing(undefined)
                setDialogOpen(true)
              }}
            >
              <Plus aria-hidden className="size-4" />
              {t('org.newInstitution')}
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
                    <ActiveToggle name={r.name} isActive={r.is_active} queryKeys={['institutions']} onToggle={(next, reason) => setInstitutionActive(r.id, next, reason)} />
                  ) : null}
                </>
              )
            : undefined
        }
      />
      <InstitutionDialog open={dialogOpen} onOpenChange={setDialogOpen} institution={editing} />
    </>
  )
}
