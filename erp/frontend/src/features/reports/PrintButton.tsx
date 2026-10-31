import { Printer } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'

/** The browser's print dialog, where "Save as PDF" gives the PDF; the menus are left out of the print. */
export function PrintButton() {
  const { t } = useTranslation()
  return (
    <Button variant="secondary" className="gap-2 print:hidden" onClick={() => window.print()}>
      <Printer aria-hidden className="size-4" />
      {t('reports.print')}
    </Button>
  )
}
