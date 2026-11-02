import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2, UserRound } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/ListStates'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { useAuth } from '@/features/auth/AuthProvider'
import { parseApiError } from '@/lib/api'
import { ABOUT_KINDS, aboutPhotoUrl, deleteAbout, fetchAbout, saveAbout, type AboutEntry, type AboutInput, type AboutKind } from './api'

function EntryDialog({ entry, kind, onClose, onSaved }: { entry: AboutEntry | null; kind: AboutKind; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const [v, setV] = useState<AboutInput>({
    kind: entry?.kind ?? kind, title: entry?.title ?? '', subtitle: entry?.subtitle ?? '', body: entry?.body ?? '',
    sort_order: entry?.sort_order ?? 0, is_active: entry?.is_active ?? true, photo: null,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  const save = async () => {
    setBusy(true)
    setErrors({})
    try {
      await saveAbout(entry?.id ?? null, v)
      onSaved()
    } catch (e) {
      const err = parseApiError(e)
      setErrors(Object.fromEntries(Object.entries(err.errors ?? { title: [err.message ?? t('common.actionFailed')] }).map(([k, m]) => [k, m[0]])))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal
      open
      onOpenChange={(o) => !o && !busy && onClose()}
      title={entry ? t('about.edit') : t('about.add')}
      footer={
        <>
          <Button variant="secondary" disabled={busy} onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={busy || !v.title.trim()} onClick={() => void save()}>
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Field id="ab-kind" label={t('about.kind')}>
          <Select id="ab-kind" value={v.kind} onChange={(e) => setV({ ...v, kind: e.target.value as AboutKind })}>
            {ABOUT_KINDS.map((k) => (
              <option key={k} value={k}>
                {t(`about.kinds.${k}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field id="ab-title" label={v.kind === 'contributor' ? t('about.name') : t('about.title')} error={errors.title}>
          <Input id="ab-title" value={v.title} maxLength={150} onChange={(e) => setV({ ...v, title: e.target.value })} />
        </Field>
        <Field id="ab-subtitle" label={v.kind === 'contributor' ? t('about.contribution') : t('about.subtitle')} error={errors.subtitle}>
          <Input id="ab-subtitle" value={v.subtitle} maxLength={200} onChange={(e) => setV({ ...v, subtitle: e.target.value })} />
        </Field>
        <Field id="ab-body" label={t('about.body')} error={errors.body}>
          <Textarea id="ab-body" rows={4} maxLength={5000} value={v.body} onChange={(e) => setV({ ...v, body: e.target.value })} />
        </Field>
        <Field id="ab-photo" label={t('about.photo')} error={errors.photo}>
          <Input id="ab-photo" type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => setV({ ...v, photo: e.target.files?.[0] ?? null })} />
        </Field>
        <div className="flex flex-wrap items-end gap-4">
          <div className="w-28">
            <Field id="ab-order" label={t('about.order')}>
              <Input id="ab-order" type="number" min={0} value={v.sort_order} onChange={(e) => setV({ ...v, sort_order: Number(e.target.value) || 0 })} />
            </Field>
          </div>
          <label className="flex min-h-10 items-center gap-2 text-sm">
            <input type="checkbox" checked={v.is_active} onChange={(e) => setV({ ...v, is_active: e.target.checked })} />
            {t('about.shown')}
          </label>
        </div>
      </div>
    </Modal>
  )
}

/** The organization's About page (erp/docs/ABOUT-AND-TV.md). */
export function AboutPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const qc = useQueryClient()
  const [manage, setManage] = useState(false)
  const q = useQuery({ queryKey: ['about', user?.current_organization?.id, manage], queryFn: () => fetchAbout(manage) })
  const [editing, setEditing] = useState<{ entry: AboutEntry | null; kind: AboutKind } | null>(null)
  const [removing, setRemoving] = useState<AboutEntry | null>(null)
  const refresh = () => qc.invalidateQueries({ queryKey: ['about'] })

  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const groups: [string, AboutKind[]][] = [
    ['revered', ['deity', 'sadguru']],
    ['guides', ['guide']],
    ['contributors', ['contributor']],
  ]
  return (
    <div className="mx-auto max-w-5xl space-y-8">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{t('nav.about')}</h1>
          <p className="text-sm text-slate-500">{user?.current_organization?.name}</p>
        </div>
        {q.data.meta.can_manage ? (
          <div className="flex gap-2">
            <Button variant={manage ? 'primary' : 'secondary'} onClick={() => setManage(!manage)}>
              {manage ? t('about.done') : t('about.manage')}
            </Button>
            {manage ? (
              <Button className="gap-2" onClick={() => setEditing({ entry: null, kind: 'contributor' })}>
                <Plus aria-hidden className="size-4" />
                {t('about.add')}
              </Button>
            ) : null}
          </div>
        ) : null}
      </div>
      {q.data.data.length === 0 ? <EmptyState message={t('about.empty')} /> : null}
      {groups.map(([key, kinds]) => {
        const items = q.data.data.filter((e) => kinds.includes(e.kind))
        if (items.length === 0) return null
        return (
          <section key={key} aria-labelledby={`about-${key}`} className="space-y-4">
            <h2 id={`about-${key}`} className="text-lg font-semibold">
              {t(`about.groups.${key}`)}
            </h2>
            <ul className={key === 'contributors' ? 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3' : 'grid gap-4 md:grid-cols-2'}>
              {items.map((e) => (
                <li key={e.id} className="flex gap-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                  {e.has_photo ? (
                    <img src={aboutPhotoUrl(e)} alt={e.title} className="size-20 shrink-0 rounded-xl object-cover" loading="lazy" />
                  ) : (
                    <span className="grid size-20 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-400 dark:bg-slate-800">
                      <UserRound aria-hidden className="size-8" />
                    </span>
                  )}
                  <div className="min-w-0 flex-1">
                    <p className="font-semibold">
                      {e.title} {!e.is_active ? <Badge>{t('about.hidden')}</Badge> : null}
                    </p>
                    {e.kind !== 'contributor' ? <p className="text-xs text-brand-700">{t(`about.kinds.${e.kind}`)}</p> : null}
                    {e.subtitle ? <p className="text-sm text-slate-600 dark:text-slate-400">{e.subtitle}</p> : null}
                    {e.body ? <p className="mt-1 text-sm whitespace-pre-line">{e.body}</p> : null}
                    {manage ? (
                      <div className="mt-2 flex gap-1">
                        <Button variant="ghost" className="px-2" aria-label={t('about.editNamed', { name: e.title })} onClick={() => setEditing({ entry: e, kind: e.kind })}>
                          <Pencil aria-hidden className="size-4" />
                        </Button>
                        <Button variant="ghost" className="px-2" aria-label={t('about.deleteNamed', { name: e.title })} onClick={() => setRemoving(e)}>
                          <Trash2 aria-hidden className="size-4" />
                        </Button>
                      </div>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          </section>
        )
      })}
      {editing ? (
        <EntryDialog
          entry={editing.entry}
          kind={editing.kind}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            void refresh()
          }}
        />
      ) : null}
      <ConfirmDialog
        open={removing !== null}
        onOpenChange={(o) => !o && setRemoving(null)}
        title={t('about.deleteTitle')}
        body={removing?.title ?? ''}
        confirmLabel={t('common.delete')}
        danger
        onConfirm={async () => {
          await deleteAbout(removing!.id)
          await refresh()
        }}
      />
    </div>
  )
}
