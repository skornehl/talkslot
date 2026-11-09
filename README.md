# TalkSlot

**Parent-teacher conference booking for schools: simple, privacy-friendly, runs on cheap shared hosting.**

Teachers publish time slots, parents book one, without an account, an app or cookies. Built for German
schools (GDPR/DSGVO, TDDDG) and multilingual families.

**📖 Documentation and screenshots: <https://skornehl.github.io/talkslot/>** (English and [German](https://skornehl.github.io/talkslot/de/))

- **For parents:** open a link or enter a short round code, pick a free slot, done. Change or cancel via a
  personal link; optional confirmation mail with a calendar file. Parent pages in German, English,
  Polish, Russian, Ukrainian and Turkish, chosen at the top of every page; the invitation text carries a
  direct link per language.
- **For teachers:** any number of booking rounds with several days, breaks and a live preview; see who
  booked when, move or add bookings, print lists, get a summary mail the evening before.
- **For the school:** admins invite the teachers, see all rounds and maintain the legal pages, with
  templates for both ways of running it: by the school itself or privately (a private person, association
  or independent provider whose service teachers use). Bookings are deleted automatically the day after the
  appointment.
- **For the operator:** PHP 8.3+ and MariaDB, no framework, no Composer, no Node, no external CDNs.
  Optional zero-downtime deployment via GitHub Actions and FTPS, including one-click rollback.

> The admin area and mails to teachers are in German or English (per account). Parent-facing pages, mails
> and the privacy policy templates are translated into six languages (translations should be reviewed by
> native speakers; for the privacy policy the German version is authoritative).

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Quick start (local)](#quick-start-local)
- [Installation on a web server](#installation-on-a-web-server)
- [Deployment with GitHub Actions](#deployment-with-github-actions)
- [Configuration](#configuration)
- [Security](#security)
- [Privacy by design](#privacy-by-design)
- [Tests](#tests)
- [Project layout](#project-layout)
- [Contributing](#contributing)
- [License](#license)

## Features

**Parents (public, no login)**
- Round page under a readable URL (e.g. `/parents-evening-2026-k7f3x9q2`) or via an 8-character round code
  (`K7MP-3QXA`) on the start page, handy for paper invitations. Typos like `0`/`O`, dashes, case and Cyrillic
  look-alikes are tolerated.
- Only free slots are shown, grouped by day. Only a name is required, e-mail is optional, and there is
  nothing to tick for the privacy notice: right above the button the form shows who is responsible, the
  purpose and the deletion with a link to the full notice (the rest folded away), and the chosen slot with
  date, time and location. The round page names the booking deadline and the deadline for changes; the
  confirmation page and mails give the date and time until which the booking can be changed online.
- Personal link to **move or cancel**; confirmation and change mails contain an `.ics` file that updates the
  same calendar entry when the booking is moved (titled with the round and the teacher), cancellations one
  that removes it. The confirmation page offers *Copy link*, *Add to
  calendar*, *Share* (where the device has it, with a warning) and the confirmation by e-mail afterwards
  (the address is not stored).
- **Multiple bookings** per round configurable: one per person per round, one per person per day, or allowed.
  Same person = same name (case/spacing ignored) and, if both bookings have one, the same e-mail.
- Location of the meeting (e.g. the room) on the booking page, in the confirmation and the calendar file;
  optional reminder mail the evening before.
- Optional **"round code required"**: the link alone shows nothing but a code prompt.
- Optional "remember my appointment on this device" (opt-in, `localStorage` only): one entry per booking, so
  siblings can be booked from the same device; removed after the day of the appointment.
- Light/dark design following the device, switchable in the footer.

**Teachers and admins (`/admin`, German or English user interface)**
- Admins (one or more): invite teachers and further admins by e-mail (one-time link, no passwords in
  mails), change roles, deactivate, reset or delete accounts, see and edit all rounds, create rounds for a
  teacher, maintain templates and the details for the legal pages. A log (*Protokoll*) shows which admin
  changed what (no names or e-mail addresses of parents, bookings with their date only; deleted after 90 days); from
  there, a logged search finds the data for a parent's request for access (Art. 15 GDPR) by one exact email address.
- Teachers only see their own rounds (enforced centrally, foreign IDs return 404).
- Rounds: several days, first/last slot, duration, any number of breaks, live preview; add days later;
  location (per round, optionally per day); block single slots; duplicate with a new first day; a 14-day trash for rounds deleted without bookings; templates
  (e.g. "parent-teacher day, 08:00–16:00, 15 minutes"); deadlines for booking and changes; ready-to-copy
  invitation text with a direct link per parent language and the round's rule on multiple bookings.
- Bookings: overview, edit, move, delete, add manually (with a mail to the parents, ticked as soon as an
  address is entered). Changed times never delete bookings, conflicts are flagged with ⚠.
- **Parents are told about changes:** deleting a booking, a day, a round or an account sends a
  cancellation (with a calendar file that removes the appointment), a new date, time or location for a
  day and a new location or teacher for the round send the new details, in the parents' language, with
  the box *Eltern per E-Mail informieren* ticked by default. Parents without an address, or whom the
  system never mailed, are named once for the teacher to tell in person. Deleting a day or a round with
  bookings needs an extra tick, checked on the server. Print view per round or day.
- Translation of title and description **in the teacher's browser** (Chrome/Edge Translator API with a
  local model, no text leaves the device), manually editable.
- Summary mails: evening before a day and after the booking deadline, per day the number of appointments,
  anything worth a look (the same name or address twice, no address, undelivered mails, conflicts) and the
  link to the list; names and a calendar file (without names) only where the school allows them.
- Links from the mails: signing in leads back to the page asked for, and a signed-in browser lands signed in
  even from a webmailer (interim page for the `SameSite=Strict` cookie).
- Reminder to parents the evening before, with the link to cancel (only with an e-mail address; can be
  turned off per round; entries made by the teacher only after the parents got a confirmation).
- A mail the mail server refused is retried from a **mail queue** and marked *E-Mail nicht zugestellt*
  in the booking list; admins see mails that wait too long.
- Privacy policy templates in all six parent languages, imprint templates in German and English; a
  translation whose placeholders are still open is not shown (parents get the English text with a notice in
  their language) and does not block activating rounds. Every mail to parents links the privacy policy.
- Imprint and privacy policy per language, editable (simple Markdown), with templates per operator type:
  *operated by the school* (public body, Art. 6(1)(e) GDPR, the school's data protection officer) or
  *operated privately* (Art. 6(1)(b)/(f) GDPR, legal notice of a private, non-commercial service). Unedited
  texts follow the operator type; hints point out texts that do not fit it. Edited texts are never
  overwritten: when an update brings a newer template, admins see which sections changed and can take them
  over section by section, keeping what they filled in. The operator's details replace
  `[[VERANTWORTLICHE]]` automatically; no round is public until they are filled in.

**Automatic clean-up**
- Bookings of a day are deleted from 0:00 the following day.
- A round is deactivated after its last day and deleted 24 h later (configurable per round). This applies
  to rounds that were in use (active at some point or with an entry by the teacher); a draft that was never
  active, e.g. a copy with the old dates, stays. The round page shows the date of the deletion.
- Rate-limit data after 24 h, inactive teacher accounts without rounds in use (with their drafts) after
  15 months, admin log entries after 90 days.
- Runs hourly via cron (or the included GitHub workflow), plus as a fallback on page views (database steps
  only; no visitor waits for the mail server).

**Mail queue**
- Reminders, summaries and mails that failed are sent by the cron job in batches over one SMTP connection,
  confirmations to parents first, failed ones retried with growing pauses for up to 48 hours.
- The queue holds references only (which mail for which booking), no address, text or link; a confirmation
  sent later gets a new link of its own. With PHP-FPM or LiteSpeed page views send a few after the response.

**Operations**
- Errors are caught, logged without personal data and **mailed to the operator** (throttled), including
  "database unreachable" (the site answers 503 with `Retry-After`).
- A cron job that stops is noticed: after 12 hours (configurable) without a completed run, admins see a warning on the
  dashboard and the operator gets an alert mail, daily while it lasts (GitHub disables scheduled workflows
  in public repositories after 60 days without activity).
- Database migrations run automatically and are additive (columns, tables, indexes), so a rollback to the
  previous version of the same major version keeps working.
- Table prefix (default `tp_`) for tables and foreign keys, so a database can be shared with other applications
  or a second TalkSlot.

## Requirements

- PHP 8.3 or newer (end-to-end tests on 8.3 and 8.5, unit tests on 8.3–8.5) with `pdo_mysql`, `mbstring`, `iconv` (and `openssl` for SMTP with TLS)
- MariaDB 10.6 or newer (tested on 10.6 and 11.8; migrations use MariaDB syntax; MySQL is not supported)
- Apache with `mod_rewrite` (and ideally `mod_headers`); other servers need equivalent rules, see
  `public/.htaccess`
- HTTPS
- A sender address with SPF/DKIM (PHP `mail()` or SMTP; PHPMailer is bundled)

TalkSlot checks this itself: an older PHP or a missing extension gets a page that says so (in German and
English, from PHP 7.1 on) instead of a blank 500, and the installer refuses MySQL, MariaDB before 10.6 and a
read-only `app/var/`.

## Quick start (local)

```bash
git clone https://github.com/skornehl/talkslot.git && cd talkslot
cp dev/config.dev.php config/config.php
docker compose -f dev/compose.yml up -d --build
dev/reset.sh          # fresh database, admin: sabrina / Sehr-Sicheres-Dev-Pw1
dev/demo.sh           # optional: demo rounds with bookings
```

App: <http://localhost:8080>, admin: <http://localhost:8080/admin>. Mails end up in Mailpit
(<http://localhost:8025>).

## Installation on a web server

1. **Upload** the project. Only `public/` may be reachable from the web; `app/`, `bin/` and `config/`
   belong outside the document root.
   - If you can choose the document root: upload everything and point it to `public/`.
   - If not: upload the *contents* of `public/` into the document root and everything else into a
     directory next to it, e.g. `talkslot-app/`. Then create `app_root.php` in the document root:
     ```php
     <?php
     return dirname(__DIR__) . '/talkslot-app';
     ```
2. **Configure:** copy `config/config.sample.php` to `config/config.php` and fill in at least `base_url`,
   `secret` (`openssl rand -hex 32`), `db` and `mail`. Set `install_key` (16+ characters) for the next step.
3. **Install:** open `https://your-site/install.php`, enter the install key, create the admin account and
   choose the operator type (operated by the school or privately; it decides the legal templates).
   The installer locks itself and tries to delete itself; remove `install.php` if it is still there.
   Alternatively via SSH: `php bin/install.php <username> [email]` (asks for the password and the type).
4. **Cron:** run `php bin/cron.php` hourly (`5 * * * *`). Without shell access, use the web cron:
   `POST /cron` with header `X-Cron-Token: <HMAC-SHA256 of "cron" with your secret>`; the included
   workflow `.github/workflows/cron.yml` does exactly that.
5. **Legal texts:** log in as admin, fill in *Texte → Angaben für Impressum und Datenschutz* and check the
   operator type there, then check *Texte*: imprint and privacy policy come from the templates of that type
   (the other type's templates can be loaded on the text form). Replace the remaining `[PLACEHOLDERS]` (data
   protection officer, for a school the provisions of your state, server log retention, supervisory
   authority); until then no round can be activated. After updating an installation from before the
   operator type, an admin may have to choose it first (*Bitte Betriebsart wählen* on the dashboard).
   The web host comes from `hosting` in `config.php`. The templates cover Art. 13 GDPR but do not replace legal advice.

### Go-live checklist

- [ ] HTTPS active, `http://` redirects
- [ ] `install.php` no longer reachable (404); `config/config.php` not readable by others (600/640; the
  dashboard warns admins otherwise)
- [ ] `debug` is `false`; `alert_email` set
- [ ] Cron job runs (`php bin/cron.php -v` prints the counts; the dashboard warns admins if it stops)
- [ ] Test booking with e-mail: mail arrives (not in spam)
- [ ] Admin contact details, imprint and privacy policy (all languages) complete, no `[PLACEHOLDERS]` left
- [ ] Operator type (school or private) matches who really runs TalkSlot
- [ ] Data processing agreement (DPA/AVV) with your web host
- [ ] Teacher invitation tested; test round booked, moved and cancelled on a phone, then deleted

## Deployment with GitHub Actions

Optional, for shared hosting with FTPS. Every commit on `main` whose tests have all passed (workflow *Tests*,
the deploy gate) is uploaded and switched over **atomically**:

1. The new version is uploaded completely into its own directory (`<APP_DIR>/releases/<name>/`, assets to
   `<WEB_DIR>/v/<name>/`) while the old one keeps running, and checked for completeness. Right after the
   code, its `config.php` gets the permissions `600` (variable `CONFIG_MODE`: `640` or `keep` if PHP runs
   as a different user than FTP; a server without `chmod` only causes a warning).
2. A pointer file `<APP_DIR>/current` is replaced in one step. Old and new code never run side by side.
3. The site is checked (login page, start page, CSS) and so is its database: the health check `POST /health`
   (key derived from `APP_SECRET`) confirms that it is reachable, MariaDB 10.6+ and fully migrated. On
   failure, e.g. a wrong database password or a failed migration, it switches straight back and the run
   fails.
4. The last five versions are kept for rollbacks; once the check has passed, their `config.php` gets the
   same permissions.

```
<APP_DIR>/current            name of the active version
<APP_DIR>/releases/<name>/   app/, bin/, config/config.php of one version
<APP_DIR>/app/var/           sessions, logs (shared by all versions)
<WEB_DIR>/                   index.php, .htaccess, app_root.php (document root)
<WEB_DIR>/v/<name>/          CSS, JS, images of one version
```

**Setup** (in your fork, with the [GitHub CLI](https://cli.github.com/)):

```bash
bin/setup-secrets.sh    # asks for host, directories, FTP and DB credentials; generates the secrets
```

This sets the variables `BASE_URL`, `FTP_HOST`, `WEB_DIR`, `APP_DIR`, `DB_HOST` and the secrets
`FTP_USERNAME`, `FTP_PASSWORD`, `DB_USER`, `DB_PASS`, `DB_NAME`, `APP_SECRET`, `INSTALL_KEY`. Optional
variables: `APP_NAME`, `HOSTING_DE`, `HOSTING_EN` (web host for the privacy policy), `LANGUAGES` (e.g. `de,en,tr`) and `DEFAULT_LANGUAGE` (e.g. `en`), `ALERT_EMAIL`, `CRON_ALERT_HOURS`, `ROUND_BOOKING_LIMIT`, `DB_SSL_CA` (absolute path of a CA certificate for an encrypted connection to a database on another server), `MAIL_FROM`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO`, `MAIL_TRANSPORT=smtp` with
`SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` and the secrets `SMTP_USER`, `SMTP_PASS`, `DB_PREFIX`; the
variable `CONFIG_MODE` sets the permissions of `config.php` on the server (default `600`).
Without `FTP_HOST` the deploy, cron and switch workflows do nothing.

Recommended: put the secrets into an environment `production` that only `main` may use
(*Settings → Environments*). After the first deployment open `/install.php`, then
`gh variable delete ALLOW_INSTALL` so the installer is no longer shipped.

**Rollback:** *Actions → Switch version → Run workflow* with `previous`, a version name or `list`:

```bash
gh workflow run "Switch version"                         # one version back
gh workflow run "Switch version" -f target=list          # show available versions
gh workflow run "Switch version" -f target=prune         # delete all versions except the active one
```

After changing secrets (database or SMTP password, `APP_SECRET`) run `prune`: older versions keep the old
values in their `config.php`.

**Upgrading from a version without `CONFIG_MODE`:** until then `config.php` kept the FTP server's default
permissions (often `644`) in every version on the server. The first deployment after the update restricts
all of them. If other users of your server could read the file (ask your web host how customers are
separated), change the secrets as described in
[Changing secrets](https://skornehl.github.io/talkslot/guide/operations/#changing-secrets).

The database is not rolled back. That is why migrations (`app/src/Migrations.php`) only **add** columns
with defaults, tables and indexes, so the previous version of the same major version keeps working with the
newer schema. Renaming, dropping or changing the type of a column waits for a major version (see
[Migrations](https://skornehl.github.io/talkslot/guide/development/#migrations)).

### Deploying from your own repository

You do not need a fork. A small private repository with nothing but configuration can deploy a pinned
TalkSlot version with the reusable workflows; updating means changing the version in one place:

```yaml
# .github/workflows/deploy.yml in your repository
name: Deploy
on:
  push:
    branches: [main]
  workflow_dispatch:
permissions:
  contents: read
jobs:
  deploy:
    uses: skornehl/talkslot/.github/workflows/deploy.yml@<commit SHA of the release> # v1.9.0
    secrets:
      APP_SECRET: ${{ secrets.APP_SECRET }}
      INSTALL_KEY: ${{ secrets.INSTALL_KEY }}
      DB_NAME: ${{ secrets.DB_NAME }}
      DB_USER: ${{ secrets.DB_USER }}
      DB_PASS: ${{ secrets.DB_PASS }}
      SMTP_USER: ${{ secrets.SMTP_USER }}
      SMTP_PASS: ${{ secrets.SMTP_PASS }}
      FTP_USERNAME: ${{ secrets.FTP_USERNAME }}
      FTP_PASSWORD: ${{ secrets.FTP_PASSWORD }}
```

Pin the release by its commit SHA with the version as a comment (a tag can be moved; look the commit up with
`git ls-remote https://github.com/skornehl/talkslot refs/tags/v1.9.0`), and pass the secrets one by one
instead of `secrets: inherit` (versions whose `deploy.yml` lists `secrets:` under `workflow_call`; older ones need `secrets: inherit`).
Releases are only created for commits whose tests passed.
Variables and secrets are the same as above and live in your repository (or its `production`
environment). The rollback workflow works the same way (`switch.yml`, input `target`). The workflows fetch the
TalkSlot code of exactly the version in the `uses:` line, so updating means changing that commit and its
comment; Dependabot (`package-ecosystem: github-actions`) can open these pull requests for you.
Set the variables `APP_NAME` (name shown to parents) and `HOSTING_DE`/`HOSTING_EN` (web host for the
privacy policy, replaces `[[HOSTER]]`) there as well.

**Releases:** *Actions → Release* tags the next version based on Conventional Commits
(`feat` → minor, `!` → major, otherwise patch). The footer shows the running version.

## Configuration

All options are documented in [`config/config.sample.php`](config/config.sample.php), among them:

| Option | Default | Meaning |
|---|---|---|
| `app_name` | `TalkSlot` | name in header, mails and alert subjects |
| `languages` | `de, en, pl, ru, uk, tr` | parent-facing languages, in the order of the language choice (variable `LANGUAGES`) |
| `default_language` | `de` | language shown until the visitor picks one; must be in `languages` (variable `DEFAULT_LANGUAGE`) |
| `timezone` | `Europe/Berlin` | |
| `alert_email` | – | recipient of error alerts (else the admin's address) |
| `hosting` | – | web host per language, replaces `[[HOSTER]]` in the privacy policy (translations without their own entry take the English one) |
| `access_link_hours` | `48` | validity of invitation/reset links |
| `summary_evening_hour` | `18` | time of the evening summary |
| `summary_names` | `false` | the school allows names in the summaries (each account chooses, new ones start without; variable `SUMMARY_NAMES`) |
| `allowed_account_domains` | `[]` | accounts may only use addresses at these domains, e.g. `['schule.example.org']` (variable `ALLOWED_ACCOUNT_DOMAINS`) |
| `mail_queue` | see file | mails per cron run (`batch`), seconds per run (`seconds`), reminders, summaries and mails to many parents after a change per hour (`hourly_limit`, `0` = no limit), sending after the response with PHP-FPM (`after_response`) |
| `cron_alert_hours` | `12` | warn admins and mail the operator after this many hours without a completed cron run (`0` = off) |
| `rate_limits` | see file | generous, since many parents share an IP (mobile CGNAT, school Wi-Fi); mails to parents per teacher account and hour (`teacher_mail` from entries, `teacher_bulk` after a change of a day or round) |

Every option is known to `app/src/ConfigSchema.php`. A missing secret, an unknown time zone, a language
without its file or an invalid table prefix stop the site with a message; unknown keys (typos) and invalid
values are listed for admins on the dashboard.

**Adding a language:** copy `app/lang/en.php` to e.g. `app/lang/it.php`, translate it, add the code to
`languages` and a flag `public/assets/flags/it.svg` (admin translation form). Optionally add the legal templates
`app/templates/pages/{imprint,imprint-private,privacy,privacy-private}.it.md` (with their hashes in
`Migrations::CURRENT_PAGE_HASHES`); otherwise the legal pages fall back to English. Set `'plural'` in the
language file to the plural rule of the language (`one`, `pl`, `east-slavic` or `none`) and give the forms of
`'hours'` it needs.

## Security

- PDO prepared statements everywhere (no emulation, strict SQL mode). A unit test parses every database
  call and fails if a variable is concatenated into SQL; smoke tests send SQL-injection payloads to all inputs.
- Consistent output escaping; Markdown is escaped first, links only http(s), mailto or relative.
- CSRF: session token in the admin area, stateless HMAC token with minimum and maximum age on public pages;
  form posts that the browser marks as cross-site (`Sec-Fetch-Site`) are refused up front (also against login CSRF).
- Every admin route names its role in the route table; a route without a known role is admin-only.
- Double bookings prevented twice: `SELECT … FOR UPDATE` in a transaction and `UNIQUE(slot_id)`.
- Change links: 256-bit random tokens, only their SHA-256 hash is stored.
- Admin session: cookie limited to `/admin`, `HttpOnly`, `Secure`, `SameSite=Strict`, idle and absolute
  timeouts, ID regeneration; password changes end all other sessions, an admin can end all sessions of all
  accounts. No session (no file, no cookie) for requests without the cookie; after signing in back to the
  page asked for (admin paths only).
- Admin rights and e-mail addresses change only with the password of the acting admin; security mails to
  the account holder, the previous address and all admins; at least one active admin under a row lock;
  password links are never shown on screen. Password and token parameters are `#[\SensitiveParameter]`.
- Brute-force protection per shortened IP and per account; uniform responses and timing. Devices that signed
  in before keep a signed cookie (only for `/admin/login`) that exempts them from the per-account limit, so
  strangers cannot lock teachers out; the account gets an e-mail when its limit is reached.
- Passwords 12 characters up to 72 bytes (bcrypt), not on a local list of common passwords, no repetitions
  or keyboard runs, without the username, e-mail address or app name.
- Spam protection: honeypot, minimum fill-in time, rate limits on bookings, codes and change links.
- Abuse of the public pages: a generous limit of bookings per round and network, a warning to the teacher and
  the admins when one network books a lot within minutes, deleting several bookings of a day at once; the
  mails parents trigger are limited per address (HMAC-hashed), and the operator hears of an unusual volume.
- Headers: CSP without `unsafe-inline`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`,
  HSTS, Permissions-Policy, `Cache-Control: no-store`, also on 404 and error pages.
- CI and deployment use no third-party GitHub Actions (code is fetched with plain `git`, the token only in a request header).
- No error details in production; the app logs itself with values, quotes and e-mail addresses stripped,
  rotating at 5 MB.

**Reporting vulnerabilities:** please use GitHub's private vulnerability reporting
(*Security → Report a vulnerability*), not public issues.

## Privacy by design

- Data minimisation: a free-text name and an optional e-mail address, nothing else.
- **No cookies on parent pages** (language via `?lang=`, stateless CSRF), so no cookie banner is needed.
  The admin area uses a session cookie and a known-device cookie, both strictly necessary for signing in.
  The browser language only offers a link to that language; the page never switches by itself.
- IP addresses only for rate limiting: shortened (IPv4 /24, IPv6 /48), HMAC-hashed, deleted after 24 h.
- No external resources, fonts, scripts or tracking.
- Admin area and print view are marked `translate="no"`, so browsers do not offer their cloud page
  translation for booking lists; parent pages stay translatable.
- Browser storage only on explicit request (§ 25 TDDDG): "remember my appointment" is an unticked checkbox;
  the light/dark choice stores only `light`/`dark`.
- No checkbox for the privacy notice: Art. 13 GDPR asks to inform, not to confirm. The form gives the
  information in two levels (Art. 12), the full notice exists in every parent language.
- Automatic deletion as described above; summary mails to teachers contain no names unless the school
  allows them, and never parent e-mail addresses. Teachers can give their own reply address for parents.
- The mail queue holds references only; a cancellation by the teacher keeps just what that one mail needs
  (no link) until it is sent, at most 48 hours.

## Tests

```bash
php tests/unit.php      # unit tests, no database needed (also run in CI on PHP 8.3–8.5)
tests/smoke.sh          # several hundred end-to-end checks against the local Docker setup, with tests/integration.php
node tests/browser.mjs  # the JavaScript in headless Chrome (after dev/reset.sh && dev/demo.sh)
tests/deploy.sh         # atomic deployment via lftp file:// under load, switch-back on errors (needs lftp, openssl and the local DB)
dev/phpstan.sh          # static analysis (PHPStan level 6 with a baseline)
```

CI runs all of them on every push: the unit tests on PHP 8.3–8.5, the others against the Docker environment
on PHP 8.5 with MariaDB 11.8, and the smoke and deployment tests once more on the minimum platform (PHP 8.3,
MariaDB 10.6, mail via `mail()`); PHP 7.4 and 8.2 must get the "cannot start" message. It measures
the line coverage of all tests (PCOV, shown in the job summary, changed lines without a test marked in pull
requests, a minimum that must not be undercut) and fails on any PHP warning, notice or deprecation of the app.
Details: [Development](https://skornehl.github.io/talkslot/guide/development/#measuring-instead-of-guessing).

The smoke test covers login and lockout, account management, **data separation between teachers**,
CSRF, honeypot, XSS escaping, SQL injection, **20 parallel bookings on one slot → exactly one succeeds**,
moving, cancelling, booking limits, conflict detection, printing, clean-up and error alerts. Security
invariants are tests too: teachers get 404 for every admin route, no GET route changes data (including
opening a change link), every booking path waits for the lock on the day, and error pages carry the
security headers.

## Project layout

```
app/              application (outside the document root)
  src/            classes (Db, Auth, BookingService, SlotGenerator, Cleanup, Mailer, …)
  src/Controller  public area and admin
  templates/      HTML templates, legal page templates (pages/*.md)
  lang/           parent-facing translations; lang/admin/en/: English texts of the admin area
  data/           list of common passwords (from SecLists, MIT)
  vendor/         PHPMailer (bundled, only for SMTP)
  var/            runtime data: sessions, lock file, error log (no personal data)
  schema.sql      database schema
bin/              cron.php, install.php, build.sh, deploy.sh, setup-secrets.sh, …
config/           config.sample.php → config.php (not in Git)
public/           document root: index.php, install.php, .htaccess, assets/
dev/              local Docker environment (PHP 8.5, MariaDB 11.8, Mailpit)
tests/            unit.php, smoke.sh, integration.php, browser.mjs, deploy.sh, coverage/, phpstan.neon
```

## Contributing

Issues and pull requests are welcome. Please:

- keep it dependency-free (no framework, no build step) and runnable on shared hosting;
- add tests (`tests/unit.php` or `tests/smoke.sh`) for behaviour changes;
- use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, …);
- make migrations additive (see *Rollback* above);
- report security issues privately, not in an issue (see [SECURITY.md](SECURITY.md)).

The [development guide](https://skornehl.github.io/talkslot/guide/development/) describes the architecture
(request flow, locking order, data lifecycle), the conventions and the rules for migrations.

## License

TalkSlot is licensed under the [GNU Affero General Public License v3.0](LICENSE). If you run a modified
version as a service for others, you must make its source code available to them.

PHPMailer (`app/vendor/phpmailer`) is licensed under LGPL-2.1. The list of common passwords
(`app/data/common-passwords.txt`) is derived from [SecLists](https://github.com/danielmiessler/SecLists)
(MIT License, see the file header).
