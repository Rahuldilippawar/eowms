# Notifications: in-app, Web Push, e-mail, and the installable app (Phase 11a)

In-app notifications (the bell, Phase 5) are unchanged. Phase 11a delivers the same notifications
to phones and computers (Web Push) and by e-mail, and makes the app installable.

## Installable app (PWA)

- `public/manifest.webmanifest` (name, colours, the old app's icons), `public/sw.js` (service
  worker at the site root) and `public/offline.html`.
- The service worker only shows the offline page when a page cannot load, and shows push
  notifications. It does not cache the app or any API answer, so people always see current data.
- On Android / desktop Chrome the browser offers "Install". On an iPhone (iOS 16.4+) the app must be
  added to the home screen before it can receive push.

## Each person's settings

**Notifications → Settings** (`/notifications/settings`).

- **This device**: turn push on or off for the phone / computer in hand, and send a test.
  Devices the push service no longer knows are removed automatically.
- **What to send where**: for each group (tasks, daily work, leave, attendance and celebrations,
  letters, renewals, work requests, grievances, vehicles, hostels, staff housing, assets and store,
  IT, CCTV, other) push on/off and e-mail on/off. Defaults: push on for everything; e-mail only for
  renewals (as the old app's renewal reminder e-mails).
- **Language**: push and e-mail are written in the language the person last chose in the app.

## Delivery

| What | Command | When |
|---|---|---|
| Web Push | `erp:deliver-push` | every minute; notifications older than 6 hours are not pushed |
| E-mail | `erp:email-notifications` | hourly (at :17), one summary e-mail per person; looks back 26 hours |

Both need the scheduler cron (`* * * * * php artisan schedule:run`, see `WORK-AND-TASKS.md`).

- Push is encrypted for each device (RFC 8291) and signed with the server's VAPID key (RFC 8292),
  using PHP's own openssl: no extra PHP extensions or libraries.
- Texts come from the app's translations. After changing a notification text in
  `src/i18n/locales/*.json`, run `npm run export:notification-texts` in `erp/frontend` (a test
  fails while the copy in `backend/resources/notification-texts` is out of date).
- A notification is marked when the run has handled it, so nobody gets the same one twice.

## Server setup

1. `php artisan erp:vapid` prints a key pair. Put it in the server's `.env` as `VAPID_PUBLIC_KEY`,
   `VAPID_PRIVATE_KEY`, and `VAPID_SUBJECT=mailto:<an address of the trust>`. Keep the private key
   secret (never in git). Changing the keys later turns push off on every device until each person
   turns it on again. Until the keys are set, push is off and the settings page says so.
2. E-mail uses Laravel's mail settings (`MAIL_MAILER`, `MAIL_HOST`, ... in `.env`, e.g. the Hostinger
   mailbox's SMTP). With `MAIL_MAILER=log` nothing is sent.
3. Push needs HTTPS (any browser refuses it on plain HTTP, except on localhost).
