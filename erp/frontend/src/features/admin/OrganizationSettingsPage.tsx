import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { CURRENT_USER_KEY, useAuth } from '@/features/auth/AuthProvider'
import { fetchOrganization, updateOrganization, type OrganizationInput } from './api'
import { AttendanceRulesSection } from './AttendanceRulesSection'
import { ModulesSection } from './ModulesSection'
import { OrganizationFields } from './OrganizationForm'
import { emptyOrganization, ORG_FIELDS, toOrganizationPayload } from './organizationDefaults'
import { splitServerErrors } from './serverErrors'

/** Settings of the organization the user is currently working in. */
export function OrganizationSettingsPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const orgId = user?.current_organization?.id ?? 0
  const query = useQuery({ queryKey: ['organization', orgId], queryFn: () => fetchOrganization(orgId), enabled: orgId > 0 })
  const [value, setValue] = useState<OrganizationInput>(emptyOrganization())
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  useEffect(() => {
    if (query.data) setValue(emptyOrganization(query.data))
  }, [query.data])

  const save = useMutation({
    mutationFn: () => updateOrganization(orgId, toOrganizationPayload(value)),
    onSuccess: (org) => {
      queryClient.setQueryData(['organization', orgId], org)
      void queryClient.invalidateQueries({ queryKey: CURRENT_USER_KEY })
      setErrors({})
      setGeneral(null)
      toast.success(t('form.saved'))
    },
    onError: (e) => {
      const r = splitServerErrors(e, ORG_FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  if (!orgId) return null
  if (query.isPending) return <LoadingState />
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />

  return (
    <div className="mx-auto max-w-3xl space-y-5">
      <h1 className="text-2xl font-semibold">{t('nav.organizationSettings')}</h1>
      <form
        noValidate
        className="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {general ? (
          <p role="alert" className="text-sm text-red-600">
            {general}
          </p>
        ) : null}
        <OrganizationFields value={value} onChange={setValue} errors={errors} idPrefix="org" />
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
        </Button>
      </form>
      <AttendanceRulesSection />
      <ModulesSection />
    </div>
  )
}
