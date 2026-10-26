import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ImageUp, Trash2, X } from 'lucide-react'
import { useEffect, useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { actionErrorMessage } from '@/lib/forms'
import { deleteWorkPhoto, searchColleagues, uploadWorkPhoto, workPhotoUrl, type Colleague, type PhotoKind, type WorkEntry } from './api'

const MAX_TEAM = 20

/** Colleagues who did the work together with the employee (searched by name or code). */
export function TeamPicker({ value, onChange, error }: { value: Colleague[]; onChange: (team: Colleague[]) => void; error?: string }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const id = useId()
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), 300)
    return () => clearTimeout(timer)
  }, [search])
  const results = useQuery({
    queryKey: ['work-colleagues', user?.current_organization?.id, debounced],
    queryFn: () => searchColleagues(debounced),
    enabled: debounced.length >= 2,
  })
  const options = (results.data ?? []).filter((c) => !value.some((v) => v.id === c.id))

  return (
    <div className="space-y-2">
      <label htmlFor={id} className="block text-sm font-medium">
        {t('work.team')}
      </label>
      {value.length ? (
        <ul className="flex flex-wrap gap-2" aria-label={t('work.team')}>
          {value.map((c) => (
            <li key={c.id} className="inline-flex items-center gap-1 rounded-full bg-brand-50 py-1 pr-1 pl-3 text-xs text-brand-800 dark:bg-slate-800 dark:text-slate-200">
              {c.name}
              <button
                type="button"
                className="rounded-full p-1 hover:bg-brand-100 dark:hover:bg-slate-700"
                aria-label={t('work.removeMember', { name: c.name })}
                onClick={() => onChange(value.filter((v) => v.id !== c.id))}
              >
                <X aria-hidden className="size-3" />
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      {value.length < MAX_TEAM ? (
        <Input id={id} value={search} placeholder={t('work.teamSearch')} aria-invalid={!!error || undefined} onChange={(e) => setSearch(e.target.value)} />
      ) : null}
      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      {debounced.length >= 2 ? (
        results.isPending ? (
          <Spinner label={t('common.loading')} />
        ) : options.length ? (
          <ul className="max-h-48 divide-y divide-slate-100 overflow-auto rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
            {options.map((c) => (
              <li key={c.id}>
                <button
                  type="button"
                  className="w-full px-3 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800"
                  onClick={() => {
                    onChange([...value, c])
                    setSearch('')
                  }}
                >
                  {t('work.addMember', { name: c.name })} <span className="font-mono text-xs text-slate-500">{c.employee_code}</span>
                  {c.department ? <span className="text-xs text-slate-500"> · {c.department}</span> : null}
                </button>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-slate-500">{t('work.noColleagues')}</p>
        )
      ) : null}
    </div>
  )
}

/** Before / after photos of an entry; the owner can add or remove them while the entry is editable. */
export function WorkPhotos({ entry, editable }: { entry: WorkEntry; editable: boolean }) {
  const kinds = (['before', 'after'] as const).filter((k) => editable || entry[`has_${k}_photo`])
  if (!kinds.length) return null
  return (
    <div className="mt-2 flex flex-wrap gap-3">
      {kinds.map((k) => (
        <PhotoSlot key={k} entry={entry} kind={k} editable={editable} />
      ))}
    </div>
  )
}

function PhotoSlot({ entry, kind, editable }: { entry: WorkEntry; kind: PhotoKind; editable: boolean }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const id = useId()
  const has = !!entry[`has_${kind}_photo`]
  const label = t(`work.photo.${kind}`)
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['my-work'] })
  const upload = useMutation({
    mutationFn: (file: File) => uploadWorkPhoto(entry.id, kind, file),
    onSuccess: () => {
      refresh()
      toast.success(t('work.photo.saved'))
    },
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  const remove = useMutation({
    mutationFn: () => deleteWorkPhoto(entry.id, kind),
    onSuccess: refresh,
    onError: (e) => toast.error(actionErrorMessage(e, t('common.actionFailed'))),
  })
  // The photo URL stays the same after a new upload; the upload time makes the browser fetch it again.
  const src = `${workPhotoUrl(entry.id, kind)}?v=${upload.submittedAt || 0}`

  return (
    <figure className="w-32 text-xs">
      {has ? (
        <a href={src} target="_blank" rel="noreferrer">
          <img src={src} alt={t('work.photo.alt', { kind: label, title: entry.title })} className="h-24 w-32 rounded-lg border border-slate-200 object-cover dark:border-slate-700" loading="lazy" />
        </a>
      ) : (
        <div className="flex h-24 w-32 items-center justify-center rounded-lg border border-dashed border-slate-300 text-slate-400 dark:border-slate-700">{t('work.photo.none')}</div>
      )}
      <figcaption className="mt-1 flex items-center justify-between gap-1">
        <span>{label}</span>
        {editable ? (
          <span className="flex items-center gap-1">
            {upload.isPending || remove.isPending ? <Spinner label={t('common.saving')} /> : null}
            <label htmlFor={id} className="cursor-pointer rounded p-1 text-brand-700 hover:bg-slate-100 dark:hover:bg-slate-800" title={t('work.photo.upload', { kind: label })}>
              <ImageUp aria-hidden className="size-4" />
              <span className="sr-only">{t('work.photo.upload', { kind: label })}</span>
            </label>
            <input
              id={id}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="sr-only"
              onChange={(e) => {
                const f = e.target.files?.[0]
                if (f) upload.mutate(f)
                e.target.value = ''
              }}
            />
            {has ? (
              <Button variant="ghost" className="min-h-7 px-1 py-0.5 text-red-700" aria-label={t('work.photo.remove', { kind: label })} onClick={() => remove.mutate()}>
                <Trash2 aria-hidden className="size-4" />
              </Button>
            ) : null}
          </span>
        ) : null}
      </figcaption>
    </figure>
  )
}
