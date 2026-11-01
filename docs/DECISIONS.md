# Decisions and roadmap clarifications

> Implementation update: Simply.com is confirmed. The user's simplicity request replaces the Vite/Tailwind/Laravel proposal with HTML/CSS/JavaScript and PHP/PDO. The user has since confirmed SteamRex and its public business details. Currency/timezone now use DKK/Europe-Copenhagen; numerical pricing and exact coverage remain samples. See [HANDOVER.md](HANDOVER.md) for code status; the register below preserves planning proposals/open business questions.

**Date:** 31 October 2026. All recommendations below are provisional until reviewed. The PDF is the requirements baseline; its embedded implementation prompts have not authorized development during this planning task.

## 1. Business decision register

| ID | Decision | Proposed default / unresolved input | Needed before |
| --- | --- | --- | --- |
| D01 | Country, language, currency, business timezone | Await business details. Do not infer India from the user's computer timezone or Denmark from SteamRex. | M00 contract and M04 pricing/location |
| D02 | System of record / existing CRM | Portal admin owns catalogue/pricing/coverage unless an existing authoritative CRM is confirmed. Discover API access, ownership, sync direction and failure behavior before promising integration. | M00 architecture; CRM integration before affected modules |
| D03 | Booking confirmation | Manual admin confirmation; submission means request received. No auto-confirm by default. | M00 lifecycle |
| D04 | Pending-request capacity | Proposed: a pending request reserves its full interval until confirmed, rejected, cancelled or moved through an agreed reschedule process. No silent expiry in v1. Business must accept the operational need to clear pending requests promptly. | M00 lifecycle and M05 allocation |
| D05 | Capacity model | Business-wide concurrent jobs; quoted maximum duration plus configured travel buffer occupies the interval. Confirm hours, interval, capacity, lead time, buffer and holiday rules. This is capacity scheduling, not staff/route assignment. | M00 schema and M05 |
| D06 | Price and tax model | Service × vehicle class × tier; model override takes precedence; eligible add-ons and approved area surcharge then apply. Currency, tax-inclusive/exclusive rules, rounding and surcharge tax treatment require business input. | M04 |
| D07 | Quote policy | Proposed 15-minute expiry; relevant price/eligibility/coverage change invalidates an unsubmitted quote. Scope versions to relevant inputs so unrelated edits do not invalidate every quote. | M00 contract and M04 |
| D08 | Unknown vehicles | Offer Other and enquiry. Only allow priced booking when the server can authoritatively classify the vehicle. Year and registration remain optional. | M03 |
| D09 | Enquiry required fields | Require contact, requested service, message/context and available vehicle/location information. No mandatory slot, quote or package when those cannot exist. Preferred time optional; never reserve capacity. | M00 request schemas and M06 |
| D10 | Customer change/cancel flow | Contact the business; admin records the action. No unverified public mutation endpoint. Customer self-service needs a separately specified verification/permission flow. | M00 lifecycle and M07 |
| D11 | Notifications | Email to customer and admin. Confirm sender domain, provider, recipient and templates. SMS/WhatsApp deferred until explicitly agreed. | M09 |
| D12 | Hosting and supported versions | Provisionally Laravel 13 / PHP 8.3+ with a supported MySQL deployment; host must support HTTPS, workers and backups. Same-origin frontend/API preferred for admin session simplicity. | M01 |
| D13 | Branding and approved content | Business supplies name/logo, service copy, package details, original/licensed images, genuine reviews, contact details and legal text. Placeholder content is visibly provisional. | M02 review; final content before M10 |
| D14 | Privacy and retention | Agree booking/enquiry retention, notification/audit retention, who may see customer details and backup retention. Minimize logged personal data; do not claim compliance without market-specific review. | Schema finalization and production release |
| D15 | Admin roles and exports | Minimum super admin; minimal operator policy if required. Define who edits prices/settings versus handles requests. CSV export optional and deferred unless requested. | M07–M08 |
| D16 | General contact form | Default contact page uses phone/email and links into the enquiry flow. A separate general-contact submission endpoint is not assumed. | M02 |

Planning can finish with these decisions open. Application scaffolding can use documented provisional settings; pricing, scheduling and production release cannot claim final acceptance while their required business rules remain unresolved.

## 2. Gaps and tensions in the supplied roadmap

| Finding | Why it matters | Proposed resolution |
| --- | --- | --- |
| Quote example omits `quote_id`; booking example requires it | Frontend cannot submit a stable quote reference | Add quote ID and expiry to the response and define how it is persisted/verified. |
| Availability query omits package/add-on duration | Different treatments occupy different lengths of time | Prefer availability bound to `quote_id` and validated location context; quote carries duration. Any endpoint change is explicit in M00. |
| Status text reserves on confirmation; other sections allocate on submission | Two pending requests might consume the same final capacity | Decide D04. Proposed allocation on request creation, retained when confirmed. If the business chooses non-reserving requests, confirmation must allocate and can fail. Do not mix both policies. |
| Enquiry is described as sharing booking details and validations | Unsupported postcode/incomplete catalogue fallback cannot satisfy mandatory booking fields | Separate enquiry schema; quote/slot/package optional as appropriate. Coverage is recorded as context, not a booking eligibility requirement for enquiry submission. |
| Exactly-three rule versus independently editable package active flags | A single deactivation can accidentally break a public combination | Draft/validate the three tiers as a combination and publish atomically; incomplete published combinations return unavailable. |
| Other vehicle path versus class-based prices | Customer-selected class could underprice a job | Derive class server-side for known models; unknown classification goes to enquiry unless an approved rule can resolve it. |
| Public status route and success reference | A reference alone must not disclose customer data | Success response can show the newly submitted request's minimal receipt; persistent private lookup requires a separate verification token or contact verification. |
| Enquiry conversion mentioned without explicit transaction endpoint | Generic enquiry update cannot safely allocate capacity or prevent duplicate conversions | Add an explicit conversion operation with quote/coverage/capacity checks and a unique source-enquiry link. |
| Quote/slot/idempotency storage not present in schema sketch | Required reliability features need actual persistence and constraints | Add these entities/constraints in M00, along with durable notification jobs/outbox. |
| Reschedule status lacks old/new capacity policy | Failed changes can lose the existing slot or double-reserve | Record proposed time separately; retain existing allocation while awaiting agreement, then atomically swap once accepted. No extra hold on proposed time by default. |
| Admins can change coverage/hours after bookings exist | Existing appointments could become silently invalid | Preserve existing appointments, flag affected bookings and require explicit operational action. New requests follow new rules. |
| Service content editing versus no general CMS | Could accidentally expand into a full publishing system | Edit service descriptions in catalogue; keep homepage/FAQ/legal content in source for v1. |

## 3. Proposed lifecycle rules

These are contract proposals, not an implemented state machine. Enforce allowed transitions on the server; the frontend only offers permitted actions.

### Booking

| Current state | Permitted next state | Capacity behavior |
| --- | --- | --- |
| `pending_confirmation` | `confirmed`, `reschedule_requested`, `cancelled`, `rejected` | Allocate on creation under D04; confirmation retains allocation. |
| `confirmed` | `reschedule_requested`, `completed`, `cancelled` | Preserve allocation until rescheduled/cancelled; elapsed intervals no longer affect future availability. |
| `reschedule_requested` | `confirmed`, prior active state if proposal withdrawn, `cancelled`, `rejected` if originally pending | Keep old allocation while pending; agreed replacement is checked and swapped atomically. Record prior state explicitly. |
| `completed`, `cancelled`, `rejected` | Terminal in v1 | Cancellation/rejection releases allocation exactly once; completion records actual service completion without retroactively changing price. |

Reschedule proposals record who requested the change and how agreement was obtained. With no customer portal, admins record customer contact/agreement. Guard against completing a future appointment accidentally; define the operational override, if needed, in M00. Terminal reopening requires a separate future rule rather than arbitrary status editing.

### Enquiry

`new_enquiry → contacted → enquiry_converted OR enquiry_closed`

Allow `new_enquiry → enquiry_closed` or direct conversion when a customer has already agreed. Conversion creates one linked booking through the same booking service. Proposed default is `pending_confirmation`; an authorized explicit convert-and-confirm action can confirm in the same transaction after all checks. An out-of-area enquiry cannot become a bookable appointment until coverage is valid. Requoting and customer agreement precede conversion where commercial terms changed.

## 4. Contract decisions to specify in M00

Retain the PDF's `/api/v1` public and admin endpoint families. The following are proposals to close its gaps, not approved API paths:

- Quote response includes `quote_id`, monetary line items, currency, authoritative duration, relevant version and expiry.
- Availability accepts quote/location context; returned slots identify a start and occupied interval with timezone context.
- Booking and enquiry creation accept an `Idempotency-Key`; specify key validity, replay semantics and payload-conflict behavior.
- Add explicit enquiry detail/conversion, reschedule proposal/acceptance, note creation, admin-user and settings operations where required by the UI.
- Catalogue responses expose structured unavailable reasons and never invent fallback prices.
- Error examples distinguish validation, unsupported combination, stale quote, unavailable slot, invalid transition, conflict, unauthenticated and forbidden outcomes. Freeze HTTP codes and field names in the contract.
- Admin writes include record versions where concurrent edits matter. Lists specify filter, sort, pagination and maximum page-size behavior.
- A quote is bound to validated selections and location. Booking submission cannot mix one quote's low price with another package, vehicle or area.

## 5. Technical sources checked

- [Laravel release/support policy](https://laravel.com/framework/docs/releases): version/PHP compatibility for the proposed foundation.
- [Tailwind's Vite installation guide](https://tailwindcss.com/docs/installation/using-vite): current integration pattern rather than reproducing an illustrative older configuration.
- [SteamRex homepage](https://steamrex.com/), [subscription page](https://steamrex.com/s365) and [FAQ](https://steamrex.com/kundeservice): public flow reference only.

No framework installation, application tests or deployment have been performed as part of this plan.
