# About page, notice-board screens and the welcome tour (Phase 11c)

## About page (`/about`)

The old app's "About" page, per organization: revered deity, Sadguru and guides (the old
`about_sections`), then the people who contributed (the old `about_contributors`), each with an
optional photo, text, order and a shown / hidden switch.

- Everyone in the organization reads it. Organization-wide `organizations.edit` (the organization
  admin) edits it ("Edit page"). Photos (JPEG / PNG / WebP up to 3 MB) are stored privately and served
  only to people of the organization. Changes are in the activity log.

## Notice-board screens (`/organization/tv`, the screen at `/tv/<secret>`)

The old `tv-display.php`, without needing anyone signed in on the screen.

- An organization admin names a screen and gets a secret link (shown once; only its SHA-256 is
  stored). Open it in the screen's browser (full screen / kiosk mode). Deleting the screen stops the
  link at once; the list shows when each screen last loaded.
- The screen shows the organization's name, a clock in its time zone, staff, present today, on leave
  today and open work requests (each only while its module is on), and today's birthdays and work
  anniversaries (names only). It refreshes every minute. No phone numbers, records or other details
  are ever shown on it. (Guests and events, which the old screen also listed, arrive with their
  modules in Phase 12.)

## Welcome tour

Shown once after a person's first sign-in (six short steps: menu, search, notifications, account,
language); "Skip" or finishing records it. It can be opened again from the account menu
("Welcome tour").
