import { api } from '@/lib/api'

export const ABOUT_KINDS = ['deity', 'sadguru', 'guide', 'contributor'] as const
export type AboutKind = (typeof ABOUT_KINDS)[number]
export interface AboutEntry {
  id: number
  kind: AboutKind
  title: string
  subtitle: string | null
  body: string | null
  sort_order: number
  is_active: boolean
  has_photo: boolean
  updated_at: string | null
}
export interface AboutInput {
  kind: AboutKind
  title: string
  subtitle: string
  body: string
  sort_order: number
  is_active: boolean
  photo: File | null
}

export const fetchAbout = async (all = false) => (await api.get<{ data: AboutEntry[]; meta: { can_manage: boolean } }>('/about', { params: all ? { all: 1 } : {} })).data

export async function saveAbout(id: number | null, v: AboutInput): Promise<AboutEntry> {
  const form = new FormData()
  form.append('kind', v.kind)
  form.append('title', v.title)
  form.append('subtitle', v.subtitle)
  form.append('body', v.body)
  form.append('sort_order', String(v.sort_order))
  form.append('is_active', v.is_active ? '1' : '0')
  if (v.photo) form.append('photo', v.photo)
  return (await api.post<{ data: AboutEntry }>(id ? `/about/${id}` : '/about', form)).data.data
}
export const deleteAbout = async (id: number) => {
  await api.delete(`/about/${id}`)
}
/** Cache-busted by the last change, so a new photo shows at once. */
export const aboutPhotoUrl = (e: AboutEntry) => `/api/v1/about/${e.id}/photo?v=${encodeURIComponent(e.updated_at ?? '')}`

export interface TvDisplay {
  id: number
  name: string
  is_active: boolean
  last_seen_at: string | null
  created_at: string | null
}
export interface TvScreen {
  organization: string
  now: string
  timezone: string
  staff: number
  present_today: number | null
  on_leave_today: number | null
  open_requests: number | null
  birthdays: string[]
  anniversaries: string[]
}
export const fetchTvDisplays = async () => (await api.get<{ data: TvDisplay[] }>('/tv-displays')).data.data
export const createTvDisplay = async (name: string) => (await api.post<{ data: { id: number; name: string; url: string } }>('/tv-displays', { name })).data.data
export const deleteTvDisplay = async (id: number) => {
  await api.delete(`/tv-displays/${id}`)
}
export const fetchTvScreen = async (token: string) => (await api.get<{ data: TvScreen }>(`/public/tv/${token}`)).data.data
