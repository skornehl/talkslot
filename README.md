# TalkSlot

**Parent-teacher conference booking for schools: simple, privacy-friendly, runs on cheap shared hosting.**

Teachers publish time slots, parents book one, without an account, an app or cookies. Built for German
schools (GDPR/DSGVO, TDDDG) and multilingual families.

- **For parents:** open a link or enter a short round code, pick a free slot, done. Change or cancel via a
  personal link; optional confirmation mail with a calendar file. Parent pages in German, English,
  Polish, Russian, Ukrainian and Turkish.
- **For teachers:** any number of booking rounds with several days, breaks and a live preview; see who
  booked when, move or add bookings, print lists, get a summary mail the evening before.
- **For the school:** one admin account invites the teachers and is the responsible party for the legal
  pages. Bookings are deleted automatically the day after the appointment.
- **For the operator:** PHP 8.3+ and MariaDB, no framework, no Composer, no Node, no external CDNs.
  Optional zero-downtime deployment via GitHub Actions and FTPS, including one-click rollback.

> The admin area, mails to teachers and the code comments are in German. Parent-facing pages and mails
> are translated (translations should be reviewed by native speakers).

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
- Only free slots are shown, grouped by day. Required: name and acknowledging the privacy notice; e-mail is
  optional.
- Personal link to **move or cancel**; confirmation and change mails contain an `.ics` file that updates the
  same calendar entry when the booking is moved.
- **Multiple bookings** per round configurable: one per person per round, one per person per day, or allowed.
  Same person = same name (case/spacing ignored) and, if both bookings have one, the same e-mail.
- Optional **"round code required"**: the link alone shows nothing but a code prompt.
- Optional "remember my appointment on this device" (opt-in, `localStorage` only).
- Light/dark design following the device, switchable in the footer.

**Teachers and admin (`/admin`, German user interface)**
- Exactly one admin: invites teachers by e-mail (one-time link, no passwords in mails), deactivates,
  resets or deletes accounts, sees and edits all rounds, can create rounds for a teacher.
- Teachers only see their own rounds (enforced centrally, foreign IDs return 404).
- Rounds: several days, first/last slot, duration, any number of breaks, live preview; add days later;
  block single slots; duplicate; deadlines for booking and changes; ready-to-copy invitation text.
- Bookings: overview, edit, move, delete, add manually (optionally with mail). Changed times never delete
  bookings, conflicts are flagged with ⚠. Print view per round or day.
- Translation of title and description **in the teacher's browser** (Chrome/Edge Translator API with a
  local model, no text leaves the device), manually editable.
- Summary mails: evening before a day and after the booking deadline (time and name only).
- Imprint and privacy policy per language, editable (simple Markdown). The admin's contact details replace
  `[[VERANTWORTLICHE]]` automatically; no round is public until they are filled in.

**Automatic clean-up**
- Bookings of a day are deleted from 0:00 the following day.
- A round is deactivated after its last day and deleted 24 h later (configurable per round).
- Rate-limit data after 24 h, inactive teacher accounts without rounds after 15 months.
- Runs hourly via cron (or the included GitHub workflow), plus as a fallback on page views.

**Operations**
- Errors are caught, logged without personal data and **mailed to the operator** (throttled), including
  "database unreachable" (the site answers 503 with `Retry-After`).
- Database migrations run automatically and only ever add columns/tables.
- Table prefix (default `tp_`), so a database can be shared with other applications.

## Requirements

- PHP 8.3 or newer (tested with 8.3–8.5) with `pdo_mysql`, `mbstring`, `iconv` (and `openssl` for SMTP with TLS)
- MariaDB 10.6 or newer (migrations use MariaDB syntax; MySQL is not supported)
- Apache with `mod_rewrite` (and ideally `mod_headers`); other servers need equivalent rules, see
  `public/.htaccess`
- HTTPS
- A sender address with SPF/DKIM (PHP `mail()` or SMTP; PHPMailer is bundled)

## Quick start (local)

```bash
git clone https://github.com/skornehl/talkslot.git && cd talkslot
cp dev/config.dev.php config/config.php
docker compose -f dev/compose.yml up -d --build
dev/reset.sh          # fresh database, admin: sabrina / Sehr-Sicheres-Dev-Pw1
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
3. **Install:** open `https://your-site/install.php`, enter the install key and create the admin account.
   The installer locks itself and tries to delete itself; remove `install.php` if it is still there.
   Alternatively via SSH: `php bin/install.php <username> [email]`.
4. **Cron:** run `php bin/cron.php` hourly (`5 * * * *`). Without shell access, use the web cron:
   `POST /cron` with header `X-Cron-Token: <HMAC-SHA256 of "cron" with your secret>`; the included
   workflow `.github/workflows/cron.yml` does exactly that.
5. **Legal texts:** log in as admin, fill in *Mein Profil → Angaben für Impressum und Datenschutz*, then
   check *Texte*: replace the remaining `[PLACEHOLDERS]` (server log retention, supervisory authority).
   The web host comes from `hosting` in `config.php`. The templates cover Art. 13 GDPR but do not replace legal advice.

### Go-live checklist

- [ ] HTTPS active, `http://` redirects
- [ ] `install.php` no longer reachable (404); `config/config.php` not readable by others (600/640)
- [ ] `debug` is `false`; `alert_email` set
- [ ] Test booking with e-mail: mail arrives (not in spam)
- [ ] Admin contact details, imprint and privacy policy (all languages) complete
- [ ] Data processing agreement (DPA/AVV) with your web host
- [ ] Teacher invitation tested; test round booked, moved and cancelled on a phone, then deleted

## Deployment with GitHub Actions

Optional, for shared hosting with FTPS. Every push to `main` runs the tests, uploads the new version and
switches over **atomically**:

1. The new version is uploaded completely into its own directory (`<APP_DIR>/releases/<name>/`, assets to
   `<WEB_DIR>/v/<name>/`) while the old one keeps running, and checked for completeness.
2. A pointer file `<APP_DIR>/current` is replaced in one step. Old and new code never run side by side.
3. The site is checked (login page, start page, CSS). On failure it switches straight back and the run
   fails.
4. The last five versions are kept for rollbacks.

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
variables: `APP_NAME`, `HOSTING_DE`, `HOSTING_EN` (web host for the privacy policy), `ALERT_EMAIL`, `MAIL_FROM`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO`, `MAIL_TRANSPORT=smtp` with
`SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` and the secrets `SMTP_USER`, `SMTP_PASS`, `DB_PREFIX`.
Without `FTP_HOST` the deploy, cron and switch workflows do nothing.

Recommended: put the secrets into an environment `production` that only `main` may use
(*Settings → Environments*). After the first deployment open `/install.php`, then
`gh variable delete ALLOW_INSTALL` so the installer is no longer shipped.

**Rollback:** *Actions → Version umschalten → Run workflow* with `previous`, a version name or `list`:

```bash
gh workflow run "Version umschalten"                     # one version back
gh workflow run "Version umschalten" -f target=list      # show available versions
```

The database is not rolled back. That is why migrations (`app/src/Migrations.php`) must only **add**
columns with defaults and tables, so an older version keeps working with a newer schema.

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
    uses: skornehl/talkslot/.github/workflows/deploy.yml@v1.0.0
    with:
      ref: v1.0.0   # same version as above
    secrets: inherit
```

Variables and secrets are the same as above and live in your repository (or its `production`
environment). The rollback workflow works the same way (`switch.yml`, inputs `target` and `ref`).
Set the variables `APP_NAME` (name shown to parents) and `HOSTING_DE`/`HOSTING_EN` (web host for the
privacy policy, replaces `[[HOSTER]]`) there as well.

**Releases:** *Actions → Release* tags the next version based on Conventional Commits
(`feat` → minor, `!` → major, otherwise patch). The footer shows the running version.

## Configuration

All options are documented in [`config/config.sample.php`](config/config.sample.php), among them:

| Option | Default | Meaning |
|---|---|---|
| `app_name` | `TalkSlot` | name in header, mails and alert subjects |
| `languages` | `de, en, pl, ru, uk, tr` | parent-facing languages |
| `timezone` | `Europe/Berlin` | |
| `alert_email` | – | recipient of error alerts (else the admin's address) |
| `hosting` | – | web host per language, replaces `[[HOSTER]]` in the privacy policy |
| `access_link_hours` | `48` | validity of invitation/reset links |
| `summary_evening_hour` | `18` | time of the evening summary |
| `rate_limits` | see file | generous, since many parents share an IP (mobile CGNAT, school Wi-Fi) |

**Adding a language:** copy `app/lang/en.php` to e.g. `app/lang/it.php`, translate it, add the code to
`languages` and a flag `public/assets/flags/it.svg`. Optionally add `app/templates/pages/{imprint,privacy}.it.md`;
otherwise the legal pages fall back to English.

## Security

- PDO prepared statements everywhere (no emulation, strict SQL mode). A unit test parses every database
  call and fails if a variable is concatenated into SQL; smoke tests send SQL-injection payloads to all inputs.
- Consistent output escaping; Markdown is escaped first, links only http(s), mailto or relative.
- CSRF: session token in the admin area, stateless HMAC token with minimum and maximum age on public pages.
- Double bookings prevented twice: `SELECT … FOR UPDATE` in a transaction and `UNIQUE(slot_id)`.
- Change links: 256-bit random tokens, only their SHA-256 hash is stored.
- Admin session: cookie limited to `/admin`, `HttpOnly`, `Secure`, `SameSite=Strict`, idle and absolute
  timeouts, ID regeneration; password changes end all other sessions. No session for anonymous visitors.
- Brute-force protection per shortened IP and per username; uniform responses and timing;
  passwords 12 characters up to 72 bytes (bcrypt).
- Spam protection: honeypot, minimum fill-in time, rate limits on bookings, codes and change links.
- Headers: CSP without `unsafe-inline`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`,
  HSTS, Permissions-Policy, `Cache-Control: no-store`.
- CI and deployment use no third-party GitHub Actions (code is fetched with plain `git`, the token only in a request header).
- No error details in production; the app logs itself with values, quotes and e-mail addresses stripped,
  rotating at 5 MB.

**Reporting vulnerabilities:** please use GitHub's private vulnerability reporting
(*Security → Report a vulnerability*), not public issues.

## Privacy by design

- Data minimisation: a free-text name and an optional e-mail address, nothing else.
- **No cookies on parent pages** (language via `?lang=`, stateless CSRF), so no cookie banner is needed.
- IP addresses only for rate limiting: shortened (IPv4 /24, IPv6 /48), HMAC-hashed, deleted after 24 h.
- No external resources, fonts, scripts or tracking.
- Browser storage only on explicit request (§ 25 TDDDG): "remember my appointment" is an unticked checkbox;
  the light/dark choice stores only `light`/`dark`.
- The mandatory checkbox is worded as *acknowledgement* of the privacy notice, not as consent (Art. 13 GDPR).
- Automatic deletion as described above; summary mails to teachers contain no parent e-mail addresses.

## Tests

```bash
php tests/unit.php      # unit tests, no database needed (also run in CI on PHP 8.3–8.5)
tests/smoke.sh          # ~300 end-to-end checks against the local Docker setup
tests/deploy.sh         # atomic deployment via lftp file:// under load (needs lftp and the local DB)
```

The smoke test covers login and lockout, account management, **data separation between teachers**,
CSRF, honeypot, XSS escaping, SQL injection, **20 parallel bookings on one slot → exactly one succeeds**,
moving, cancelling, booking limits, conflict detection, printing, clean-up and error alerts.

## Project layout

```
app/              application (outside the document root)
  src/            classes (Db, Auth, BookingService, SlotGenerator, Cleanup, Mailer, …)
  src/Controller  public area and admin
  templates/      HTML templates, legal page templates (pages/*.md)
  lang/           parent-facing translations
  vendor/         PHPMailer (bundled, only for SMTP)
  var/            runtime data: sessions, lock file, error log (no personal data)
  schema.sql      database schema
bin/              cron.php, install.php, build.sh, deploy.sh, setup-secrets.sh, …
config/           config.sample.php → config.php (not in Git)
public/           document root: index.php, install.php, .htaccess, assets/
dev/              local Docker environment (PHP 8.5, MariaDB 11.8, Mailpit)
tests/            unit.php, smoke.sh, deploy.sh
```

## Contributing

Issues and pull requests are welcome. Please:

- keep it dependency-free (no framework, no build step) and runnable on shared hosting;
- add tests (`tests/unit.php` or `tests/smoke.sh`) for behaviour changes;
- use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, …);
- make migrations additive (see *Rollback* above).

## License

TalkSlot is licensed under the [GNU Affero General Public License v3.0](LICENSE). If you run a modified
version as a service for others, you must make its source code available to them.

PHPMailer (`app/vendor/phpmailer`) is licensed under LGPL-2.1.
