import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { fetchEmployeeAttendance, saveEmployeeAttendance } from '@/features/attendance/parityApi'
import { WeeklyOffs } from '@/features/attendance/WeeklyOffs'

/** Per-employee shift override and biometric PIN; empty fields follow the organization rule. */
export function AttendanceTab({ employeeId, mine }: { employeeId: number; mine: boolean }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const q = useQuery({ queryKey: ['employee-attendance', employeeId], queryFn: () => fetchEmployeeAttendance(employeeId) })
  const [v, setV] = useState({ work_start: '', work_end: '', late_grace_minutes: '', half_day_below_hours: '', own_offs: false, weekly_offs: [] as number[], biometric_pin: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    if (!q.data) return
    const s = q.data.shift
    setV({
      work_start: s?.work_start ?? '',
      work_end: s?.work_end ?? '',
      late_grace_minutes: s?.late_grace_minutes?.toString() ?? '',
      half_day_below_hours: s?.half_day_below_hours?.toString() ?? '',
      own_offs: s?.weekly_offs != null,
      weekly_offs: s?.weekly_offs ?? q.data.effective.weekly_offs,
      biometric_pin: q.data.biometric_pin ?? '',
    })
  }, [q.data])

  if (q.isPending) return <Spinner label={t('common.loading')} />
  if (!q.data) return null
  const e = q.data.effective
  const editable = q.data.can_edit

  const save = async () => {
    setBusy(true)
    setErrors({})
    try {
      const n = (s: string) => (s === '' ? null : Number(s))
      const saved = await saveEmployeeAttendance(employeeId, {
        work_start: v.work_start || null,
        work_end: v.work_end || null,
        late_grace_minutes: n(v.late_grace_minutes),
        half_day_below_hours: n(v.half_day_below_hours),
        weekly_offs: v.own_offs ? v.weekly_offs : null,
        biometric_pin: v.biometric_pin.trim() || null,
      })
      queryClient.setQueryData(['employee-attendance', employeeId], saved)
      toast.success(t('form.saved'))
    } catch (err) {
      setErrors(splitServerErrors(err, ['work_start', 'work_end', 'late_grace_minutes', 'half_day_below_hours', 'weekly_offs', 'biometric_pin'], t('common.actionFailed')).byField)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-semibold">{t('profile.tabs.attendance')}</h2>
        <Link to={mine ? '/my/attendance/certificate' : `/attendance/certificate/${employeeId}`} className="text-sm font-medium text-brand-700 hover:underline">
          {t('certificate.title')}
        </Link>
      </div>
      <p className="text-sm text-slate-600 dark:text-slate-400">
        {t('rules.effective', { start: e.work_start, end: e.work_end, grace: e.late_grace_minutes, half: e.half_day_below_hours })}{' '}
        {e.weekly_offs.length ? t('rules.offsList', { days: e.weekly_offs.map((d) => t(`rules.days.${d}`)).join(', ') }) : t('rules.noOffs')}
      </p>
      {editable ? (
        <>
          <p className="text-xs text-slate-500">{t('rules.overrideHint')}</p>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field id="sh-start" label={t('rules.workStart')} error={errors.work_start}>
              <Input id="sh-start" type="time" value={v.work_start} onChange={(ev) => setV({ ...v, work_start: ev.target.value })} />
            </Field>
            <Field id="sh-end" label={t('rules.workEnd')} error={errors.work_end}>
              <Input id="sh-end" type="time" value={v.work_end} onChange={(ev) => setV({ ...v, work_end: ev.target.value })} />
            </Field>
            <Field id="sh-grace" label={t('rules.grace')} error={errors.late_grace_minutes}>
              <Input id="sh-grace" type="number" min={0} value={v.late_grace_minutes} onChange={(ev) => setV({ ...v, late_grace_minutes: ev.target.value })} />
            </Field>
            <Field id="sh-half" label={t('rules.halfDay')} error={errors.half_day_below_hours}>
              <Input id="sh-half" type="number" step="0.5" value={v.half_day_below_hours} onChange={(ev) => setV({ ...v, half_day_below_hours: ev.target.value })} />
            </Field>
            <Field id="sh-pin" label={t('rules.biometricPin')} error={errors.biometric_pin}>
              <Input id="sh-pin" value={v.biometric_pin} maxLength={20} onChange={(ev) => setV({ ...v, biometric_pin: ev.target.value })} />
            </Field>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={v.own_offs} onChange={(ev) => setV({ ...v, own_offs: ev.target.checked })} />
            {t('rules.ownOffs')}
          </label>
          {v.own_offs ? <WeeklyOffs idPrefix="emp" value={v.weekly_offs} onChange={(weekly_offs) => setV({ ...v, weekly_offs })} /> : null}
          <Button disabled={busy} onClick={() => void save()}>
            {busy ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      ) : q.data.biometric_pin ? (
        <p className="text-sm">
          {t('rules.biometricPin')}: <span className="font-mono">{q.data.biometric_pin}</span>
        </p>
      ) : null}
    </section>
  )
}
