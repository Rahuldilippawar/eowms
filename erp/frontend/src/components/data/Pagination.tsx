import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import type { Paginated } from '@/lib/pagination'

export function Pagination({ meta, onPage }: { meta: Paginated<unknown>['meta']; onPage: (page: number) => void }) {
  const { t } = useTranslation()
  if (meta.last_page <= 1) return null
  return (
    <nav aria-label={t('common.pagination')} className="flex items-center justify-between gap-3 pt-4 text-sm text-slate-600">
      <span>{t('common.showing', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total })}</span>
      <div className="flex gap-2">
        <Button variant="secondary" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
          {t('common.previous')}
        </Button>
        <Button variant="secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
          {t('common.next')}
        </Button>
      </div>
    </nav>
  )
}
