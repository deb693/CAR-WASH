# SteamRex

A complete booking website and operational admin panel using **HTML, CSS, vanilla JavaScript, PHP 8.3+ and MySQL 8**. No frontend build, Node server, Composer or framework is required in production.

Customers can select a vehicle, compare packages, choose eligible add-ons, check postcode coverage, get a server-calculated quote and request an available appointment. Unsupported vehicles/locations can send an enquiry. Requests require manual confirmation; payment is arranged after the service, with no online payment gateway.

The homepage includes the supplied SteamRex video, actual SteamRex photography, an animated car story, accessible interior/exterior tabs and attributed Google review excerpts. Reviews and video playback settings are owner-managed. Media is served locally, with still-image fallback and reduced-motion/data-saving behavior; no third-party review scripts are needed.

The admin panel manages services, vehicle classes/makes/models, three package tiers per service/class, inclusions, extras and their eligibility/prices, model and add-on class overrides, postcode coverage, surcharges, hours/capacity/closures, bookings, enquiries, notifications, page content, FAQs, images, SEO, business settings and admin users. Catalogue removal archives records so existing bookings keep their history.

## Start locally on this Windows machine

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\start-local.ps1
```

Open **http://127.0.0.1:8080** and **http://127.0.0.1:8080/admin/**. The generated local owner email/password are in the private, ignored file [.runtime/admin-access.json](.runtime/admin-access.json). Do not upload or share that file. The startup script creates credentials only when no owner exists and preserves existing database data.

The script starts portable PHP, isolated MySQL on port 3307 and the email worker. Local emails are captured as private `.eml` files under `storage/mail/`; delivery status and message previews are also visible in admin. It does not send real customer emails by default. Use synthetic customer details while testing.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stop-local.ps1
```

On a fresh Windows x64 checkout, run `python scripts/download-runtimes.py` first. It downloads official PHP/MySQL portable runtimes into ignored `.runtime/`. The Microsoft Visual C++ runtime must be available. Python is a setup/testing convenience, not a website dependency.

## Try it

1. Sign into admin and inspect **Packages**, **Add-ons**, **Coverage**, **Hours**, **Settings** and **Launch checklist**.
2. Select Volkswagen / Golf on the public booking form. Sample covered postcodes include **1000**, **2000** and **3000**.
3. Choose a package, extras and an available future time. Submit a request using test details.
4. In admin, open the request and confirm, add a note, propose/accept an agreed reschedule, amend an agreed quote, or cancel it. Complete appointments after their scheduled finish.
5. Try **Other vehicle** and postcode **9999** to send an enquiry. Admin can record contact, close it or convert it to a booking once the service, vehicle, coverage and time are known.

SteamRex contact details follow the reference business; currency/timezone default to **DKK / Europe/Copenhagen**. Sample prices, exact covered postcodes and operating hours are development fixtures. Replace them in admin before launch. See [admin guide](docs/ADMIN_GUIDE.md) and [booking lifecycle](docs/BOOKING_LIFECYCLE.md).

## Existing PHP/MySQL installation

Create an empty MySQL 8 database/user. Copy `.env.example` to private `.env.local`; set database credentials and a random APP_KEY of at least 32 characters. Enable PDO MySQL, mbstring, OpenSSL, DOM and zlib. Keep `APP_ENV=local` for local testing.

```text
php scripts/migrate.php
php scripts/seed.php
php scripts/create-admin.php --email=owner@your-domain.com --name=Owner
php -S 127.0.0.1:8080 -t public router.php
```

The admin creation command reads the password from stdin. Run `php scripts/worker.php --watch` separately for local captured emails. Production uses scheduled worker invocations. Seeding is one-time and only for demo mode; it does not reset later admin changes.

## Verification

Start local MySQL, then run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test-all.ps1
```

This deliberately resets only `carcare_test`, and uses a separate empty `carcare_restore_test` for backup/import checks. It covers MySQL domain rules and concurrent allocation, HTTP auth/roles/CSRF, catalogue controls, taxes/overrides, booking lifecycle/conversion, SMTP failure/recovery, real backup/restore, customer/admin Chrome flows, mobile layout, syntax and an extracted deployment package with a fresh SQL import and first-owner setup. It starts/stops its own test server on 8081; keep that port free.

Browser tests require Node, installed Chrome and project-local Playwright: `npm install --prefix .runtime/browser playwright --no-save`. These are test tools only.

## Deploy to Simply.com

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\package.ps1
```

The ZIP under `deploy/` contains `public_html/`, private PHP services, an empty complete SQL installer and operational scripts. It excludes local credentials, customer data and runtimes. Follow [DEPLOYMENT.md](docs/DEPLOYMENT.md): upload, import **database/install.sql**, configure private environment, create the first owner at `/setup/`, configure the business in admin and add email/maintenance/backup cron jobs.

Public pages render full HTML on the server, with admin-editable metadata, canonical/social links, structured data, sitemap and robots. Local/demo sites stay noindex. The live readiness gate prevents accepting production requests until required catalogue, legal, coverage and SMTP settings are configured. Publishing, real mailbox delivery, DNS/SSL and the final Apache smoke test happen on the actual hosting account.

## Project references

- [Handover](docs/HANDOVER.md), [admin guide](docs/ADMIN_GUIDE.md), [architecture](docs/ARCHITECTURE.md).
- [API contract](docs/API_CONTRACT.md), [database notes](docs/DATABASE_SCHEMA.md), [booking lifecycle](docs/BOOKING_LIFECYCLE.md).
- [Original implementation plan](docs/IMPLEMENTATION_PLAN.md), [baseline backlog](docs/MODULE_BACKLOG.md), [decision register](docs/DECISIONS.md).
