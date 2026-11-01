# Development handover — 31 October 2026

**Homepage update — 1 November 2026:** added the supplied locally hosted video, genuine SteamRex photography, a scroll-driven car story, keyboard-accessible interior/exterior exploration, attributed Google review excerpts and links to existing subscription/gift-card services. Owners now manage review cards and video settings. Motion, failed-video fallback and no-JavaScript content are covered by `tests/home-browser.cjs`; all assets remain compatible with the existing PHP/shared-hosting deployment.

The full acceptance suite passed after this update, including real video decoding/playback, persistent pause, scroll-driven chapter changes, 390/768/1024/1440-pixel layouts, loaded imagery, reduced motion, explicit play, unavailable-video fallback, no-JavaScript rendering, review/video owner controls, existing booking/admin flows and the extracted fresh-install release package.

The requested application modules are implemented. The project is a build-free HTML/CSS/JavaScript site with PHP/PDO services and MySQL, ready for local acceptance testing and hosting configuration. The original navy/cream/teal styling, responsive layouts and reduced-motion support are retained.

## Implemented modules

| Area | Delivered behavior |
| --- | --- |
| Public website | Server-rendered home, services/details, process, coverage, contact, booking, legal and custom content pages; editable FAQs/media |
| Catalogue | Owner CRUD/archive/restore for services, classes/makes/models, three package tiers, inclusions/exclusions, extras and package eligibility |
| Pricing | Integer minor-unit calculations, model/package and extra/class overrides, postcode surcharges, inclusive/exclusive tax, expiring/stale quote protection |
| Coverage and scheduling | Admin postcodes, hours, capacity, intervals, lead/buffer/horizon, partial/full closures, transactional concurrent allocation |
| Customer requests | Idempotent booking/enquiry submission, saved commercial snapshots, consent versioning, request references |
| Admin security | First-owner token setup, password hashing, session rotation/expiry, CSRF, rate limits, owner/operator permissions, last-owner protection |
| Operations | Inbox/search/filter/detail, notes/history, confirmation, rejection/cancellation/completion, agreed quote amendments and transactional rescheduling |
| Enquiries | Contact/close, fresh quote and exactly-once agreed conversion into a capacity-checked booking |
| Configuration | Business/contact/domain/currency/timezone/tax, booking policies, legal publication/versions, SEO/indexing, mail, retention and admin users |
| Notifications | Transactional outbox, local message capture, authenticated TLS SMTP, leases/retries, delivery previews/status/retry controls and worker heartbeat |
| SEO | Real HTML, unique editable metadata, canonical/social tags, nonce-protected structured data, dynamic sitemap/robots, local/demo noindex |
| Operations tooling | Idempotent migrations, full empty SQL installer, CLI worker/retention cleanup, consistent backup/empty-database restore, readiness checks and secret-free hosting ZIP |

The local startup command and private generated admin credentials are documented in [README](../README.md). [ADMIN_GUIDE.md](ADMIN_GUIDE.md) explains configuration; [DEPLOYMENT.md](DEPLOYMENT.md) gives the hosting procedure.

## Verification and practical limits

**Result, 31 October 2026: the complete local acceptance suite passed.** The actual main-site startup/restart, generated owner login, admin resource schemas, public pages and first-owner setup lockout also passed a separate smoke check. PHP 8.3.36 and MySQL 8.0.46 were used locally; Chrome exercised both customer and admin interfaces.

Run `scripts/test-all.ps1` after local MySQL starts. It uses isolated test/restore databases and tests real MySQL rules/concurrent capacity, HTTP authentication/CSRF/roles and admin CRUD, request transitions and conversion, tax/override calculations, SMTP success/failure/retry, erasure, real backup/restore, public/admin Chrome workflows, responsive pages, syntax and a fresh extracted hosting package. Test SMTP is loopback-only; no real customer emails are sent by that suite.

The hosting acceptance step remains environment-specific: actual Simply.com Apache rewrite behavior, domain/SSL, database credentials, mailbox delivery and scheduled commands must be verified after upload. No hosting account credentials were supplied and no live deployment has been performed.

## Configuration still needed from the business

- Replace sample prices/durations, exact postcode coverage and hours in admin. Add or remove packages, extras and vehicles as required; maintain three active tiers for each bookable service/class.
- Set the final domain, approved public/legal copy, tax policy, retention and operational rules. Legal text is a draft until reviewed and approved in settings.
- Configure a real mailbox/SMTP sender and notification recipient, database/environment secrets, HTTPS and cron. Run the launch checklist and enable production intake/indexing.
- Set protected offsite database/config/media backup retention and monitor worker health/failed mail.

These are deployment and business inputs, not unimplemented admin modules. Payment gateway, customer accounts, staff dispatch/route optimization and an external CRM were outside the agreed simple first release. Payment preference is recorded as pay on completion; no payment is charged online.

## Rules retained

SteamRex is the business name, with reference contact information and Danish context from steamrex.com; currency/timezone default to DKK/Europe-Copenhagen. A submitted booking is pending manual confirmation and reserves capacity. Enquiries reserve none. Proposing a new time keeps the original reservation until an agreed acceptance; failed changes roll back. Old requests retain their quote snapshots when catalogue items change or are archived. Closed-record personal-data erasure is distinct from catalogue archival.
