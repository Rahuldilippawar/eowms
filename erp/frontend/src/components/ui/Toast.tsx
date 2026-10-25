import { CheckCircle2 } from 'lucide-react'
import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'

interface ToastContextValue {
  success: (message: string) => void
}

const ToastContext = createContext<ToastContextValue>({ success: () => {} })

/** Polite success announcements (errors are shown next to the form that caused them). */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [messages, setMessages] = useState<{ id: number; text: string }[]>([])

  const success = useCallback((text: string) => {
    const id = Date.now() + Math.random()
    setMessages((m) => [...m, { id, text }])
    setTimeout(() => setMessages((m) => m.filter((x) => x.id !== id)), 4000)
  }, [])

  return (
    <ToastContext.Provider value={{ success }}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4">
        {messages.map((m) => (
          <div key={m.id} role="status" className="pointer-events-auto flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white shadow-lg">
            <CheckCircle2 aria-hidden className="size-4 text-emerald-400" />
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
