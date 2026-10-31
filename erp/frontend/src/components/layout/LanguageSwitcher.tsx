import { useTranslation } from 'react-i18next'
import { useAuth } from '@/features/auth/AuthProvider'
import { saveNotificationSettings } from '@/features/notifications/settingsApi'
import { LANGUAGES, setLanguage, type LanguageCode } from '@/i18n'

export function LanguageSwitcher() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  return (
    <label className="inline-flex items-center gap-2 text-sm">
      <span className="sr-only">{t('common.language')}</span>
      <select
        value={i18n.language}
        onChange={(e) => {
          const code = e.target.value as LanguageCode
          setLanguage(code)
          // Push and e-mail are written in the language the person last chose.
          if (user) void saveNotificationSettings({ locale: code }).catch(() => undefined)
        }}
        className="min-h-9 rounded-lg border border-slate-300 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        {LANGUAGES.map((lang) => (
          <option key={lang.code} value={lang.code}>
            {lang.label}
          </option>
        ))}
      </select>
    </label>
  )
}
