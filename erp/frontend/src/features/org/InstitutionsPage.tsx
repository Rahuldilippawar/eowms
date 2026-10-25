import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { fetchInstitutions, type Institution } from './api'
import { ListPage, type Column } from './ListPage'

export function InstitutionsPage() {
  const { t } = useTranslation()
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

  return (
    <ListPage
      title={t('nav.institutions')}
      queryKey="institutions"
      fetcher={fetchInstitutions}
      columns={columns}
      emptyMessage={t('org.noInstitutions')}
      cardTitle={(r) => r.name}
    />
  )
}
