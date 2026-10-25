import { AlertTriangle, CheckCircle2 } from 'lucide-react'
import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'

interface ToastContextValue {
  success: (message: string) => void
  /** For one-click actions with no form to show the error next to. */
  error: (message: string) => void
}

const ToastContext = createContext<ToastContextValue>({ success: () => {}, error: () => {} })

/** Success announcements, and errors of one-click actions (form errors are shown next to the form). */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [messages, setMessages] = useState<{ id: number; text: string; kind: 'success' | 'error' }[]>([])

  const push = useCallback((text: string, kind: 'success' | 'error') => {
    const id = Date.now() + Math.random()
    setMessages((m) => [...m, { id, text, kind }])
    setTimeout(() => setMessages((m) => m.filter((x) => x.id !== id)), kind === 'error' ? 7000 : 4000)
  }, [])
  const success = useCallback((text: string) => push(text, 'success'), [push])
  const error = useCallback((text: string) => push(text, 'error'), [push])

  return (
    <ToastContext.Provider value={{ success, error }}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4">
        {messages.map((m) => (
          <div
            key={m.id}
            role={m.kind === 'error' ? 'alert' : 'status'}
            className="pointer-events-auto flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white shadow-lg"
          >
            {m.kind === 'error' ? <AlertTriangle aria-hidden className="size-4 text-amber-400" /> : <CheckCircle2 aria-hidden className="size-4 text-emerald-400" />}
            {m.text}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export function useToast() {
  return useContext(ToastContext)
}
