import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/data/ListStates'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { createRole, fetchPermissionCatalog, fetchRole, scopeBreadth, SCOPES, updateRole, type Scope } from './api'
import { splitServerErrors } from './serverErrors'

/**
 * Permission matrix: one row per permission, a checkbox to grant it and the
 * scope it applies at. Options the editor cannot grant (not held, or wider
 * than their own scope) are disabled here and refused by the API anyway.
 */
export function RoleEditorPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const isNew = id === 'new'
  const roleId = isNew ? 0 : Number(id)
  const navigate = useNavigate()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { user, can } = useAuth()
  const orgKey = user?.current_organization?.id
  const readOnly = isNew ? !can('roles.create') : !can('roles.edit')

  const catalog = useQuery({ queryKey: ['permission-catalog', orgKey], queryFn: fetchPermissionCatalog })
  const role = useQuery({ queryKey: ['role', orgKey, roleId], queryFn: () => fetchRole(roleId), enabled: !isNew })

  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [grants, setGrants] = useState<Record<string, Scope>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  useEffect(() => {
    if (role.data) {
      setName(role.data.name)
      setDescription(role.data.description ?? '')
      setGrants(Object.fromEntries((role.data.permissions ?? []).map((p) => [p.key, p.scope])))
    }
  }, [role.data])

  const save = useMutation({
    mutationFn: () => {
      const input = { name: name.trim(), description: description.trim() || null, permissions: Object.entries(grants).map(([key, scope]) => ({ key, scope })) }
      return isNew ? createRole(input) : updateRole(roleId, input)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['roles'] })
      void queryClient.invalidateQueries({ queryKey: ['role'] })
      toast.success(t('form.saved'))
      navigate('/roles')
    },
    onError: (e) => {
      const r = splitServerErrors(e, ['name', 'description', 'permissions'], t('common.actionFailed'))
      setErrors(r.byField)
      setGeneral([r.general, r.byField.permissions].filter(Boolean).join(' ') || null)
    },
  })

  if (catalog.isPending || (!isNew && role.isPending)) return <LoadingState />
  if (catalog.isError || role.isError) return <ErrorState onRetry={() => void (catalog.isError ? catalog.refetch() : role.refetch())} />

  const canGrant = (mine: Scope | null, scope: Scope) => user?.is_super_admin || (mine !== null && scopeBreadth(mine) >= scopeBreadth(scope))

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <Link to="/roles" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
        <ArrowLeft aria-hidden className="size-4" />
        {t('nav.roles')}
      </Link>
      <h1 className="text-2xl font-semibold">{isNew ? t('admin.newRole') : (role.data?.name ?? '')}</h1>

      <form
        noValidate
        className="space-y-5"
        onSubmit={(e) => {
          e.preventDefault()
          if (!name.trim()) {
            setErrors({ name: t('form.required') })
            return
          }
          save.mutate()
        }}
      >
        {general ? (
          <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {general}
          </p>
        ) : null}
        <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2 dark:border-slate-800 dark:bg-slate-900">
          <Field id="role-name" label={t('org.name')} error={errors.name}>
            <Input id="role-name" value={name} disabled={readOnly} onChange={(e) => setName(e.target.value)} />
          </Field>
          <Field id="role-desc" label={t('admin.description')} error={errors.description}>
            <Input id="role-desc" value={description} disabled={readOnly} onChange={(e) => setDescription(e.target.value)} />
          </Field>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <h2 className="font-semibold">{t('admin.permissions')}</h2>
          <p className="mb-4 text-sm text-slate-500">{t('admin.permissionsHint')}</p>
          <div className="space-y-5">
            {catalog.data.map((mod) => (
              <fieldset key={mod.module}>
                <legend className="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-300">{t(`modules.${mod.module}`, mod.module)}</legend>
                <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                  {mod.permissions.map((p) => {
                    const granted = p.key in grants
                    const grantable = canGrant(p.my_scope, 'own')
                    return (
                      <li key={p.key} className="flex flex-col gap-2 px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
                        <label className="flex items-center gap-2 text-sm">
                          <input
                            type="checkbox"
                            checked={granted}
                            disabled={readOnly || (!granted && !grantable)}
                            onChange={(e) =>
                              setGrants((g) => {
                                const next = { ...g }
                                if (e.target.checked) next[p.key] = user?.is_super_admin ? 'organization' : (p.my_scope ?? 'own')
                                else delete next[p.key]
                                return next
                              })
                            }
                          />
                          {t(`actions.${p.action}`, p.action)}
                          <span className="font-mono text-xs text-slate-400">{p.key}</span>
                        </label>
                        {granted ? (
                          <Select
                            aria-label={t('admin.scopeFor', { permission: p.key })}
                            className="sm:w-48"
                            value={grants[p.key]}
                            disabled={readOnly}
                            onChange={(e) => setGrants((g) => ({ ...g, [p.key]: e.target.value as Scope }))}
                          >
                            {SCOPES.map((s) => (
                              <option key={s} value={s} disabled={!canGrant(p.my_scope, s)}>
                                {t(`scopes.${s}`)}
                              </option>
                            ))}
                          </Select>
                        ) : null}
                      </li>
                    )
                  })}
                </ul>
              </fieldset>
            ))}
          </div>
        </section>

        {readOnly ? null : (
          <div className="flex justify-end gap-2">
            <Button asChild variant="secondary">
              <Link to="/roles">{t('common.cancel')}</Link>
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {save.isPending ? <Spinner label={t('common.saving')} /> : t('common.save')}
            </Button>
          </div>
        )}
      </form>
    </div>
  )
}
