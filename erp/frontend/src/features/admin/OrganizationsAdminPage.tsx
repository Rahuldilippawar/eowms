import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { CURRENT_USER_KEY } from '@/features/auth/AuthProvider'
import { ActiveToggle } from '@/features/org/ActiveToggle'
import { ListPage, type Column } from '@/features/org/ListPage'
import { createOrganization, fetchOrganizations, setOrganizationActive, type Organization, type OrganizationInput } from './api'
import { OrganizationFields } from './OrganizationForm'
import { emptyOrganization, ORG_FIELDS, toOrganizationPayload } from './organizationDefaults'
import { splitServerErrors } from './serverErrors'

function NewOrganizationDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [value, setValue] = useState<OrganizationInput>(emptyOrganization())
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: () => createOrganization(toOrganizationPayload(value)),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
      void queryClient.invalidateQueries({ queryKey: CURRENT_USER_KEY })
      toast.success(t('admin.organizationCreated'))
      setValue(emptyOrganization())
      setErrors({})
      onOpenChange(false)
    },
    onError: (e) => {
      const r = splitServerErrors(e, ORG_FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={t('admin.newOrganization')}
      description={t('admin.newOrganizationHint')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={() => save.mutate()}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      {general ? (
        <p role="alert" className="mb-3 text-sm text-red-600">
          {general}
        </p>
      ) : null}
      <OrganizationFields value={value} onChange={setValue} errors={errors} idPrefix="new-org" />
    </Modal>
  )
}

/** Super admins only: every organization on the platform. */
export function OrganizationsAdminPage() {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const columns: Column<Organization>[] = [
    { key: 'name', header: t('org.name'), cell: (o) => o.name },
    { key: 'code', header: t('org.code'), cell: (o) => <span className="font-mono text-xs">{o.code}</span> },
    {
      key: 'status',
      header: t('org.status'),
      cell: (o) => <Badge tone={o.is_active ? 'green' : 'slate'}>{o.is_active ? t('org.active') : t('org.inactive')}</Badge>,
    },
  ]

  return (
    <>
      <ListPage
        title={t('nav.allOrganizations')}
        queryKey="organizations"
        fetcher={fetchOrganizations}
        columns={columns}
        emptyMessage={t('admin.noOrganizations')}
        cardTitle={(o) => o.name}
        toolbar={
          <Button onClick={() => setOpen(true)}>
            <Plus aria-hidden className="size-4" />
            {t('admin.newOrganization')}
          </Button>
        }
        actions={(o) => (
          <ActiveToggle name={o.name} isActive={o.is_active} queryKeys={['organizations', CURRENT_USER_KEY[0]]} onToggle={(next, reason) => setOrganizationActive(o.id, next, reason)} />
        )}
      />
      <NewOrganizationDialog open={open} onOpenChange={setOpen} />
    </>
  )
}
