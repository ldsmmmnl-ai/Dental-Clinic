# Design pass — what changed

## Run this first (fixes a crash)
`database/migration_dental_record_visits.sql` — creates the `dental_record_visits` table and the
`bills.dental_record_id` column. They were missing from `cap.sql`, so the **Patient Profile page
returned HTTP 500 on a fresh install**. Safe to re-run on a database that already has them.

## Login page
- New split layout: navy→teal brand panel + clean form (`assets/css/login.css`). The mountain photo is gone.
- Same palette as the app (navy, teal, gold). Works in light, dark and mobile; respects reduced-motion.
- One motion moment: the patient visit flow lights up once, then stays still.
- Forgot-password / OTP / reset screens share the new layout.

## Self-hosted (no CDN, works offline, faster first paint)
- Fonts: DM Sans (body) + Outfit (headings) in `assets/fonts/`, declared in `assets/css/fonts.css`.
  Replaces the Google Fonts + Fontshare `@import`s. (Outfit replaces Clash Display.)
- Bootstrap 5.3.0, Bootstrap Icons 1.10.0, Chart.js 4.4.0 now served from `assets/`.
  `includes/head.php` already used local Bootstrap when present.
- Still on a CDN: html2pdf and the QR library (print / payment pages only).

## Colour consistency
- Decorative blues → brand teal/navy: dashboard visit-flow banner, quick-action button, links,
  calendar, user-edit header, walk-in page, analytics accents, info alerts, "blue" accent scale.
- Kept on purpose: status colours (pending / confirmed / completed / cancelled / no-show).
- Fixed an existing dark-mode bug: dashboard greeting, hero stats and sidebar username were
  near-invisible (dark text on dark teal) because `--white` is remapped in dark mode.
  New `--on-brand` token = white text that stays white in both themes.

## Motion
- KPI tiles rise in once, in sequence (the existing count-up in `app.js` rolls the numbers).
- Cards fade in (opacity only — no leftover `transform`, so dropdowns/modals inside cards can't be clipped).
- Removed: table-row slide-in, top-bar entrance animation.
- Nothing replays on PJAX navigation; none of it runs for reduced-motion users.

## Accessibility (axe-core, WCAG 2.0/2.1 A + AA)
Tested: login, dashboard, patients, patient profile, appointments, calendar, billing, analytics,
doctors (light) and login, dashboard, patients, calendar (dark).
Before: 3–5 rule violations per app page (up to 44 elements). After: 0 on every page tested.
- Text-grey token and several greens/reds/blues darkened to ≥ 4.5:1; dark-mode equivalents lightened.
- Sidebar list semantics, notification panel (focusable region), dismiss buttons, billing filters,
  patient avatar (keyboard-operable) and an inline link now labelled/underlined.

## Other
- Analytics: donut centre label no longer overlaps the "no data" message.

## Not done / left for you
- ~390 `!important` rules across the 7 stylesheets are untouched (needs a careful, screenshot-checked refactor).
- `assets/images/cap.jpg` (630 KB) is no longer used by the login page — safe to delete.
- Only pages listed above were audited; other modules were not reviewed.
