/** Browser side of Web Push (erp/docs/NOTIFICATIONS.md). */

export type PushState = 'unsupported' | 'blocked' | 'off' | 'on'

export function pushSupported(): boolean {
  return typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

async function registration(): Promise<ServiceWorkerRegistration> {
  return (await navigator.serviceWorker.getRegistration('/')) ?? navigator.serviceWorker.register('/sw.js')
}

export async function currentPush(): Promise<{ state: PushState; endpoint: string | null }> {
  if (!pushSupported()) return { state: 'unsupported', endpoint: null }
  if (Notification.permission === 'denied') return { state: 'blocked', endpoint: null }
  const sub = await (await registration()).pushManager.getSubscription()
  return { state: sub ? 'on' : 'off', endpoint: sub?.endpoint ?? null }
}

function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const b64 = base64url.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (base64url.length % 4)) % 4)
  const raw = atob(b64)
  const out = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i)
  return out
}

/** Asks the browser for permission and a subscription; null when the person said no. */
export async function enablePush(serverKey: string): Promise<PushSubscriptionJSON | null> {
  if ((await Notification.requestPermission()) !== 'granted') return null
  const reg = await registration()
  const sub = (await reg.pushManager.getSubscription()) ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(serverKey) }))
  return sub.toJSON()
}

export async function disablePush(): Promise<string | null> {
  const sub = await (await registration()).pushManager.getSubscription()
  if (!sub) return null
  await sub.unsubscribe()
  return sub.endpoint
}
