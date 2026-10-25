import { forwardRef, type SelectHTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(({ className, ...props }, ref) => (
  <select
    ref={ref}
    className={cn(
      'block min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-600 focus:ring-2 focus:ring-brand-600/30 focus:outline-none aria-[invalid=true]:border-red-500 dark:border-slate-700 dark:bg-slate-900',
      className,
    )}
    {...props}
  />
))
Select.displayName = 'Select'
