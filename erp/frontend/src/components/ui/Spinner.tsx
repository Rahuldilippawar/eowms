import { Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'

export function Spinner({ className, label }: { className?: string; label?: string }) {
  return (
    <span role="status" className="inline-flex items-center gap-2">
      <Loader2 aria-hidden className={cn('size-4 animate-spin', className)} />
      {label ? <span>{label}</span> : <span className="sr-only">Loading</span>}
    </span>
  )
}
