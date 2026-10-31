// Copies the notification texts from the app's translations to the backend, which uses them for
// Web Push and e-mail (erp/docs/NOTIFICATIONS.md). Run from erp/frontend after changing them:
//   npm run export:notification-texts
// A test fails while the copy is out of date.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'

export function notificationTexts(lang, root = process.cwd()) {
  const all = JSON.parse(readFileSync(`${root}/src/i18n/locales/${lang}.json`, 'utf8'))
  return {
    types: all.notifications.types,
    generic: all.notifications.generic,
    app: all.app.name,
    requestStatus: all.requests.status,
    grievanceStatus: all.grievances.status,
    hostelSession: all.hostels.session,
    emailSubject: all.notifications.emailSubject,
    emailIntro: all.notifications.emailIntro,
    emailFooter: all.notifications.emailFooter,
  }
}

if (process.argv[1]?.endsWith('export-notification-texts.mjs')) {
  const out = `${process.cwd()}/../backend/resources/notification-texts`
  mkdirSync(out, { recursive: true })
  for (const lang of ['en', 'mr']) writeFileSync(`${out}/${lang}.json`, `${JSON.stringify(notificationTexts(lang), null, 2)}\n`)
  console.log('Notification texts exported.')
}
