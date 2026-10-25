import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import mr from './locales/mr.json'
import en from './locales/en.json'

export const LANGUAGES = [
  { code: 'mr', label: 'मराठी' },
  { code: 'en', label: 'English' },
] as const

export type LanguageCode = (typeof LANGUAGES)[number]['code']

const STORAGE_KEY = 'erp.language'

function savedLanguage(): LanguageCode {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved === 'mr' || saved === 'en') return saved
  } catch {
    // storage blocked (private mode) — fall back to Marathi
  }
  return 'mr'
}

export function setLanguage(code: LanguageCode) {
  void i18n.changeLanguage(code)
  document.documentElement.lang = code
  try {
    localStorage.setItem(STORAGE_KEY, code)
  } catch {
    // ignore
  }
}

void i18n.use(initReactI18next).init({
  resources: { mr: { translation: mr }, en: { translation: en } },
  lng: savedLanguage(),
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
})

export default i18n
