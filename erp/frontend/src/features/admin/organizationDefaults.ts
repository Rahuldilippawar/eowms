import type { Organization, OrganizationInput } from './api'

export const ORG_FIELDS = ['name', 'short_name', 'code', 'address', 'phone', 'email', 'website', 'financial_year_start_month'] as const

export function emptyOrganization(o?: Organization): OrganizationInput {
  return {
    name: o?.name ?? '',
    short_name: o?.short_name ?? '',
    code: o?.code ?? '',
    address: o?.address ?? '',
    phone: o?.phone ?? '',
    email: o?.email ?? '',
    website: o?.website ?? '',
    financial_year_start_month: o?.financial_year_start_month ?? 4,
  }
}

/** Blank strings become null so optional fields are cleared on the server. */
export function toOrganizationPayload(v: OrganizationInput): OrganizationInput {
  const n = (s: string | null) => (s && s.trim() ? s.trim() : null)
  return { ...v, name: v.name.trim(), code: v.code.trim(), short_name: n(v.short_name), address: n(v.address), phone: n(v.phone), email: n(v.email), website: n(v.website) }
}
