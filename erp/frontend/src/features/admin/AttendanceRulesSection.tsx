import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { fetchAttendanceRules, saveAttendanceRules, type AttendanceRulesSettings } from '@/features/attendance/parityApi'
import { WeeklyOffs } from '@/features/attendance/WeeklyOffs'
import { useAuth } from '@/features/auth/AuthProvider'
import { splitServerErrors } from './serverErrors'

/** Organization-wide working hours, weekly offs and the daily-work edit window. */
export function AttendanceRulesSection() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const q = useQuery({ queryKey: ['attendance-rules', user?.current_organization?.id], queryFn: fetchAttendanceRules })
  const [v, setV] = useState<AttendanceRulesSettings | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    if (q.data) setV(q.data)
  }, [q.data])

  if (!v) return q.isPending ? <Spinner label={t('common.loading')} /> : null
  const num = (k: keyof AttendanceRulesSettings, label: string, step = '1') => (
    <Field id={`ar-${k}`} label={label} error={errors[k]}>
      <Input id={`ar-${k}`} type="number" step={step} value={v[k] as number} onChange={(e) => setV({ ...v, [k]: Number(e.target.value) })} />
    </Field>
  )

  const save = async () => {
    setBusy(true)
    setErrors({})
    try {
      queryClient.setQueryData(['attendance-rules', user?.current_organization?.id], await saveAttendanceRules(v))
      toast.success(t('form.saved'))
    } catch (e) {
      setErrors(splitServerErrors(e, Object.keys(v), t('common.actionFailed')).byField)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section aria-labelledby="ar-title" className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <div>
        <h2 id="ar-title" className="font-semibold">
          {t('rules.title')}
        </h2>
        <p className="text-sm text-slate-500">{t('rules.hint')}</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-3">
        <Field id="ar-timezone" label={t('rules.timezone')} error={errors.timezone}>
          <Input id="ar-timezone" value={v.timezone} onChange={(e) => setV({ ...v, timezone: e.target.value })} />
        </Field>
        <Field id="ar-work_start" label={t('rules.workStart')} error={errors.work_start}>
          <Input id="ar-work_start" type="time" value={v.work_start} onChange={(e) => setV({ ...v, work_start: e.target.value })} />
        </Field>
        <Field id="ar-work_end" label={t('rules.workEnd')} error={errors.work_end}>
          <Input id="ar-work_end" type="time" value={v.work_end} onChange={(e) => setV({ ...v, work_end: e.target.value })} />
        </Field>
        {num('late_grace_minutes', t('rules.grace'))}
        {num('half_day_below_hours', t('rules.halfDay'), '0.5')}
        {num('max_shift_hours', t('rules.maxShift'))}
        {num('daily_work_edit_days', t('rules.editDays'))}
      </div>
      <WeeklyOffs idPrefix="org" value={v.weekly_offs} onChange={(weekly_offs) => setV({ ...v, weekly_offs })} />
      <Button disabled={busy} onClick={() => void save()}>
        {busy ? <Spinner label={t('common.saving')} /> : t('common.save')}
      </Button>
    </section>
  )
}
