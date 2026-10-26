import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchDepartmentOptions } from '@/features/org/api'
import { fetchHostels, saveHostel, type Hostel, type HostelGender } from './api'

export function HostelDialog({ hostel, onClose }: { hostel: Hostel | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const departments = useQuery({ queryKey: ['department-options', user?.current_organization?.id], queryFn: fetchDepartmentOptions })
  const [v, setV] = useState({ name: hostel?.name ?? '', department_id: hostel?.department?.id.toString() ?? '', gender: (hostel?.gender ?? 'mixed') as HostelGender, capacity: hostel?.capacity?.toString() ?? '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => saveHostel(hostel?.id ?? null, { name: v.name.trim(), department_id: Number(v.department_id), gender: v.gender, capacity: v.capacity === '' ? null : Number(v.capacity) }),
    onSuccess: (h) => {
      queryClient.setQueryData(['hostel', h.id], h)
      void queryClient.invalidateQueries({ queryKey: ['hostels'] })
      toast.success(t('form.saved'))
      onClose()
      if (!hostel) navigate(`/hostels/${h.id}`)
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['name', 'department_id', 'gender', 'capacity'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })
  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.name.trim()) local.name = t('form.required')
    if (!v.department_id) local.department_id = t('form.required')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={hostel ? t('hostels.edit') : t('hostels.new')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={submit}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <div className="sm:col-span-2">
          <Field id="hs-name" label={t('hostels.name')} error={errors.name}>
            <Input id="hs-name" value={v.name} maxLength={255} onChange={(e) => setV({ ...v, name: e.target.value })} />
          </Field>
        </div>
        <Field id="hs-dept" label={t('hostels.runBy')} error={errors.department_id}>
          <Select id="hs-dept" value={v.department_id} onChange={(e) => setV({ ...v, department_id: e.target.value })}>
            <option value="">—</option>
            {(departments.data ?? []).map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="hs-gender" label={t('hostels.for')}>
          <Select id="hs-gender" value={v.gender} onChange={(e) => setV({ ...v, gender: e.target.value as HostelGender })}>
            {(['boys', 'girls', 'mixed'] as const).map((g) => (
              <option key={g} value={g}>
                {t(`hostels.gender.${g}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="hs-capacity" label={t('hostels.capacity')} error={errors.capacity}>
          <Input id="hs-capacity" type="number" inputMode="numeric" min={0} value={v.capacity} onChange={(e) => setV({ ...v, capacity: e.target.value })} />
        </Field>
      </div>
    </Modal>
  )
}

export function HostelsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [creating, setCreating] = useState(false)
  const q = useQuery({ queryKey: ['hostels', user?.current_organization?.id], queryFn: fetchHostels })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">{t('nav.hostels')}</h1>
        {q.data?.meta.can_create ? (
          <Button onClick={() => setCreating(true)}>
            <Plus aria-hidden className="size-4" />
            {t('hostels.new')}
          </Button>
        ) : null}
      </div>
      {q.isPending ? <LoadingState /> : null}
      {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
      {q.data && q.data.data.length === 0 ? <EmptyState message={t('hostels.none')} /> : null}
      <ul className="grid gap-3 sm:grid-cols-2">
        {(q.data?.data ?? []).map((h) => (
          <li key={h.id}>
            <Link to={`/hostels/${h.id}`} className="block rounded-2xl border border-slate-200 bg-white p-4 hover:border-brand-600 dark:border-slate-800 dark:bg-slate-900">
              <p className="font-semibold">{h.name}</p>
              <p className="text-sm text-slate-500">{[t(`hostels.gender.${h.gender}`), h.department?.name, !h.is_active ? t('hostels.closed') : null].filter(Boolean).join(' · ')}</p>
              <p className="mt-2 flex flex-wrap gap-x-4 text-sm">
                <span>{t('hostels.residentsOfBeds', { residents: h.residents_count, beds: h.beds })}</span>
                {h.out_now ? <span className="text-slate-600">{t('hostels.outNow', { count: h.out_now })}</span> : null}
                {h.overdue_returns ? <span className="font-medium text-red-700">{t('hostels.overdueReturns', { count: h.overdue_returns })}</span> : null}
              </p>
            </Link>
          </li>
        ))}
      </ul>
      {creating ? <HostelDialog hostel={null} onClose={() => setCreating(false)} /> : null}
    </div>
  )
}
