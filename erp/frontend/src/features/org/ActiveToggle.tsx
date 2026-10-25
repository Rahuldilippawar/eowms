import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useToast } from '@/components/ui/Toast'
import { actionErrorMessage } from '@/lib/forms'

/** Activate / deactivate button with a confirmation and an optional audit reason. */
export function ActiveToggle({
  name,
  isActive,
  queryKeys,
  onToggle,
}: {
  name: string
  isActive: boolean
  queryKeys: string[]
  onToggle: (next: boolean, reason: string) => Promise<unknown>
}) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)

  return (
    <>
      <Button variant="ghost" className="min-h-8 px-2 py-1 text-xs" onClick={() => setOpen(true)}>
        {isActive ? t('common.deactivate') : t('common.activate')}
      </Button>
      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        title={isActive ? t('common.deactivateTitle', { name }) : t('common.activateTitle', { name })}
        body={isActive ? t('common.deactivateBody') : t('common.activateBody')}
        confirmLabel={isActive ? t('common.deactivate') : t('common.activate')}
        danger={isActive}
        withReason
        onConfirm={async (reason) => {
          try {
            await onToggle(!isActive, reason)
          } catch (e) {
            throw new Error(actionErrorMessage(e, t('common.actionFailed')))
          }
          queryKeys.forEach((k) => void queryClient.invalidateQueries({ queryKey: [k] }))
          toast.success(t('form.saved'))
        }}
      />
    </>
  )
}
