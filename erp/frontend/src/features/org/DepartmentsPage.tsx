import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { fetchDepartments, type Department } from './api'
import { ListPage, type Column } from './ListPage'

export function DepartmentsPage() {
  const { t } = useTranslation()
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

  return (
    <ListPage
      title={t('nav.departments')}
      queryKey="departments"
      fetcher={fetchDepartments}
      columns={columns}
      emptyMessage={t('org.noDepartments')}
      cardTitle={(r) => r.name}
    />
  )
}
