# SteamRex admin guide

Open `/admin/`. For the prepared local installation the generated owner credentials are in private `.runtime/admin-access.json`. On hosting create the first owner through token-protected `/setup/`. Owners manage configuration; operators handle requests and messages. Configuration changes are audited. If another admin edits the same record, reload it before saving rather than overwriting their change.

## Configure the catalogue

1. Add/edit **Services** and **Vehicle classes** first. Make/model records connect a known vehicle to its class. Unknown vehicles use an enquiry instead of an unreliable automatic price.
2. For each bookable service/class, add exactly **three active package tiers: 1, 2, 3**. Set the display name, description, inclusions/exclusions, price, estimated duration and badge. Incomplete combinations are shown in Launch checklist and are unavailable for automatic quotes.
3. Create **Add-ons** with a name, price and additional duration. Edit each package to select its eligible add-ons; an extra is offered only when active and attached to that package. You can attach many extras and change their eligibility later.
4. Optional **Model prices** override a package price for a particular model. **Add-on class prices** override an extra's price for a vehicle class. Otherwise the base price applies.

Money fields in the UI use the displayed currency's standard two-decimal amounts (for example DKK 499.00); the server stores integer minor units. Tax settings use basis points: **2500 means 25.00%**. Choose inclusive, exclusive or no tax according to the business's approved policy. A quote includes package, eligible extras, area surcharge and configured tax, with a saved breakdown.

Use **Remove/archive** to stop offering an item. It is removed from new quotes while old booking snapshots stay intact. Show archived records to restore them. Referenced historical data is retained; this is different from erasing a customer's personal details. Stable IDs/slugs should be chosen carefully because they identify URLs and references. Editing an archived tier restores that tier rather than creating a duplicate.

## Coverage and availability

**Coverage** contains individual postcodes and an optional surcharge for each. Add/remove/edit exact coverage at any time. The server normalizes and revalidates the postcode before a request is accepted.

**Hours** defines one or more nonoverlapping working windows per weekday, slot start interval and simultaneous capacity. Capacity is business-wide concurrent appointments, not a staff roster. **Closures** can block a whole date or part of a date. Settings controls lead time, travel buffer, maximum advance booking and quote lifetime. Appointment duration plus buffer must fit a window and capacity must be available throughout the occupied interval.

Removing hours/coverage blocks new requests; it does not silently cancel existing reservations. Contact affected customers and use explicit cancellation or agreed rescheduling when necessary.

## Handle bookings

Open **Bookings**, search by reference/contact and filter by status/date. The detail screen shows contact/address, the saved price/service snapshot, current reservation, notes, history and delivery states.

- **Confirm** a pending request after checking the details. Submission alone is not confirmation.
- **Reject** a pending request or **Cancel** an active request with a reason. This releases its reserved capacity.
- **Propose reschedule** validates a suggested time but keeps the original reservation. After customer agreement, **Accept reschedule** rechecks capacity and changes the time atomically. If it is no longer available, choose another time. **Withdraw proposal** returns to the previous status/time.
- **Amend service/quote** builds a fresh authoritative quote and requires customer agreement and a reason. The old/new quote is recorded; a longer duration must still fit capacity. Catalogue edits alone never reprice saved requests.
- **Complete** a confirmed appointment after its scheduled finish. Notes/contact corrections remain in history; private notes are not customer messages.

Pending requests reserve capacity, so clear stale requests operationally. The system does not silently expire them. Requests are pay on completion preferences; no online payment is collected.

## Handle enquiries

Enquiries do not reserve capacity. Record contact or close one with a reason. To convert it, choose a classified vehicle, service/package/extras, covered postcode and available time, obtain customer agreement, then convert. Conversion creates one linked booking and prevents another conversion. Confirmation can be selected as part of this agreed conversion.

## Website and SEO

**Customer reviews** manages the homepage review cards: customer name, excerpt, individual 1–5 rating, source label/HTTPS URL, display order and publication. The initial cards are attributed translated Google excerpts featured on SteamRex's reference website. Add verified material with its source; removal archives it. This is a curated presentation, not an automatic Google Business Profile sync.

In **Settings → Business details**, enable/disable homepage background playback, select a fallback photo and set a local MP4 path. The supplied video is already included at `/assets/video/steamrex-spring.mp4`. To replace it, upload an MP4 to that private deployment's public assets/video folder and enter its path. The image picker includes the new SteamRex photography alongside your uploaded media. Reduced motion and data-saving preferences suppress automatic playback; a visitor can explicitly play/pause it.

**Pages** controls page titles, descriptions, headings, summaries, body copy, images and publication. Built-in keys include home, services, how-it-works, service-areas, contact, privacy and terms. Custom published keys appear at `/pages/<key>.html`. Body text supports `## Heading` and `- list item`; arbitrary HTML/scripts are escaped. Service detail URLs come from service slugs. **FAQs** edits homepage questions/answers and their order.

Upload JPG/PNG/WebP images up to 5 MB in **Media**, then set a descriptive alt text. Use those images in services/pages or the homepage setting. Archiving a media record hides it from choices but preserves the file for already-published references.

Settings controls business contact details, region description, canonical website URL and indexing. Production pages include canonical/social metadata and structured data; sitemap/robots update dynamically. Local/demo sites stay noindex. Set the final HTTPS domain, review published content, and enable indexing only on production. Search ranking is not guaranteed by technical SEO.

Review privacy/terms content, publish it, record policy versions and mark legal approval. New requests retain the versions they accepted. Draft sample legal copy needs business review before launch.

## Email, users and maintenance

Local startup runs a worker in capture mode. **Messages** shows the exact queued content, sent/captured/failed states and errors, and allows retry. Captured does not mean delivered to an inbox. Production SMTP configuration is described in [DEPLOYMENT.md](DEPLOYMENT.md); it also requires a scheduled worker. Check the worker heartbeat and failed-message count in **Launch checklist**.

**Admin users** lets owners create/change/deactivate owners or operators and change passwords. Password/role changes sign affected sessions out. The last active owner cannot be disabled/downgraded. Keep credentials unique and private; there is no public password reset workflow.

**Erase personal data** is owner-only on closed/completed requests. It removes customer contact/address, notes and stored/captured notification payloads while retaining nonpersonal commercial/history records. A scheduled maintenance job applies the configured terminal-record retention policy in batches. Database backups require a separate retention policy. Back up and restore with the private CLI scripts, not through a public URL.

Before opening live intake, complete **Launch checklist**, configure SMTP/cron and actual catalogue/coverage/hours/legal settings, then enable requests. All these business controls are available in admin without editing source code.
