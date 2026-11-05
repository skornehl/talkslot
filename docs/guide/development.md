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
tests/            unit.php, smoke.sh, integration.php, browser.mjs, deploy.sh, coverage/, phpstan.neon
```

## Tests

```bash
php tests/unit.php      # unit tests, no database needed; CI runs them on PHP 8.3, 8.4 and 8.5
tests/smoke.sh          # ~300 end-to-end checks against the Docker setup (dev/reset.sh first)
php tests/integration.php  # services against the database in a throwaway schema (part of the smoke test)
node tests/browser.mjs  # the JavaScript in headless Chrome (dev/reset.sh && dev/demo.sh first)
tests/deploy.sh         # the atomic deployment via lftp file:// under load, switch-back on errors (needs lftp, openssl and the database)
dev/phpstan.sh          # static analysis with PHPStan (downloads the pinned PHAR into dev/data/)
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

CI runs the unit tests on every push, and the smoke, browser and deployment tests against the Docker environment
(PHP 8.5, MariaDB 11.8, SMTP). The job *Minimum platform* runs the smoke and deployment tests once more on PHP 8.3
and MariaDB 10.6, the minimum, and sends the mails with `mail()` as many web hosts do
(`PHP_VERSION=8.3 MARIADB_VERSION=10.6` for `dev/compose.yml`; Mailpit's sendmail mode receives them). It also
checks that PHP 7.4 and 8.2 get the message of `App\Platform` instead of a syntax error. A push to `main`
deploys only after all of this has passed ([deploy gate]({{ '/guide/deployment/' | relative_url }}#deploy-gate)).

The images are pinned by digest: the test environment in `dev/Dockerfile`, the PHP command line of CI in
`dev/php-cli.Dockerfile` (`dev/php.sh 8.3 php tests/unit.php` runs the unit tests on PHP 8.3 without a local
PHP). Dependabot proposes new digests; which versions are tested is changed by hand in these files.

### Measuring instead of guessing

- **Warnings fail the tests.** The app only logs PHP warnings, notices and deprecations (`app/var/php-error.log`)
  and carries on. `tests/unit.php` turns them into errors; after the smoke test and in every CI job
  `tests/php-errors.sh` fails for any such line of the app in the error log (lines of test code that provoke
  them on purpose do not count).
- **Line coverage.** CI builds the app container with PCOV (`COVERAGE=1 docker compose -f dev/compose.yml up -d
  --build`): `tests/coverage/prepend.php` records the lines of every request and CLI script of the unit, smoke,
  integration, browser and deployment tests, and `tests/coverage/report.php` merges them. The job summary of
  the run shows the coverage per file; for a pull request, changed lines that no test executes are marked in the
  diff. The run fails if the total drops below `COVERAGE_MIN` in `.github/workflows/tests.yml` (raise it when
  the coverage rises) or if no test loads a PHP file at all. Locally, after the tests in such a container:
  `docker compose -f dev/compose.yml exec -w /srv/app app php tests/coverage/report.php`.
- **Static analysis.** `dev/phpstan.sh` runs [PHPStan](https://phpstan.org/) at level 6 (`tests/phpstan.neon`);
  CI does the same. The errors that existed when it was introduced are listed in `tests/phpstan-baseline.neon`;
  every new one fails. Value types of arrays are not required yet. PHPStan is a development tool only: the
  script downloads a pinned version and checks its checksum, the app and its build never contain it.
- **Integration tests** (`tests/integration.php`) call the services directly against the database, without
  HTTP. Every run creates the tables of `schema.sql` under a random prefix (`it<hex>_`) in the configured
  database and drops them at the end; the data of an installation or a running smoke test stay untouched.
  `App\Time::setNow()` fixes the clock, so deadlines and deletion periods are tested without moving
  timestamps in the database (it works in unit tests too).

## Conventions

- **Language:** code, comments, commits and documentation in English. The admin area is German; parent
  pages use `app/lang/*.php`, and every key must exist in every language (a unit test checks this).
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, …);
  releases are derived from them.
- **Database:** only prepared statements; the unit test's SQL audit fails on concatenated variables.
- **Regular expressions on text** use the `u` modifier wherever bytes and characters differ: a non-ASCII
  character in the pattern, `\R`, `\h`, `\v`, `\X`, `\p{…}`, `\x{…}`. Without it they split UTF-8 characters
  (Polish, Russian, Ukrainian names and mails). A unit test checks every pattern in the code.
- **Smoke checks** test HTTP status codes, the database or a stable marker in the template (`data-…`, helper
  `marker` in `tests/smoke.sh`) rather than the wording of a page, which changes. This matters most for checks
  that something is *not* shown: after a rewording they would pass without testing anything.
- **Admin routes:** add a route to `AdminController::ROUTES` with the role it needs as the fourth element
  (`'teacher'` for every signed-in account, `'admin'` for admin accounts only). A route without a known role
  is admin-only. GET routes must not change data; the smoke test opens every GET route of the table and
  fails for a placeholder it cannot fill yet (extend the IDs in `tests/smoke.d/security.sh`).
- **Migrations** (`app/src/Migrations.php`) only add columns with defaults and tables, so that a rollback
  to an older version keeps working. Raise `LATEST`, add the step, update `schema.sql`.
- **Legal templates:** after changing `app/templates/pages/*.md`, update `CURRENT_PAGE_HASHES` and move
  the old hashes to `OLD_PAGE_HASHES`, so unedited texts are upgraded automatically. The unit test tells
  you the new hashes. Edited texts need no new hint in `AdminController::legalHints()`: their version
  markers show admins the changed sections by themselves (`LegalTexts::outdated()`). Keep sections under
  first-level headings (`# 5. …`) and put each paragraph or list item on one line, so the comparison works
  per section and line; a unit test checks that the four privacy templates have the same numbered sections.
  Avoid renaming headings: a section is known by its heading without the number, a renamed one counts as a
  new section, and taking it over does not carry the filled-in details of the old one along. A placeholder
  inside a line stands for text up to the end of that line; only one alone on its line (like
  `[[VERANTWORTLICHE]]`) may stand for several lines. A placeholder may also be filled in by nothing, so a
  line that is little more than a placeholder (`- [WEITERE EMPFÄNGER]`) is filled in by almost any line:
  give such a line some fixed wording of its own (`- **Weitere Empfänger:** [NAME UND ANSCHRIFT]`). Whenever what TalkSlot processes changes (data, recipients, retention periods,
  browser storage, kinds of mail), update the privacy templates of both operator types in German and
  English in the same change.
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
