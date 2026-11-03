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
tests/            unit.php, smoke.sh, deploy.sh
```

## Tests

```bash
php tests/unit.php      # unit tests, no database needed; CI runs them on PHP 8.3, 8.4 and 8.5
tests/smoke.sh          # ~300 end-to-end checks against the Docker setup (dev/reset.sh first)
tests/deploy.sh         # the atomic deployment via lftp file:// under load (needs lftp and the database)
```

The smoke test covers login and lockout, account management, data separation between teachers, CSRF,
honeypot, XSS escaping, SQL injection, 20 parallel bookings on one slot (exactly one succeeds), moving and
cancelling, booking limits, conflict detection, printing, clean-up and error alerts. After a full run no
alert mail may have been sent.

## Conventions

- **Language:** code, comments, commits and documentation in English. The admin area is German; parent
  pages use `app/lang/*.php`, and every key must exist in every language (a unit test checks this).
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, …);
  releases are derived from them.
- **Database:** only prepared statements; the unit test's SQL audit fails on concatenated variables.
- **Migrations** (`app/src/Migrations.php`) only add columns with defaults and tables, so that a rollback
  to an older version keeps working. Raise `LATEST`, add the step, update `schema.sql`.
- **Legal templates:** after changing `app/templates/pages/*.md`, update `CURRENT_PAGE_HASHES` and move
  the old hashes to `OLD_PAGE_HASHES`, so unedited texts are upgraded automatically. The unit test tells
  you the new hashes.
- **Deployment variables:** every variable read by `bin/build-config.php` must be passed in the build step
  of `.github/workflows/deploy.yml`; a unit test checks this too.

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
