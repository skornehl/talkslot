---
title: Development
description: Project structure, tests and how to contribute.
---

TalkSlot is deliberately simple: plain PHP 8.3+, no framework, no Composer, no Node, no build step. It must
keep running on shared hosting with only FTP access.

## Project layout

```
app/              application (outside the document root)
  bootstrap.php   shared entry point: config, autoloader, error handling
  src/            classes (Db, Auth, BookingService, SlotGenerator, Cleanup, Mailer, …)
  src/Controller  PublicController (parents), AdminController (/admin)
  templates/      HTML templates; pages/*.md are the legal templates
  lang/           parent-facing translations, one file per language
  vendor/         PHPMailer (bundled, only used for SMTP)
  var/            runtime data: sessions, lock file, error log
  schema.sql      database schema for new installations
bin/              cron.php, install.php, build.sh, deploy.sh, build-config.php, …
config/           config.sample.php → config.php (not in Git)
public/           document root: index.php (front controller), install.php, .htaccess, assets/
dev/              Docker environment, reset.sh, demo.sh
docs/             this website (GitHub Pages, Jekyll)
tests/            unit.php, smoke.sh, browser.mjs, deploy.sh
```

## Tests

```bash
php tests/unit.php      # unit tests, no database needed; CI runs them on PHP 8.3, 8.4 and 8.5
tests/smoke.sh          # ~300 end-to-end checks against the Docker setup (dev/reset.sh first)
node tests/browser.mjs  # the JavaScript in headless Chrome (dev/reset.sh && dev/demo.sh first)
tests/deploy.sh         # the atomic deployment via lftp file:// under load, switch-back on errors (needs lftp, openssl and the database)
```

The smoke test covers login and lockout, account management, data separation between teachers, CSRF,
honeypot, XSS escaping, SQL injection, 20 parallel bookings on one slot (exactly one succeeds), moving and
cancelling, booking limits, conflict detection, printing, clean-up and error alerts. After a full run no
alert mail may have been sent.

The browser test drives headless Chrome (Node 22+, no dependencies) through the JavaScript: time fields and
the slot preview (compared with the slots the server creates), date and break fields, double-submit and
delete confirmations, copying links, the in-browser translation (with a simulated Translator API, including language-pack downloads,
re-translating and error cases), "remember
my appointment" including rejected foreign links, the language choice at phone width (Enter still books,
switching keeps the input, the browser-language hint), error focus and the light/dark switch.

The security invariants (roles in the route table, no data changes by GET, locking on every booking path,
security headers on error pages, refused cross-site form posts) are in `tests/unit.d/security.php` and
`tests/smoke.d/security.sh`; see [Security]({{ '/guide/security/' | relative_url }}).

CI runs the unit tests on every push, and the smoke, browser and deployment tests against the Docker environment.
It also runs the smoke tests against MariaDB 10.6, the minimum (`MARIADB_VERSION=10.6` for `dev/compose.yml`),
and checks that PHP 7.4 and 8.2 get the message of `App\Platform` instead of a syntax error.

## Conventions

- **Language:** code, comments, commits and documentation in English. The admin area is German; parent
  pages use `app/lang/*.php`, and every key must exist in every language (a unit test checks this).
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, …);
  releases are derived from them.
- **Database:** only prepared statements; the unit test's SQL audit fails on concatenated variables.
- **Admin routes:** add a route to `AdminController::ROUTES` with the role it needs as the fourth element
  (`'teacher'` for every signed-in account, `'admin'` for admin accounts only). A route without a known role
  is admin-only. GET routes must not change data; the smoke test opens every GET route of the table and
  fails for a placeholder it cannot fill yet (extend the IDs in `tests/smoke.d/security.sh`).
- **Migrations** (`app/src/Migrations.php`) only add columns with defaults and tables, so that a rollback
  to an older version keeps working. Raise `LATEST`, add the step, update `schema.sql`.
- **Legal templates:** after changing `app/templates/pages/*.md`, update `CURRENT_PAGE_HASHES` and move
  the old hashes to `OLD_PAGE_HASHES`, so unedited texts are upgraded automatically. The unit test tells
  you the new hashes.
- **Deployment variables:** every variable read by `bin/build-config.php` must be passed in the build step
  of `.github/workflows/deploy.yml`; a unit test checks this too.
- **Configuration options:** a new option goes into `App\ConfigSchema::KEYS` (type, default) and
  `config/config.sample.php`; the unit test fails for a key the code reads that the schema does not know, for
  a default that differs from the code's, and for a sample without the key. Admins would otherwise be told
  the option is unknown.
- **Platform check:** `app/bootstrap.php` up to `App\Platform::enforce()`, `app/src/Platform.php`,
  `public/index.php`, `public/install.php` and the scripts in `bin/` that load the bootstrap must stay
  readable for older PHP versions (no named arguments, `match`, `str_starts_with` there), so that they can
  explain that PHP is too old; CI checks this with PHP 7.4.
- **Bundled libraries:** PHPMailer is committed in `app/vendor/`. `composer.json` and `composer.lock` only
  list it so that Dependabot reports new versions and security advisories; Composer is never run. When
  Dependabot raises the version, run `bin/update-vendor.sh` on its branch and commit `app/vendor/`; the unit
  test fails until both match.

## Contributing

Issues and pull requests are welcome on [GitHub](https://github.com/skornehl/talkslot). Please keep it
dependency-free and runnable on shared hosting, add tests for behaviour changes and run the unit and smoke
tests before opening a pull request.

## Working on this website

The site lives in `docs/` and is built by GitHub Pages with Jekyll. To preview it locally:

```bash
cd docs && jekyll serve --baseurl /talkslot
# http://localhost:4000/talkslot/
```

Screenshots come from the local instance with demo data (`dev/reset.sh && dev/demo.sh`).

The site is bilingual. English pages live in `docs/guide/`, German ones in `docs/de/guide/` under the same
file names; the sidebar is in `docs/_data/nav.yml` and the interface strings in `docs/_data/i18n.yml`.
When you change a page, please update the other language too.
