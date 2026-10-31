import { api, ensureCsrfCookie } from '@/lib/api'
import type { CurrentUser } from './api'

/** Browser side of fingerprint / face / device-PIN keys (erp/docs/WEBAUTHN.md). */

type Json = Record<string, unknown>

export function webauthnSupported(): boolean {
  return typeof window !== 'undefined' && window.isSecureContext && typeof window.PublicKeyCredential === 'function' && !!navigator.credentials
}

function toBytes(b64url: string): ArrayBuffer {
  const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (b64url.length % 4)) % 4)
  const raw = atob(b64)
  const out = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i)
  return out.buffer
}

function toB64url(buf: ArrayBuffer): string {
  let s = ''
  for (const b of new Uint8Array(buf)) s += String.fromCharCode(b)
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

const listOf = (items: unknown) => ((items as { id: string; type: 'public-key' }[]) ?? []).map((c) => ({ ...c, id: toBytes(c.id) }))

/** Asks the device to make a key; the answer, ready to send to the server. */
export async function createKey(options: Json): Promise<Json> {
  const user = options.user as { id: string; name: string; displayName: string }
  const cred = (await navigator.credentials.create({
    publicKey: {
      ...(options as unknown as PublicKeyCredentialCreationOptions),
      challenge: toBytes(options.challenge as string),
      user: { ...user, id: toBytes(user.id) },
      excludeCredentials: listOf(options.excludeCredentials),
    },
  })) as PublicKeyCredential
  const r = cred.response as AuthenticatorAttestationResponse
  return {
    id: cred.id,
    rawId: toB64url(cred.rawId),
    type: cred.type,
    response: { clientDataJSON: toB64url(r.clientDataJSON), attestationObject: toB64url(r.attestationObject), transports: r.getTransports?.() ?? [] },
  }
}

/** Asks the device to sign the server's challenge with one of the person's keys. */
export async function signChallenge(options: Json): Promise<Json> {
  const cred = (await navigator.credentials.get({
    publicKey: {
      ...(options as unknown as PublicKeyCredentialRequestOptions),
      challenge: toBytes(options.challenge as string),
      allowCredentials: listOf(options.allowCredentials),
    },
  })) as PublicKeyCredential
  const r = cred.response as AuthenticatorAssertionResponse
  return {
    id: cred.id,
    rawId: toB64url(cred.rawId),
    type: cred.type,
    response: {
      clientDataJSON: toB64url(r.clientDataJSON),
      authenticatorData: toB64url(r.authenticatorData),
      signature: toB64url(r.signature),
      userHandle: r.userHandle ? toB64url(r.userHandle) : null,
    },
  }
}

export interface DeviceKey {
  id: number
  name: string
  created_at: string | null
  last_used_at: string | null
}

export const fetchKeys = async () => (await api.get<{ data: DeviceKey[] }>('/me/webauthn')).data.data
export const deleteKey = async (id: number) => (await api.delete<{ data: DeviceKey[] }>(`/me/webauthn/${id}`)).data.data

export async function addKey(name: string, password: string): Promise<DeviceKey[]> {
  const options = (await api.post<{ data: Json }>('/me/webauthn/register/options')).data.data
  const credential = await createKey(options)
  return (await api.post<{ data: DeviceKey[] }>('/me/webauthn/register', { name, password, credential })).data.data
}

export async function signInWithKey(): Promise<CurrentUser> {
  await ensureCsrfCookie()
  const options = (await api.post<{ data: Json }>('/auth/webauthn/options')).data.data
  const credential = await signChallenge(options)
  return (await api.post<{ data: CurrentUser }>('/auth/webauthn/login', { credential })).data.data
}

/** A fingerprint check of the signed-in person, to send along with an action (e.g. signing a letter). */
export async function confirmWithKey(): Promise<Json> {
  const options = (await api.post<{ data: Json }>('/me/webauthn/confirm/options')).data.data
  return signChallenge(options)
}
