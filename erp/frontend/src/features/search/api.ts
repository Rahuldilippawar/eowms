import { api } from '@/lib/api'

export const SEARCH_GROUPS = ['employees', 'students', 'letters', 'vehicles', 'renewals', 'store', 'it'] as const
export type SearchGroup = (typeof SEARCH_GROUPS)[number]
export interface SearchHit {
  id: number
  title: string
  subtitle: string | null
  url: string
}

export const searchAll = async (q: string) => (await api.get<{ data: Partial<Record<SearchGroup, SearchHit[]>> }>('/search', { params: { q } })).data.data
