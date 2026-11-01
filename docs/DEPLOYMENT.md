# Simply.com production deployment — first-time guide

Verified against Simply.com's official documentation on **1 November 2026**. This guide uses the tested SteamRex PHP/MySQL release and one supported route: **PHP hosting + FTPS upload + MySQL + authenticated SMTP + SSH/Linux cron**.

The application and extracted release have passed local acceptance tests. Production is ready to configure; the final host-specific checks below establish that your actual DNS, SSL, PHP, database, mailbox and scheduler work together. A ZIP upload by itself does not configure those services.

Every `your-domain.com`, `YOUR_ACCOUNT_PATH` and capitalized placeholder below must be replaced with your own value. Use only a domain/account you administer. The reference website's domain is not automatically your deployment domain.

## 0. Gather these values first

Log into the Simply.com control panel and **select the web-hosting product for your domain**. Account login, hosting/FTP credentials, database credentials, mailbox credentials and the new website admin login are separate things.

Keep this worksheet privately; do not paste passwords into a support screenshot or public file:

| Value | Where to find it / what to choose |
| --- | --- |
| Final website URL | Choose `https://your-domain.com` or `https://www.your-domain.com`, consistently |
| Hosting type | PHP/Linux hosting, not ASP/.NET or website-builder-only hosting |
| Website server IP / DNS target | Selected product's server/DNS details |
| FTP username and product password | Hosting product / FTP details or welcome email |
| MySQL hostname, database name, username, password | MySQL section / welcome email |
| Sender mailbox | A real mailbox such as `bookings@your-domain.com` |
| Sender mailbox password | Password assigned to that mailbox |
| Admin notification recipient | Your monitored business inbox; may be different from the sender |
| APP_KEY and SETUP_TOKEN | Generate in Step 6 |
| Absolute account path | Read from SSH with `pwd` in Step 10; do not guess it |

If this domain already has a live site, first download its files and export its database. Do not overwrite that site or change its DNS until its replacement is ready. Preserve existing mail DNS records if mail is staying with its present provider.

## 1. Select the correct hosting and PHP version

1. Open the selected hosting product.
2. Confirm it uses **PHP hosting**. This application runs on PHP 8.3+ and MySQL 8; it does not need a Node server, npm, Vite or Composer on hosting.
3. Open **Website → PHP Version** and select **PHP 8.3**, matching the tested application runtime.
4. Locate **Website → SSH access**. We use this later for the scheduled email worker.

Simply.com's published PHP-hosting specifications include Apache 2.4 and MySQL 8.0. PHP version selection is documented in [PHP versions and modules](https://www.simply.com/en/support/faq/php/1-php-versions-extensions-and-modules/); hosting specifications are on [PHP hosting technical details](https://www.simply.com/en/hosting/basicsuite/). Check the versions shown for your selected account.

**Checkpoint:** you can access PHP settings, MySQL, file management, mail administration and SSH access for the intended product.

## 2. Prepare the release on your Windows computer

Use the latest supplied `deploy/steamrex-*.zip`, which already contains the video and new homepage. Right-click it in Windows Explorer and choose **Extract All**.

If rebuilding after a code change, run this from the project folder in PowerShell:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\package.ps1
```

The extracted release must contain `public_html`, `app`, `database`, `scripts`, `docs`, `.env.example` and the guides. This is the release folder, not your entire development checkout.

The production installer is **database/install.sql**. It contains the complete empty schema, including admin and reviews tables. It does not transfer your local test bookings, local admin password or local catalogue configuration. First-time production business data is entered in Step 8. Initial pages/FAQs/review excerpts and default hours are initialized by owner setup.

**Checkpoint:** you can find `public_html/index.php`, `public_html/.htaccess`, `public_html/assets/video/steamrex-spring.mp4` and `database/install.sql` on your computer.

## 3. Upload the correct folders

For a complete folder upload on Windows, use FileZilla's Site Manager or your existing FTPS client. Simply.com's [FTPS instructions](https://www.simply.com/en/support/faq/ftp/76-setting-up-ftp-and-ftps/) specify:

| Connection setting | Value |
| --- | --- |
| Protocol | FTP |
| Host | `ftp.simply.com` |
| Port | `21` |
| Encryption | Require explicit FTP over TLS (FTPS) |
| Logon type | Normal |
| Username/password | The hosting product's FTP credentials |
| Transfer mode | Passive |

Connect. The server side should show `public_html`. That folder's **parent** is the account root used throughout this guide. Simply.com's [file location guide](https://www.simply.com/en/support/faq/general/32-file-location/) confirms `public_html` is the public web root.

Upload as follows:

| From extracted release | Destination on server |
| --- | --- |
| Contents of `public_html/` | Inside the existing server `public_html/` |
| `app/` | Beside server `public_html/` |
| `database/` | Beside server `public_html/` |
| `scripts/` | Beside server `public_html/` |

Create a `storage` directory beside `public_html`; the app creates its session/mail/backup subdirectories as needed. Keep guides on your computer unless you want private server copies.

The final tree must look like this:

```text
account-root/
  .env.local                  create in Step 6; PRIVATE
  app/
    bootstrap.php
    Homepage.php
    ...
  database/
    install.sql
    migrations/
  scripts/
    worker.php
    maintenance.php
    backup.php
    restore.php
    ...
  storage/                    PRIVATE; writable by PHP
  public_html/                PUBLIC website
    .htaccess
    index.php
    render.php
    admin/
    setup/
    api/
    assets/
      css/
      js/
      images/
      uploads/                writable by PHP
      video/steamrex-spring.mp4
    ...
```

There must **not** be a second `public_html` inside `public_html`. Upload dotfiles, including both `.htaccess` files (root and uploads). Wait for the transfer queue to finish without failed files. If the account has a provider welcome page, save a copy and remove/rename only that known placeholder after uploading the app; do not remove an existing site's files blindly.

Do not upload `.runtime`, your local `.env.local`, `storage` test data, `.git`, `node_modules`, tests, local admin-access.json, test screenshots, backups or the ZIP into `public_html`.

**Checkpoint:** `index.php` is directly inside server `public_html`; `app`, `scripts` and the future `.env.local` are one level above it.

## 4. Import a fresh production database

1. Open **MySQL** in the selected product's control panel.
2. Copy the exact database host, database name, username and password from there. Use port **3306**. The hostname is not assumed to be `localhost`.
3. Use a new/empty production database. If your included database contains another application, use a separate database; do not drop its tables.
4. Open **MySQL web administration / phpMyAdmin** from the panel, or [mysql.simply.com](https://mysql.simply.com).
5. Select your intended database in the left sidebar.
6. Choose **Import → Choose file**, select the extracted **database/install.sql**, keep SQL format selected and run the import.
7. Confirm there are no import errors and tables such as `admins`, `bookings`, `enquiries`, `packages`, `settings`, `reviews` and `notifications` exist.

This creates tables inside your hosting database, not a database/server user. Import only once into an empty database. Do not import `schema.sql` alone, and do not use a database backup of local testing as your clean production installer.

Sources: [MySQL administration](https://www.simply.com/en/support/faq/mysql/5-mysql-database-administration/), [SQL import](https://www.simply.com/en/support/faq/mysql/846-import-mysql-database/).

## 5. Point the domain and enable HTTPS

Use the selected product's actual DNS targets. If DNS is already correct, leave it unchanged. Otherwise update the website's DNS records at the provider managing your domain; do not guess an IP or replace mail records to change only the website.

Once the hostname resolves to this hosting product:

1. Open **Website → HTTPS protection**.
2. Select **Let's Encrypt DV certificate** for the hostname you will use.
3. Activate it and wait until the panel shows it active.
4. If you serve both bare domain and `www`, ensure both have working certificates before redirecting one to the other.
5. Enable **Forced HTTPS** after the certificate works.

Let's Encrypt requires the hostname to point to Simply.com and its validation path to be accessible. See [HTTPS setup](https://www.simply.com/en/support/faq/general/123-https-ssl-protection/). Use a direct hosting connection for this initial setup; an existing reverse proxy must also have a correct HTTPS connection to the origin.

**Checkpoint:** the intended hostname has a valid browser certificate. The app's production redirect will use the exact URL entered next.

## 6. Create the private production .env.local

Do not change your development `.env.local`. Make a separate production file from the release `.env.example`.

On your Windows computer, run the following command **twice**, from the development project directory:

```powershell
.\.runtime\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Each run prints a different 64-character random value. Use the first for APP_KEY and the second for SETUP_TOKEN. Save APP_KEY securely: stored SMTP credentials are encrypted with it.

Create `.env.local` in UTF-8 without BOM, with this content after replacing all placeholders:

```ini
APP_ENV=production
APP_KEY="PASTE_FIRST_RANDOM_VALUE"
SITE_URL="https://your-domain.com"
BUSINESS_NAME="SteamRex"
BUSINESS_TIMEZONE=Europe/Copenhagen
CURRENCY=DKK
DEMO_MODE=false
SETUP_TOKEN="PASTE_SECOND_RANDOM_VALUE"

DB_HOST="MYSQL_HOST_FROM_PANEL"
DB_PORT=3306
DB_DATABASE="MYSQL_DATABASE_FROM_PANEL"
DB_USERNAME="MYSQL_USERNAME_FROM_PANEL"
DB_PASSWORD="MYSQL_PASSWORD_FROM_PANEL"

MAIL_TRANSPORT=smtp
MAIL_FROM="bookings@your-domain.com"
SMTP_HOST=websmtp.simply.com
SMTP_PORT=587
SMTP_SECURITY=starttls
SMTP_USERNAME=""
SMTP_PASSWORD=""

SCHEDULE_CAPACITY=1
SCHEDULE_BUFFER_MINUTES=15
SCHEDULE_LEAD_MINUTES=60
```

The empty SMTP credentials are intentional here: enter the real mailbox credentials through admin in Step 9, where the password is encrypted in the database. The admin notification recipient is also set in admin. APP_KEY, setup token and database credentials must already be real values.

Upload this production file **beside** `public_html`, at `account-root/.env.local`. Windows must not silently name it `.env.local.txt`. Save with ordinary straight quotes and exact credentials; values with embedded quotes require correct INI escaping. Do not paste the credentials into a shell command.

**Checkpoint:** opening `https://your-domain.com/api/v1/health` returns JSON with status `ok`, database `connected` and demo_mode `false`. If it does not, use the troubleshooting table before continuing.

## 7. Create your live owner account

1. Open `https://your-domain.com/setup/`.
2. Paste the production SETUP_TOKEN.
3. Enter your owner name, real email and a new strong password of at least 12 characters.
4. Submit once, then sign in at `https://your-domain.com/admin/`.
5. Edit the private production `.env.local` and set `SETUP_TOKEN=""`.

The setup API also locks itself after the first admin exists. Your `.runtime/admin-access.json` is only for local development and is not the live owner login. PHP must be able to write private `storage/sessions` and public `assets/uploads`; use the host's normal account permissions, not blanket 777 permissions.

**Checkpoint:** live owner login works and a second first-owner setup is unavailable.

## 8. Configure the business in admin

Leave **Accept new requests OFF** and **Allow search-engine indexing OFF** while configuring. A fresh production database starts with intake closed.

Configure in this order:

| Admin area | What to enter |
| --- | --- |
| Business settings | Business/contact details, canonical HTTPS URL, DKK/currency, Europe/Copenhagen timezone, admin notification recipient |
| Services | Names, descriptions, active state, photos and URL slugs |
| Vehicle classes | The classes you actually price, such as hatchback/saloon/SUV |
| Vehicle makes/models | Makes/models mapped to the correct class |
| Add-ons | Names, real prices and extra duration |
| Packages | Exactly three active tiers (1, 2, 3) per bookable service/class; inclusions, prices, duration and eligible add-ons |
| Overrides | Optional model-specific package prices or class-specific add-on prices |
| Postcode coverage | Actual supported individual postcodes and any surcharge |
| Working hours / closures | Correct business hours, start intervals, capacity and holidays/partial closures |
| Pricing and tax | Approved inclusive/exclusive/none treatment; 2500 basis points means 25% |
| Pages / FAQs / media / reviews | Final public content, photos, alt text, meta descriptions and attributed reviews |
| Publication and privacy | Reviewed/published privacy and terms, policy versions, approval and retention |

For a minimal operational smoke test, you need at least one active service, class, make/model, a full three-tier package set, one covered postcode and a working window that fits the service duration plus buffer. Configure all intended combinations before promoting the site.

See [ADMIN_GUIDE.md](ADMIN_GUIDE.md) for the full workflow. The stored admin website URL overrides the environment default after setup; keep them consistent. Do not run the local demo seed/reset/test scripts on hosting.

## 9. Set up real email

1. In Simply.com's **Mail / Mail Administration**, create or select an actual sending mailbox, for example `bookings@your-domain.com`.
2. Confirm that mailbox login works. Its password is a mailbox password, not your Simply.com account, FTP or MySQL password.
3. In SteamRex **Admin → Business settings → Email delivery**, set:

| Field | Value |
| --- | --- |
| Email delivery | SMTP delivery |
| Sender email | Your actual sender mailbox |
| SMTP hostname | `websmtp.simply.com` |
| Port | `587` |
| Encryption | STARTTLS |
| SMTP username | Full sender mailbox address |
| SMTP password | That mailbox's password |

4. In **Business details**, set **Admin notification email** to the inbox that should receive booking alerts. Save.
5. Confirm the domain's SPF/DKIM/DMARC configuration with the mail provider, especially when DNS is managed elsewhere. Preserve existing records for other senders.

Simply.com documents this endpoint specifically for scripts running on its own web servers; it is not a local-PC SMTP endpoint. See [outgoing mail for scripts](https://www.simply.com/en/support/faq/mail/23-outgoing-mail-server-for-scripts/). This app sends through SMTP, not PHP mail().

SMTP configuration alone does not process the queue. Continue with the worker.

## 10. Start the private worker and schedule it

Open **Website → SSH access** in Simply.com's control panel and use its browser SSH connection. If using your own SSH/SFTP client instead, follow [SSH setup](https://www.simply.com/en/support/faq/php/349-connect-via-ssh/); it uses `ssh.simply.com`, your product username and an uploaded public key.

In the server terminal, run these commands one at a time:

```sh
pwd
ls -a
```

Find the directory containing **both** `public_html` and `app`/`scripts`. Navigate there if the terminal opened elsewhere. This is your actual account root. Run `pwd` there and privately record its full output as YOUR_ACCOUNT_PATH. Do not copy the Windows workspace path or guess `/var/www/...`.

From that confirmed account root:

```sh
mkdir -p storage/sessions storage/backups
cd public_html
/usr/local/bin/php -v
/usr/local/bin/php -m
```

The CLI must be PHP **8.3+** and include `pdo_mysql`, `mbstring`, `openssl`, `dom`, `fileinfo` and `zlib`. Simply.com documents `/usr/local/bin/php` as its PHP launcher. Check its actual version here; choosing web PHP in the panel does not remove the need to check CLI.

If needed, add this documented directive near the top of the **server public_html/.htaccess**, keep PHP 8.3 selected in the panel, and run the version command again from `public_html`:

```apache
AddType application/x-httpd-php83 .php
```

The launcher follows `.htaccess` PHP selection; see [PHP binary documentation](https://www.simply.com/en/support/faq/php/1-php-versions-extensions-and-modules/). If it still uses an older version, have Simply.com identify the correct PHP 8.3 CLI command before scheduling it. Do not guess another executable.

Now run the worker once, still from `public_html`:

```sh
/usr/local/bin/php ../scripts/worker.php
```

No output can be normal when the queue is empty. Refresh **Admin → Launch checklist**: its worker heartbeat should now be recent.

Create the real schedule:

```sh
crontab -e
```

Add the following three lines **without deleting existing jobs**. Replace `/YOUR_ACCOUNT_PATH` in every line with the exact account-root path you recorded:

```cron
* * * * * cd /YOUR_ACCOUNT_PATH/public_html && /usr/local/bin/php ../scripts/worker.php >> ../storage/worker.log 2>&1
15 3 * * * cd /YOUR_ACCOUNT_PATH/public_html && /usr/local/bin/php ../scripts/maintenance.php >> ../storage/maintenance.log 2>&1
30 2 * * * cd /YOUR_ACCOUNT_PATH/public_html && /usr/local/bin/php ../scripts/backup.php >> ../storage/backup.log 2>&1
```

The `cd` matters: it lets the PHP launcher see the intended directory's PHP settings, and the private script/log paths resolve correctly. These commands assume the recorded path has no spaces; quote it if your actual path does. Cron uses the server's timezone, so daily jobs' wall-clock times may differ from Copenhagen/India time. Appointment display still uses the application timezone.

If the editor is nano: **Ctrl+O**, Enter, **Ctrl+X** saves/exits. If it is vi: press **i** to edit, then **Esc**, type `:wq`, Enter. Confirm the installed jobs with:

```sh
crontab -l
```

Wait for subsequent scheduled runs and confirm the worker heartbeat continues changing without manually starting it. Use **Linux/SSH cron**, not the control panel's URL cron: the latter opens a web URL and cannot execute these CLI-only scripts. See [Simply.com cron jobs](https://www.simply.com/en/support/faq/general/356-cron-jobs/).

If SSH/Linux cron is unavailable in your account, have Simply.com enable/confirm it before opening bookings. Do not solve this by putting a worker, backup or secret token into a public URL.

## 11. Run readiness checks

In admin, open **Launch checklist** and resolve all blocking items. Check that the worker heartbeat is current, demo mode is off, live sender/recipient are set and all intended service/class combinations have three active tiers.

From the SSH terminal in `public_html`, run:

```sh
/usr/local/bin/php ../scripts/check-release.php --strict
```

Look for `ready: true`, environment `production` and required extensions loaded. The business readiness check cannot prove inbox delivery, DNS correctness or all filesystem permissions; the next step checks the actual deployed behavior.

Confirm `.env.local`, `app`, `database`, `scripts` and `storage` remain above the public root. Browser attempts to `/.env.local`, `/app/bootstrap.php`, `/database/install.sql`, `/scripts/worker.php` and `/storage/backups/` must return denied/not-found responses, never private file contents.

## 12. Perform the live acceptance test

Keep search indexing off. Once configuration is complete, enable **Accept new requests** in admin and run this controlled test using your own real inbox plus synthetic customer details:

1. Open the public site on phone and desktop. Check photos, video, menus and service links.
2. Choose a configured vehicle, package, extra and covered postcode. Verify the quote, currency/tax and available time.
3. Submit a request with your own test inbox address. Verify it appears as **pending confirmation** in admin.
4. Wait for the scheduled worker. Verify the customer acknowledgement arrives and the admin alert reaches its configured recipient.
5. Confirm the booking in admin. Wait for another scheduled run. Verify the customer gets a **second email explicitly confirming the appointment**, with the correct reference/date/time/service/quote.
6. Check **Admin → Email delivery** for the corresponding messages and any errors. `Sent` means the SMTP server accepted the message; confirm actual inbox/spam receipt too.
7. Cancel the test booking; check its status update and capacity release. Test an unsupported-postcode/Other-vehicle enquiry and its admin handling.
8. Test one admin image upload, logout and login. Confirm owner setup remains unavailable.
9. Run `backup.php` once, confirm a new private backup file exists, and download a protected copy.
10. Erase the closed test request's personal data through admin when finished.

Do not rely only on a manually invoked worker: successful scheduled delivery is the proof that confirmation mail will run after you leave the terminal.

If intake or email fails, turn **Accept new requests OFF** while correcting it. Existing saved bookings remain in the database.

## 13. Turn on indexing and complete the launch

After the host acceptance test succeeds:

1. Confirm the final business/legal copy, prices, postcode coverage and opening hours.
2. In admin Settings, enable production search indexing. Keep demo mode off.
3. Open `/robots.txt`: it should no longer disallow the whole website.
4. Open `/sitemap.xml`: it should list the real HTTPS domain, active services and published pages.
5. Check that HTTP/noncanonical-domain visits redirect to the selected HTTPS URL.
6. Submit the sitemap to the business's Google Search Console property if available.
7. Keep the live owner password, APP_KEY, private environment and a recovery copy in secure storage. Create an operator account for daily booking work if needed.

At this point the public site, admin, SQL storage, email confirmations, scheduler and SEO publication have all been checked on the actual host.

## 14. Backups, updates and recovery

The daily backup job writes schema/data snapshots into private `storage/backups`. Also back up `.env.local` and uploaded images separately; a database backup does not include image files. Keep the matching APP_KEY with recovery material, and download protected offsite copies. Establish log rotation and retention for older backups; do not let scheduled archives accumulate indefinitely.

For later updates: back up first; upload changed application files; preserve production `.env.local`, uploads and storage; then run from `public_html`:

```sh
/usr/local/bin/php ../scripts/migrate.php
/usr/local/bin/php ../scripts/check-release.php --strict
```

Repeat the booking/confirmation test after material updates. Do not import the fresh installer into a populated live database and do not rerun first-owner setup.

For recovery, configure a separate **empty** target database, restore the correct images/configuration, and run:

```sh
/usr/local/bin/php ../scripts/restore.php --file=/private/path/backup.jsonl.gz --confirm-database=THE_CONFIGURED_EMPTY_DATABASE
```

Restore refuses a nonempty database and requires exact database-name confirmation. Verify the restored application privately before switching live database configuration. Do not delete a working production database to make a restore fit.

## Troubleshooting

| Symptom | Check / action |
| --- | --- |
| Simply.com welcome page / wrong site | Correct product/DNS, placeholder index, files directly inside public_html |
| Home works but /admin/, API or service URLs return 404 | Upload the supplied .htaccess and preserve its rewrite rules |
| 500 error | Hosting error log; .htaccess syntax/PHP selection; partial upload; missing extensions; file permissions |
| 503 / health cannot connect | Correct private .env.local, exact DB hostname/name/user/password, complete SQL import and PHP modules |
| Redirect loop / wrong domain | Same canonical HTTPS URL in environment/admin, active SSL, origin HTTPS and proxy configuration |
| Setup unavailable | Correct database, setup token length/value, or an owner already exists; use /admin/ after setup |
| No packages or enquiry only | Fresh installer has no catalogue; create models/classes and all three tiers, check active states |
| No times available | Covered postcode, working weekday/date, lead time, closures, duration plus buffer and capacity |
| Pending confirmation emails never send | Linux cron installed, correct path/PHP, current heartbeat, worker.log and SMTP settings |
| SMTP authentication error | Full actual mailbox username/password; verify mailbox login; product/FTP passwords are different |
| Email says Sent but is missing | Check spam and provider mail logs; recipient spelling and sender-domain mail authentication |
| Setup/admin image/session write fails | PHP write access to private storage and public assets/uploads; ask host about account ownership/permissions |
| Browser still shows old homepage | Hard-refresh with Ctrl+F5; confirm the new CSS/JS/video uploaded |

For hosting support, a useful message is: “I am deploying a PHP 8.3/PDO MySQL application. Please confirm my absolute account root, `/usr/local/bin/php` version from `public_html`, required modules and SSH `crontab` access. My email queue needs a private CLI invocation every minute.” Send actual paths/error messages through the support portal, without attaching secrets.
