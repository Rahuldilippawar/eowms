import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { AlarmClock, CalendarCheck, CalendarX, ClipboardCheck, GraduationCap, ListChecks, ListTodo, Plane, UserCheck, Users, type LucideIcon } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchDepartmentOptions, fetchInstitutionOptions } from '@/features/org/api'
import { formatDate, todayYmd } from '@/lib/format'
import { fetchDashboard, type DashboardData, type Period } from './api'

type KpiKey = keyof DashboardData['kpis']
const KPIS: { key: KpiKey; icon: LucideIcon; to?: string }[] = [
  { key: 'employees', icon: Users, to: '/employees' },
  { key: 'present_today', icon: UserCheck, to: '/attendance' },
  { key: 'on_leave_today', icon: Plane, to: '/attendance' },
  { key: 'absent_today', icon: CalendarX, to: '/attendance' },
  { key: 'students', icon: GraduationCap, to: '/students' },
  { key: 'pending_leave_approvals', icon: ClipboardCheck, to: '/approvals' },
  { key: 'pending_corrections', icon: CalendarCheck, to: '/approvals' },
  { key: 'my_open_tasks', icon: ListChecks, to: '/tasks' },
  { key: 'tasks_to_verify', icon: ListTodo, to: '/tasks' },
  { key: 'overdue_tasks', icon: AlarmClock, to: '/tasks' },
]

/** Attendance states use the fixed status hues; each also has its label in legend, tooltip and table. */
const SERIES = [
  { key: 'present', color: 'var(--viz-good)' },
  { key: 'late', color: 'var(--viz-warning)' },
  { key: 'leave', color: 'var(--viz-neutral)' },
  { key: 'absent', color: 'var(--viz-critical)' },
] as const

// Tooltip and legend text stay in ink colour; the swatch beside it carries the series identity.
const tooltipItemStyle = { color: 'var(--viz-ink)' }
const axis = {
  stroke: 'var(--viz-grid)',
  tick: { fill: 'var(--viz-ink)', fontSize: 12 },
}
const tooltipStyle = {
  backgroundColor: 'var(--viz-surface)',
  border: '1px solid var(--viz-grid)',
  borderRadius: 8,
  color: 'var(--viz-ink)',
  fontSize: 12,
}

function Kpi({ label, value, icon: Icon, to }: { label: string; value: number; icon: LucideIcon; to?: string }) {
  const body = (
    <>
      <Icon aria-hidden className="size-5 text-brand-700" />
      <p className="mt-2 text-2xl font-semibold tabular-nums">{value.toLocaleString()}</p>
      <p className="text-sm text-slate-500">{label}</p>
    </>
  )
  const cls = 'block rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'
  return to ? (
    <Link to={to} className={`${cls} hover:border-brand-600`}>
      {body}
    </Link>
  ) : (
    <div className={cls}>{body}</div>
  )
}

function ChartCard({ title, children, table }: { title: string; children: React.ReactNode; table: React.ReactNode }) {
  const { t } = useTranslation()
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
      <h2 className="mb-3 font-semibold">{title}</h2>
      {children}
      <details className="mt-3 text-sm">
        <summary className="cursor-pointer text-slate-500">{t('dashboard.showTable')}</summary>
        <div className="mt-2 max-h-64 overflow-auto">{table}</div>
      </details>
    </section>
  )
}

function TotalsChart({ rows, label, nullLabel }: { rows: { id: number | null; name: string | null; total: number }[]; label: string; nullLabel?: string }) {
  const { t } = useTranslation()
  const data = rows.map((r) => ({
    name: r.name ?? nullLabel ?? t('dashboard.unassigned'),
    total: r.total,
  }))
  return (
    <ChartCard
      title={label}
      table={
        <table className="w-full text-left">
          <tbody>
            {data.map((r) => (
              <tr key={r.name} className="border-t border-slate-100 dark:border-slate-800">
                <td className="py-1">{r.name}</td>
                <td className="py-1 text-right tabular-nums">{r.total}</td>
              </tr>
            ))}
          </tbody>
        </table>
      }
    >
      {data.length === 0 ? (
        <p className="py-8 text-center text-sm text-slate-500">{t('dashboard.noData')}</p>
      ) : (
        <div style={{ height: Math.max(120, data.length * 36) }}>
          <ResponsiveContainer>
            <BarChart data={data} layout="vertical" margin={{ left: 8, right: 16 }}>
              <CartesianGrid horizontal={false} stroke="var(--viz-grid)" />
              <XAxis type="number" allowDecimals={false} {...axis} />
              <YAxis type="category" dataKey="name" width={120} {...axis} />
              <Tooltip contentStyle={tooltipStyle} itemStyle={tooltipItemStyle} cursor={{ fill: 'var(--viz-grid)', opacity: 0.4 }} formatter={(v) => [v, t('dashboard.employees')]} />
              <Bar dataKey="total" fill="var(--viz-neutral)" radius={[0, 4, 4, 0]} maxBarSize={20} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </ChartCard>
  )
}

export function DashboardPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()
  const orgKey = user?.current_organization?.id
  const [period, setPeriod] = useState<Period>('week')
  const [from, setFrom] = useState(todayYmd())
  const [to, setTo] = useState(todayYmd())
  const [institutionId, setInstitutionId] = useState<number | undefined>()
  const [departmentId, setDepartmentId] = useState<number | undefined>()
  const hasOrg = !!user?.current_organization
  const params = {
    period,
    ...(period === 'custom' ? { from, to } : {}),
    institution_id: institutionId,
    department_id: departmentId,
  }
  const q = useQuery({
    queryKey: ['dashboard', orgKey, params],
    queryFn: () => fetchDashboard(params),
    enabled: hasOrg,
    placeholderData: keepPreviousData,
  })
  const institutions = useQuery({
    queryKey: ['institution-options', orgKey],
    queryFn: fetchInstitutionOptions,
    enabled: hasOrg && can('institutions.view'),
  })
  const departments = useQuery({
    queryKey: ['department-options', orgKey],
    queryFn: fetchDepartmentOptions,
    enabled: hasOrg && can('departments.view'),
  })
  if (!user) return null

  const deptOptions = (departments.data ?? []).filter((d) => !institutionId || d.institution_id === institutionId)
  const d = q.data
  const trend =
    d?.charts.attendance_trend?.map((r) => ({
      ...r,
      // The API's `present` includes late and half-day; stack on-time + half-day so late is not counted twice.
      present: r.present - r.late,
      label: formatDate(r.date),
    })) ?? null
  const seriesLabel = (k: string) => t(`attendance.status.${k}`)

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">{t('dashboard.welcome', { name: user.name })}</h1>
          {user.current_organization ? (
            <p className="mt-1 text-sm font-medium text-brand-700">
              {t('dashboard.organization', {
                name: user.current_organization.name,
              })}
            </p>
          ) : null}
        </div>
        <Link to="/my/attendance" className="text-sm font-medium text-brand-700 hover:underline">
          {t('dashboard.myAttendanceLink')}
        </Link>
      </div>

      {hasOrg ? null : (
        <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {t('dashboard.noOrganization')}
        </div>
      )}

      {hasOrg ? (
        <>
          <div className="flex flex-wrap items-end gap-3" role="group" aria-label={t('dashboard.filters')}>
            <Field id="d-period" label={t('dashboard.period')}>
              <Select id="d-period" value={period} onChange={(e) => setPeriod(e.target.value as Period)}>
                {(['today', 'week', 'month', 'year', 'custom'] as const).map((p) => (
                  <option key={p} value={p}>
                    {t(`dashboard.periods.${p}`)}
                  </option>
                ))}
              </Select>
            </Field>
            {period === 'custom' ? (
              <>
                <Field id="d-from" label={t('leave.from')}>
                  <Input id="d-from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
                </Field>
                <Field id="d-to" label={t('leave.to')}>
                  <Input id="d-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
                </Field>
              </>
            ) : null}
            {can('institutions.view') ? (
              <Field id="d-inst" label={t('org.institution')}>
                <Select
                  id="d-inst"
                  value={institutionId ?? ''}
                  onChange={(e) => {
                    setInstitutionId(e.target.value ? Number(e.target.value) : undefined)
                    setDepartmentId(undefined)
                  }}
                >
                  <option value="">{t('dashboard.all')}</option>
                  {(institutions.data ?? []).map((i) => (
                    <option key={i.id} value={i.id}>
                      {i.name}
                    </option>
                  ))}
                </Select>
              </Field>
            ) : null}
            {can('departments.view') ? (
              <Field id="d-dept" label={t('people.department')}>
                <Select id="d-dept" value={departmentId ?? ''} onChange={(e) => setDepartmentId(e.target.value ? Number(e.target.value) : undefined)}>
                  <option value="">{t('dashboard.all')}</option>
                  {deptOptions.map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.name}
                    </option>
                  ))}
                </Select>
              </Field>
            ) : null}
          </div>

          {q.isPending ? <LoadingState /> : null}
          {q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
          {d ? (
            <>
              <section aria-label={t('dashboard.kpis')} className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {KPIS.filter((k) => d.kpis[k.key] !== null).map((k) => (
                  <Kpi key={k.key} label={t(`dashboard.kpi.${k.key}`)} value={d.kpis[k.key] as number} icon={k.icon} to={k.to} />
                ))}
              </section>
              {trend ? (
                <ChartCard
                  title={t('dashboard.trend', {
                    from: formatDate(d.from),
                    to: formatDate(d.to),
                  })}
                  table={
                    <table className="w-full text-left">
                      <thead>
                        <tr>
                          <th className="py-1">{t('attendance.date')}</th>
                          {SERIES.map((s) => (
                            <th key={s.key} className="py-1 text-right">
                              {seriesLabel(s.key)}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {trend.map((r) => (
                          <tr key={r.date} className="border-t border-slate-100 dark:border-slate-800">
                            <td className="py-1">{r.label}</td>
                            {SERIES.map((s) => (
                              <td key={s.key} className="py-1 text-right tabular-nums">
                                {r[s.key]}
                              </td>
                            ))}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  }
                >
                  <div className="h-64">
                    <ResponsiveContainer>
                      <BarChart data={trend} margin={{ left: -16, right: 8 }}>
                        <CartesianGrid vertical={false} stroke="var(--viz-grid)" />
                        <XAxis dataKey="label" {...axis} minTickGap={16} />
                        <YAxis allowDecimals={false} {...axis} />
                        <Tooltip contentStyle={tooltipStyle} itemStyle={tooltipItemStyle} cursor={{ fill: 'var(--viz-grid)', opacity: 0.4 }} />
                        <Legend wrapperStyle={{ fontSize: 12 }} formatter={(value) => <span style={{ color: 'var(--viz-ink)' }}>{value}</span>} />
                        {SERIES.map((s, i) => (
                          <Bar
                            key={s.key}
                            dataKey={s.key}
                            name={seriesLabel(s.key)}
                            stackId="a"
                            fill={s.color}
                            stroke="var(--viz-surface)"
                            strokeWidth={1}
                            maxBarSize={28}
                            radius={i === SERIES.length - 1 ? [4, 4, 0, 0] : 0}
                          />
                        ))}
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                </ChartCard>
              ) : null}
              <div className="grid gap-4 lg:grid-cols-2">
                {d.charts.by_department ? <TotalsChart rows={d.charts.by_department} label={t('dashboard.byDepartment')} /> : null}
                {d.charts.by_institution ? <TotalsChart rows={d.charts.by_institution} label={t('dashboard.byInstitution')} nullLabel={t('org.central')} /> : null}
              </div>
            </>
          ) : null}
        </>
      ) : null}
    </div>
  )
}
