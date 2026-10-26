import { useTranslation } from 'react-i18next'

/** ISO weekday checkboxes (1 = Monday … 7 = Sunday). */
export function WeeklyOffs({ value, onChange, disabled, idPrefix }: { value: number[]; onChange: (v: number[]) => void; disabled?: boolean; idPrefix: string }) {
  const { t } = useTranslation()
  return (
    <fieldset>
      <legend className="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">{t('rules.weeklyOffs')}</legend>
      <div className="flex flex-wrap gap-3">
        {[1, 2, 3, 4, 5, 6, 7].map((d) => (
          <label key={d} htmlFor={`${idPrefix}-wo-${d}`} className="flex items-center gap-1.5 text-sm">
            <input
              id={`${idPrefix}-wo-${d}`}
              type="checkbox"
              disabled={disabled}
              checked={value.includes(d)}
              onChange={(e) => onChange(e.target.checked ? [...value, d].sort() : value.filter((x) => x !== d))}
            />
            {t(`rules.days.${d}`)}
          </label>
        ))}
      </div>
    </fieldset>
  )
}
