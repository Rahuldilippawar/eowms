import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { splitServerErrors } from '@/features/admin/serverErrors'
import { useAuth } from '@/features/auth/AuthProvider'
import { ActiveToggle } from '@/features/org/ActiveToggle'
import { fetchDepartmentOptions, fetchInstitutionOptions } from '@/features/org/api'
import { ListPage, type Column } from '@/features/org/ListPage'
import { CustomFieldInputs } from './CustomFieldInputs'
import { createStudent, fetchStudents, setStudentActive, updateStudent, type Student, type StudentInput } from './api'

const FIELDS = ['custom', 'student_code', 'name', 'institution_id', 'department_id', 'class_name', 'section', 'gender', 'date_of_birth', 'admission_date', 'guardian_name', 'guardian_phone'] as const

function initial(s?: Student): StudentInput {
  return {
    student_code: s?.student_code ?? '',
    name: s?.name ?? '',
    institution_id: s?.institution.id ?? 0,
    department_id: s?.department?.id ?? null,
    class_name: s?.class_name ?? '',
    section: s?.section ?? '',
    gender: s?.gender ?? null,
    date_of_birth: s?.date_of_birth ?? '',
    admission_date: s?.admission_date ?? '',
    guardian_name: s?.guardian_name ?? '',
    guardian_phone: s?.guardian_phone ?? '',
    custom: s?.custom ?? {},
  }
}

function StudentDialog({ open, onOpenChange, student }: { open: boolean; onOpenChange: (o: boolean) => void; student?: Student }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const orgKey = user?.current_organization?.id
  const [v, setV] = useState<StudentInput>(initial(student))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const institutions = useQuery({ queryKey: ['institution-options', orgKey], queryFn: fetchInstitutionOptions, enabled: open })
  const departments = useQuery({ queryKey: ['department-options', orgKey], queryFn: fetchDepartmentOptions, enabled: open })

  useEffect(() => {
    if (open) {
      setV(initial(student))
      setErrors({})
      setGeneral(null)
    }
  }, [open, student])

  const save = useMutation({
    mutationFn: () => {
      const n = (s: string | null) => (s && s.trim() ? s.trim() : null)
      const input = {
        ...v,
        student_code: v.student_code.trim(),
        name: v.name.trim(),
        class_name: n(v.class_name),
        section: n(v.section),
        date_of_birth: n(v.date_of_birth),
        admission_date: n(v.admission_date),
        guardian_name: n(v.guardian_name),
        guardian_phone: n(v.guardian_phone),
      }
      return student ? updateStudent(student.id, input) : createStudent(input)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['students'] })
      toast.success(t('form.saved'))
      onOpenChange(false)
    },
    onError: (e) => {
      const r = splitServerErrors(e, FIELDS, t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral(r.general)
    },
  })

  const submit = () => {
    const local: Record<string, string> = {}
    if (!v.student_code.trim()) local.student_code = t('form.required')
    if (!v.name.trim()) local.name = t('form.required')
    if (!v.institution_id) local.institution_id = t('form.required')
    setErrors(local)
    if (Object.keys(local).length === 0) save.mutate()
  }
  const set = (k: keyof StudentInput) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })
  const deptOptions = (departments.data ?? []).filter((d) => d.institution_id === v.institution_id)

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={student ? t('people.editStudent') : t('people.newStudent')}
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={submit}>
            {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {general ? (
          <p role="alert" className="text-sm text-red-600 sm:col-span-2">
            {general}
          </p>
        ) : null}
        <Field id="s-name" label={t('org.name')} error={errors.name}>
          <Input id="s-name" value={v.name} aria-invalid={!!errors.name || undefined} onChange={set('name')} />
        </Field>
        <Field id="s-code" label={t('people.studentCode')} error={errors.student_code}>
          <Input id="s-code" value={v.student_code} aria-invalid={!!errors.student_code || undefined} onChange={set('student_code')} />
        </Field>
        <Field id="s-inst" label={t('org.institution')} error={errors.institution_id}>
          <Select id="s-inst" value={v.institution_id || ''} aria-invalid={!!errors.institution_id || undefined} onChange={(e) => setV({ ...v, institution_id: Number(e.target.value), department_id: null })}>
            <option value="">{t('people.chooseInstitution')}</option>
            {(institutions.data ?? []).map((i) => (
              <option key={i.id} value={i.id}>
                {i.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="s-dept" label={t('people.department')} error={errors.department_id}>
          <Select id="s-dept" value={v.department_id ?? ''} onChange={(e) => setV({ ...v, department_id: e.target.value ? Number(e.target.value) : null })}>
            <option value="">—</option>
            {deptOptions.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="s-class" label={t('people.className')} error={errors.class_name}>
          <Input id="s-class" value={v.class_name ?? ''} onChange={set('class_name')} />
        </Field>
        <Field id="s-section" label={t('people.section')} error={errors.section}>
          <Input id="s-section" value={v.section ?? ''} onChange={set('section')} />
        </Field>
        <Field id="s-gender" label={t('people.gender')} error={errors.gender}>
          <Select id="s-gender" value={v.gender ?? ''} onChange={(e) => setV({ ...v, gender: (e.target.value || null) as StudentInput['gender'] })}>
            <option value="">—</option>
            <option value="female">{t('people.genders.female')}</option>
            <option value="male">{t('people.genders.male')}</option>
            <option value="other">{t('people.genders.other')}</option>
          </Select>
        </Field>
        <Field id="s-dob" label={t('people.dob')} error={errors.date_of_birth}>
          <Input id="s-dob" type="date" value={v.date_of_birth ?? ''} onChange={set('date_of_birth')} />
        </Field>
        <Field id="s-guardian" label={t('people.guardian')} error={errors.guardian_name}>
          <Input id="s-guardian" value={v.guardian_name ?? ''} onChange={set('guardian_name')} />
        </Field>
        <Field id="s-gphone" label={t('people.guardianPhone')} error={errors.guardian_phone}>
          <Input id="s-gphone" type="tel" value={v.guardian_phone ?? ''} onChange={set('guardian_phone')} />
        </Field>
        <CustomFieldInputs entity="student" values={v.custom ?? {}} onChange={(custom) => setV({ ...v, custom })} errors={errors} />
      </div>
    </Modal>
  )
}

export function StudentsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [editing, setEditing] = useState<Student | undefined>()
  const [open, setOpen] = useState(false)
  const columns: Column<Student>[] = [
    { key: 'name', header: t('org.name'), cell: (s) => s.name },
    { key: 'code', header: t('people.studentCode'), cell: (s) => <span className="font-mono text-xs">{s.student_code}</span> },
    { key: 'institution', header: t('org.institution'), cell: (s) => s.institution.name },
    { key: 'class', header: t('people.className'), cell: (s) => [s.class_name, s.section].filter(Boolean).join(' / ') || '—' },
    { key: 'status', header: t('org.status'), cell: (s) => <Badge tone={s.is_active ? 'green' : 'slate'}>{s.is_active ? t('org.active') : t('org.inactive')}</Badge> },
  ]
  const canEdit = can('students.edit')
  const canToggle = can('students.activate')

  return (
    <>
      <ListPage
        title={t('nav.students')}
        queryKey="students"
        fetcher={fetchStudents}
        columns={columns}
        emptyMessage={t('people.noStudents')}
        cardTitle={(s) => s.name}
        toolbar={
          can('students.create') ? (
            <Button
              onClick={() => {
                setEditing(undefined)
                setOpen(true)
              }}
            >
              <Plus aria-hidden className="size-4" />
              {t('people.newStudent')}
            </Button>
          ) : null
        }
        actions={
          canEdit || canToggle
            ? (s) => (
                <>
                  {canEdit ? (
                    <Button
                      variant="ghost"
                      className="min-h-8 px-2 py-1 text-xs"
                      aria-label={t('common.editNamed', { name: s.name })}
                      onClick={() => {
                        setEditing(s)
                        setOpen(true)
                      }}
                    >
                      {t('common.edit')}
                    </Button>
                  ) : null}
                  {canToggle ? <ActiveToggle name={s.name} isActive={s.is_active} queryKeys={['students']} onToggle={(next, reason) => setStudentActive(s.id, next, reason)} /> : null}
                </>
              )
            : undefined
        }
      />
      <StudentDialog open={open} onOpenChange={setOpen} student={editing} />
    </>
  )
}
