# Module execution backlog

**Status update, 31 October 2026:** The requested public, admin, catalogue, scheduling, request operations, notification, publication, SEO and deployment-tooling modules are implemented using HTML/CSS/JavaScript + PHP/MySQL. This document preserves the original planning scope; HANDOVER.md and API_CONTRACT.md describe the final implementation and hosting configuration work.

**Ownership abbreviations:** Shared = product/contract review; FE = frontend; BE = backend; Ops = environment/release. These describe responsibilities and do not assume a particular team size.

## M00 — Contract and product rules

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M00.1 | Shared | Resolve blocking entries in DECISIONS.md; record approved versus provisional rules. |
| M00.2 | BE + Shared | Define entities, relationships, unique constraints, quote/allocation/idempotency storage and lifecycle transitions. |
| M00.3 | FE + BE | Write API/OpenAPI request and response schemas, auth/error conventions and endpoint examples. |
| M00.4 | FE + BE | Create fixtures covering three packages, unavailable context, unsupported area, expired quote and slot conflict. Validate fixtures against schemas. |
| M00.5 | Shared | Review the complete booking, unsupported-area enquiry and conversion journeys on paper; accept contract before parallel coding. |

## M01 — Foundation

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M01.1 | Ops | Verify runtime versions, PHP extensions, Composer, database and optional container availability. Document missing setup. |
| M01.2 | FE | Vite/vanilla-JS/Tailwind app, router, API client, mock adapter and frontend environment example. |
| M01.3 | BE | Laravel API, database connection, version prefix, health route, reference migration/seed structure and backend environment example. |
| M01.4 | Shared | Fresh-checkout instructions plus frontend build, schema validation and backend test commands in automation. |

## M02 — Design and public pages

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M02.1 | FE | Design tokens and representative mobile/desktop layouts for home, package selection and admin detail. |
| M02.2 | FE | Accessible fields, selectors, cards, modal, accordion, stepper, summary and feedback components. |
| M02.3 | FE | Public page map, real navigation, quick-start booking state and visible content placeholders. |
| M02.4 | FE | Responsive, keyboard and reduced-motion walkthrough; confirm original/licensed assets and approved copy status. |

## M03 — Catalogue

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M03.1 | BE | Catalogue migrations, relations, factories and deterministic sample services/vehicles/three-tier combinations. |
| M03.2 | BE | Read endpoints with active filtering, model/class validation and structured unavailable response. |
| M03.3 | FE | Service and make/model/class selection, Other route and three package cards/details using the shared API client. |
| M03.4 | FE + BE | Integrate real read API; prove dependent-state reset and exactly-three behavior with supported and incomplete data. |

## M04 — Quotes and coverage

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M04.1 | BE | Quote service and persistence; precedence, money rounding, duration, expiry and input binding. |
| M04.2 | BE | Coverage normalization/check and eligible add-ons; surcharge/tax only as approved. |
| M04.3 | FE | Extras, location check, itemized quote and stale-price review UI; no client price authority. |
| M04.4 | FE + BE | Verify pricing tampering, model override, ineligible extra, location change, expiry and out-of-area enquiry fallback. |

## M05 — Availability

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M05.1 | BE | Hours, capacity, interval, lead time, blocked dates and travel-buffer model. |
| M05.2 | BE | Duration-aware slot generation and lockable interval-allocation/release service. |
| M05.3 | FE | Date/time picker with timezone, empty/full/loading and stale-selection states. |
| M05.4 | BE + FE | Real-database concurrency test for final capacity and differently-starting overlapping jobs; boundary/timezone checks. |

## M06 — Customer submissions

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M06.1 | BE | Separate booking/enquiry validators; quote, catalogue, area and capacity rules appropriate to each intent. |
| M06.2 | BE | Transactional persistence, snapshots, random references, consent records and idempotency/replay behavior. |
| M06.3 | FE | Contact/intent/review/submit/success flow, field errors, duplicate-click protection and retry-safe handling. |
| M06.4 | FE + BE | Booking and enquiry end-to-end demonstrations, last-slot race, stale quote and ambiguous network-retry verification. |

## M07 — Admin operations

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M07.1 | BE + FE | Admin auth, permissions, session/logout behavior and protected route shell. |
| M07.2 | BE + FE | Counts, searchable/paginated lists, full request detail, notes and history. |
| M07.3 | BE | Explicit state transitions, transactional rescheduling and exactly-once enquiry conversion. |
| M07.4 | FE | Status/reason actions, proposed-time agreement interface, conflict feedback and conversion review. |
| M07.5 | BE + FE | Authorization tests, invalid-transition checks, simultaneous-admin conflict and allocation-preserving failed reschedule. |

## M08 — Admin configuration

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M08.1 | BE + FE | Services, package tiers/overrides and three-tier publication controls. |
| M08.2 | BE + FE | Vehicles/classes, add-ons and eligibility management. |
| M08.3 | BE + FE | Coverage, business hours, lead time, buffer, capacity and blocked-date screens/APIs. |
| M08.4 | BE + FE | Minimal admin-user management and read-only audit view, respecting permission boundaries. |
| M08.5 | Shared | Demonstrate public cache invalidation, immutable old booking snapshots and explicit handling of affected upcoming appointments. |

## M09 — Notifications

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M09.1 | BE | Event-to-template map, durable jobs/outbox, email adapter and development mail sink. |
| M09.2 | BE | Customer/admin templates, after-commit delivery, retry strategy and delivery logs. |
| M09.3 | FE + BE | Admin delivery visibility and delayed-notification copy where useful. |
| M09.4 | Shared | Verify failure/retry behavior and request-versus-confirmation wording with actual captured messages. |

## M10 — Release candidate verification

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M10.1 | FE + BE | Run all 14 release scenarios in IMPLEMENTATION_PLAN.md against the integrated system. |
| M10.2 | FE | Keyboard, reduced-motion, responsive/browser, imagery/performance and public SEO checks. |
| M10.3 | BE | Auth/CSRF/rate-limit/input/privacy checks plus real-database concurrency and queue-recovery checks. |
| M10.4 | Shared | Prioritized findings with reproduction steps; fix blocking issues, rerun affected checks and record residual limitations. |

## M11 — Release and handover

| Task | Owner | Deliverable / evidence |
| --- | --- | --- |
| M11.1 | Ops | Staging/production configuration, HTTPS, secrets, supervised workers, backups and monitoring. |
| M11.2 | Ops + BE | Restore drill, migration/rollback rehearsal and secure admin provisioning. |
| M11.3 | Shared | Approved content/settings, business walkthrough and staging smoke checks. |
| M11.4 | Ops | Production deployment when release is authorized; record actual smoke checks and delivery outcomes. |
| M11.5 | Shared | Complete README, API/schema/architecture/lifecycle/deployment docs, HANDOVER.md and CHANGELOG.md. |

## Definition of done for each implementation task

- Scope is limited to the named module and its necessary dependencies.
- Frontend and backend honor the same contract; a changed payload updates schemas, fixtures and documentation in the same change.
- Loading, empty, error, validation and success behavior are included where relevant.
- Meaningful checks prove the task's business behavior; do not add tests that only duplicate trivial implementation details.
- Report actual commands/results, reviewable behavior, outstanding blockers and the next dependent task.
- Do not count fixture-only behavior as a completed real-API integration.

## Suggested first build instruction

> Read README.md, docs/IMPLEMENTATION_PLAN.md, docs/DECISIONS.md and docs/MODULE_BACKLOG.md. Complete M00 only: confirm or explicitly record provisional product decisions, write the API/OpenAPI contract, database specification, lifecycle and shared fixtures. Identify decisions that block later modules. Do not scaffold the application until the contract is reviewed; do not implement payment gateways, customer accounts, staff assignment or an external CRM without agreed scope.
