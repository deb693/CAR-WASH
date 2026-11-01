# Implemented API contract

Base path: `/api/v1/`. JSON responses use `{ "data": ... }`; errors use `{ "error": { "code", "message", "fields" } }`. Requests with bodies require `application/json`, except multipart image upload. Server-side validation, rate limits and SQL parameterization apply. API responses are no-store/noindex. Prices are **integer minor units**; dates are business-local calendar dates and appointment timestamps include an explicit offset.

## Public endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| GET | health | Database connection and demo flag; no credentials |
| GET | setup/status | Whether first-owner setup is available |
| POST | setup/owner | Token-protected first owner only; name/email/password/setup_token |
| GET | public/catalogue | Active services, vehicle data, packages, eligible extras, overrides and public booking settings |
| POST | public/service-area/check | Normalized postcode coverage and surcharge |
| POST | public/quotes | Authoritative quote for service/model/package/extras/postcode |
| GET | public/availability?quote_id=…&date=YYYY-MM-DD | Slots for the current quote/duration |
| POST | public/bookings | Save a pending pay-on-completion request and reserve capacity |
| POST | public/enquiries | Save an enquiry without reserving capacity |

Booking/enquiry submission requires `Idempotency-Key`: repeating the same request returns its original reference; reusing a key with a different payload conflicts. Quote submission revalidates relevant price/eligibility/coverage inputs and expiry. Incomplete three-tier contexts cannot produce priced bookings; enquiry remains available. All customer prices/durations/classification are calculated by the server. See `public/assets/js/booking.js` and `app/Requests.php` for the exact submission fields and validation limits.

## Authentication and authorization

| Method | Path | Purpose |
| --- | --- | --- |
| GET | admin/auth/session | Current user (or null), CSRF token and local-mode flag |
| POST | admin/auth/login | Email/password; returns rotated session/token |
| GET | admin/auth/me | Authenticated identity |
| POST | admin/auth/logout | Destroy current session |

Sessions use HttpOnly, SameSite=Strict cookies and Secure on HTTPS. Admin writes require `X-CSRF-Token`. Login is rate limited and rotates the session. Passwords are hashed; password/role/deactivation changes invalidate existing sessions. `super_admin` owns configuration and user management; `operator` handles requests/notifications. A sole active owner cannot be removed or downgraded. Production transport redirects to the configured canonical HTTPS host before serving setup/admin.

## Request operations (both roles)

| Method | Path | Purpose |
| --- | --- | --- |
| GET | admin/overview | Status counts, upcoming requests and failed message count |
| GET | admin/bookings or admin/enquiries | Search/filter/paginated inbox |
| GET | admin/bookings/{id} or admin/enquiries/{id} | Full saved snapshot/contact/history/notes/messages |
| PATCH | admin/bookings/{id} | `action` and current `version`; action-specific fields |
| PATCH | admin/enquiries/{id} | `action` and current `version` |
| GET | admin/bookings/{id}/availability?date=… | Reallocation options excluding that booking |
| POST | admin/enquiries/{id}/convert | Fresh quote/time, `version`, `customer_agreed`; optional confirm |
| DELETE | admin/bookings/{id} or admin/enquiries/{id} | Owner only: erase personal data of a terminal request |

Booking actions: `note`, `edit_contact`, `confirm`, `reject`, `cancel`, `complete`, `propose_reschedule`, `accept_reschedule`, `withdraw_reschedule`, `amend`. Enquiry actions: `note`, `edit_contact`, `contacted`, `close`. Agreement is required for accepting a reschedule, changing a quote or converting an enquiry. Invalid transitions fail; stale versions return 409. Completion before the appointment ends is rejected. See [lifecycle](BOOKING_LIFECYCLE.md).

## Owner configuration

| Method | Path | Purpose |
| --- | --- | --- |
| GET | admin/metadata, admin/lookups | Validated resource form schemas and contextual choices |
| GET/PUT | admin/settings | Typed settings; PUT requires current `_revision` |
| GET | admin/readiness | Production blockers, warnings and worker heartbeat |
| GET | admin/audit | Paginated configuration audit trail |
| GET/POST | admin/users | List/create admins |
| PUT | admin/users/{id} | Edit credentials, role, active state |
| GET/POST | admin/media | List/upload validated JPG/PNG/WebP, max 5 MB |
| PUT/DELETE | admin/media/{id} | Edit name/alt or archive metadata, preserving referenced image files |
| GET/POST | admin/catalogue/{resource} | List/search or create |
| GET/PUT/DELETE | admin/catalogue/{resource}/{id} | Read/edit/archive, with optimistic `version` |

Resources: `services`, `vehicle-classes`, `vehicle-makes`, `vehicle-models`, `packages`, `add-ons`, `price-overrides`, `addon-price-overrides`, `service-areas`, `availability-rules`, `blocked-dates`, `content`, `faqs`, `reviews`. Composite override IDs are encoded `package_id~model_id` or equivalent resource keys. Schema definitions in `app/AdminCatalogue.php` are authoritative. Archived catalogue rows can be restored; historical request snapshots are preserved. Package inputs include `add_on_ids` for eligibility, inclusions/exclusions, tier, price and duration. Reviews require attributed HTTPS source URLs and individual 1–5 ratings; homepage video settings allow only existing MP4 files under `/assets/video/`.

Settings contain mail credentials write-only: SMTP password never appears in responses/audit. It is encrypted at rest. Enabling live production intake runs the readiness gate: real mail configuration, legal approval and valid catalogue/coverage/hours are required. Local test intake may use captured messages.

## Notifications (both roles)

GET `admin/notifications` lists paginated/filterable delivery states; GET `admin/notifications/{id}` previews the saved message; POST `admin/notifications/{id}/retry` requeues a pending/failed message. Customer/admin events are queued transactionally, processed by private CLI worker and retried independently of request saving. Messages use stable event keys/Message-ID; delivery is at least once. There is no public worker, backup or request-mutation endpoint.

Common errors include 401 unauthenticated, 403 role denied, 419 CSRF/session changed, 409 stale edit/quote/slot/idempotency conflict, 422 invalid input/transition, 429 rate limited and 503 temporary database/configuration failure. No database exception text or secrets are returned to visitors.
