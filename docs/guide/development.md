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
  lang/admin/en/  English texts of the admin area, one dictionary per topic (the German text is the key)
  vendor/         PHPMailer (bundled, only used for SMTP)
  var/            runtime data: sessions, lock file, error log
  schema.sql      database schema for new installations
bin/              cron.php, install.php, build.sh, deploy.sh, build-config.php, …
config/           config.sample.php → config.php (not in Git)
public/           document root: index.php (front controller), install.php, .htaccess, assets/
dev/              Docker environment, reset.sh, demo.sh, cs.sh
docs/             this website (GitHub Pages, Jekyll)
tests/            unit.php, smoke.sh, browser.mjs, deploy.sh
```

## Tests

```bash
php tests/unit.php      # unit tests, no database needed; CI runs them on PHP 8.3, 8.4 and 8.5
tests/smoke.sh          # several hundred end-to-end checks against the Docker setup (dev/reset.sh first)
node tests/browser.mjs  # the JavaScript in headless Chrome (dev/reset.sh && dev/demo.sh first)
tests/deploy.sh         # the atomic deployment via lftp file:// under load, switch-back on errors (needs lftp, openssl and the database)
```

`tests/unit.php` includes every file in `tests/unit.d/`, `tests/smoke.sh` every file in `tests/smoke.d/`
(one file per topic, see the `README.md` there), so that work on different topics does not change the same
lines.

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

- **Language:** code, comments, commits and documentation in English (the German guide in `docs/de/` in
  German). The admin area is German or English per account, the parent pages come in six languages.
- **Admin texts** are written in German in the code and pass through `__()`: `__('Runde gespeichert.')`
  returns the German text as it is and, for an English account, the entry of the dictionaries in
  `app/lang/admin/en/*.php` (one file per topic, all merged; a text needs exactly one entry, as a second
  one in another file is ignored). Pass the German text as a string literal, so that the unit test finds it;
  placeholders are filled in both languages: `__('{count} Termine', ['count' => $n])`. `__h()` does the same
  for HTML and inserts the values unescaped (for links the caller has built and escaped). JavaScript in the
  admin area uses `L('…')`; its texts belong in `app/lang/admin/en/js.php`, the only dictionary the browser
  gets. A unit test fails for a text without an English entry, for an entry nobody uses and for different
  placeholders. Mails to an account follow its language (`AdminI18n::with()`).
- **Parent texts** pass through `t('key')` with `app/lang/{de,en,pl,ru,tr,uk}.php`; every key must exist in
  every language (a unit test checks this). Ask native speakers to review new Polish, Russian, Turkish and
  Ukrainian texts, and say so in the pull request. Numbers with a unit go through `I18n::plural()`
  (`fmt_hours()`): each language file names its plural rule (`'plural'`: `one`, `pl`, `east-slavic` or `none`)
  and gives as many forms of `'hours'` as the rule needs.
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, …);
  releases are derived from them.
- **Coding standard:** PSR-12 for `app/src`, `bin`, `public` and `config` (the exceptions are in
  `.php-cs-fixer.dist.php`). `dev/cs.sh` checks it with a pinned PHP-CS-Fixer, `dev/cs.sh --fix` applies it; CI
  runs the check in the workflow *Coding standard*.
- **Regular expressions:** routes and checks of a whole value end with `\z`, not `$`, which also matches before
  a final line break (a unit test checks the routes).
- **Database:** only prepared statements; the unit test's SQL audit fails on concatenated variables.
- **Table prefix:** SQL names the tables plainly and `Db::prefix()` adds the prefix. A new table goes into
  `Db::TABLES`, a foreign key is called `fk_…` (it gets the prefix too; to drop one, look its name up with
  `Db::foreignKey()`, as older installations have it without the prefix), no alias is named like a table, and a
  named lock goes through `Db::lock()`. Unit tests check all four.
- **Admin routes:** add a route to `AdminController::ROUTES` with the role it needs as the fourth element
  (`'teacher'` for every signed-in account, `'admin'` for admin accounts only). A route without a known role
  is admin-only. GET routes must not change data; the smoke test opens every GET route of the table and
  fails for a placeholder it cannot fill yet (extend the IDs in `tests/smoke.d/security.sh`).
- **Migrations** (`app/src/Migrations.php`) are additive, so that a rollback to the previous version of the
  same major version keeps working; see [Migrations](#migrations) below for the rules.
- **Legal templates:** after changing `app/templates/pages/*.md`, update `CURRENT_PAGE_HASHES` and move
  the old hashes to `OLD_PAGE_HASHES`, so unedited texts are upgraded automatically. The privacy policy exists in all
  six parent languages for both operator types (`privacy*.{de,en,pl,ru,uk,tr}.md`): a change to the German
  template goes into all twelve files, with the same sections and the German placeholders in the translations
  (a unit test compares them); mark changed translations for a native-speaker review. The unit test tells
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

## Architecture

### Request flow

Every request that is not a file under `public/` reaches `public/index.php`, which loads `app/bootstrap.php`
(configuration, autoloader, error handling; `App\Platform` refuses a PHP that is too old) and calls
`App::run()`:

1. HTTPS redirect (`force_https`) and the security headers.
2. On an installed system, for every request except the two below:
   - `Migrations::run()` brings the database up to date (one request at a time, under `GET_LOCK`); unedited
     legal texts are then replaced by newer templates (see *Legal templates* above).
   - `Cleanup::maybeRun()` runs the database steps of the clean-up, at most every `cleanup_interval_minutes`
     (claimed with a compare-and-set on `settings.last_cleanup`; `cleanup_on_request` turns it off).
   - `CronMonitor::check()` notices a cron job that stopped.
   - After the response, due mails of the outbox go out (where PHP can finish the response first, e.g.
     PHP-FPM; see `mail_queue.after_response`).

   A failure in this step sends an alert mail but does not block the page.
3. A form posted from another site gets 403 before anything is processed.
4. `POST /cron` with `X-Cron-Token` and `POST /health` with `X-Health-Token` are the web cron and the
   deployment's health check; they migrate only after checking their key. Without the header these paths
   are treated as the address of a round (`/<slug>`).
5. `/admin…` goes to `AdminController::dispatch()` (session, CSRF token, route table with roles),
   everything else to `PublicController::dispatch()` (language from `?lang=`, the form or the browser).

Controllers render templates with `View::render()`, which hands the variables to the template. Every value
in the HTML is escaped: with `e()`, by the Markdown renderer, or by `__h()`, which escapes the text (the
values it inserts must already be escaped).

### Locking order

Writes that concern bookings lock rows in one order: **round → its days → slots → bookings**. Deleting or
changing a day, a round or an account (`ScheduleService::LOCKS`) and rebuilding the slots of a day
(`SlotGenerator::sync()`) follow it; an account's deletion first locks the rows of the active admins, so
that one admin always remains (`AccountSecurity`).

A booking runs in `Db::transaction()`: with a booking limit it locks the round
(`BookingService::lockForLimit()`), then the slot together with its day in one joined `SELECT … FOR UPDATE`
(`lockSlot()`), then checks for a booking of the slot. That join is the one place where a slot may be locked
before its day, so a booking can cross a `sync()` of the same day; InnoDB then ends one of them as a deadlock.
Bookings expect this: they retry once on a deadlock or lock wait timeout (`retryOnDeadlock()`) and then report
the slot as taken, and `UNIQUE(slot_id)` is the hard guarantee against double bookings; the teacher's entries
and moves go through the same methods. Changes to days, rounds and accounts (`ScheduleService`, `sync()`) do not
retry. For new code: lock the day before its slots, and wrap new writes that use `lockSlot()` in
`retryOnDeadlock()`. Mails go out after the commit (directly or through the outbox), never inside the
transaction.

Background work takes named locks instead: migrations and the outbox use `GET_LOCK`; reminders and summaries
claim their rows with `UPDATE … WHERE reminded_at IS NULL` and `INSERT IGNORE`, so that two runs at the same
time do not send twice.

### Data lifecycle

The cron job (`bin/cron.php` or `POST /cron`) runs `Cleanup::run()`, queues the summaries and reminders and
sends the outbox; page requests run the same database steps as a fallback. What is deleted when:

| Data | Deleted |
| --- | --- |
| Bookings (name, e-mail address) | at midnight after the day of the appointment; at once when cancelled, and with their day, round or account |
| Rounds | deactivated after their last day if they were in use, deleted `delete_after_hours` later (per round; unset = never deleted) |
| Mail queue (references; for a cancellation by the teacher address, name and appointment) | once sent or no longer needed, with its booking or account; at the latest after 48 hours (`Outbox::MAX_AGE_HOURS`) |
| Rate limits (HMACs of truncated IP addresses, of account names and IDs) | after 24 hours |
| Summary delivery log | after 60 days |
| Admin log | after `audit_log_days` (default 90) |
| Rounds without bookings in the trash (`round_trash`: what the teacher entered, no data of parents) | after 14 days (`RoundTrash::KEEP_DAYS`), or restored |
| Deactivated teacher accounts without rounds in use | after 15 months without login |

New personal data needs its deletion step in `Cleanup::run()` (with a test), a line in this table and in
the privacy templates of both operator types (operated by the school or privately).

### Migrations

`schema.sql` always describes the latest state: a new installation gets it and `Migrations::LATEST`
directly. An existing one runs the missing steps of `Migrations::STEPS` on the next request.

- Add a step under the next number, raise `LATEST` and change `schema.sql` in the same commit. A step that
  has been released is never changed.
- Steps are **additive and repeatable**: `ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`,
  `ADD KEY IF NOT EXISTS`, data updates that give the same result when run again. A new `NOT NULL` column
  needs a default, as the previous version's `INSERT`s do not name it (step 22 shows how to add a nullable
  column whose default only new rows get). An interrupted step can then simply run again, and the previous
  version works with the new schema, because it ignores what it does not know. That is what the one-click
  rollback relies on: it switches the code, not the database.
- PHP work of a step (filling in data) goes into `Migrations::steps()` under its number, like steps 7 and
  10, and must be repeatable as well.
- **Checked automatically.** A unit test rejects what a step must not do (every step except 3, from before the
  rule) and duplicate numbers. `tests/migrations.php` (part of the smoke test) brings the schema of the first
  release up to date with some data, compares it with a new installation (tables, columns, indexes, foreign keys)
  and runs every step since then a second time. `tests/n-1.sh` runs the latest release on the schema of the current code
  (sign-in, booking, moving, cancelling, health check); CI does this for every push.
- A step waits at most 10 seconds for a metadata lock (`lock_wait_timeout`, e.g. an open transaction in
  phpMyAdmin), then gives up; after a minute's pause a later request repeats it. If the database is newer than the code (after
  switching back), admins get one mail and a hint on the dashboard.
- A new table goes into `Db::TABLES`, or it does not get the table prefix; no column may be named like a
  table (unit tests check both).
- **Not** in a minor version: renaming or dropping columns and tables, changing a column's type or
  collation, new `UNIQUE` or foreign key constraints on existing columns, `ON DELETE RESTRICT`. They can
  break the previous version or fail on existing data. They wait for a major version and go in two steps
  (expand, then contract): add the new column and use it, drop the old one only in a later major version.
  Step 3 (renaming `admin_users`, dropping columns) dates from before this rule.
- While the files of an update are uploaded, new code may meet the old schema for a moment. Where that
  matters (a page that must not fail), let the code fall back to the old behaviour until the step has run,
  as `RoundAccess::required()` does. Such a fallback names the migration it bridges and can go with the next
  major version only if the [upgrade path]({{ '/guide/operations/' | relative_url }}#updates) no longer starts
  below that migration: 2.0 is planned to require v1.3.0 (migration 13).
- The steps use MariaDB syntax (10.6 or newer); MySQL is not supported.
- Parallel branches: two branches that add a step under the same number conflict in Git, and keeping both
  blocks when resolving it loses one of them silently (PHP keeps only the last of two equal array keys).
  Renumber one of them instead, and check `main` and the open branches before taking a number.

## Security fixes (maintainers) {#security-fixes}

Vulnerabilities are reported privately (see [Security]({{ '/guide/security/' | relative_url }}#reporting-a-vulnerability)
and [`SECURITY.md`](https://github.com/skornehl/talkslot/blob/main/SECURITY.md)), never in a public issue.
The maintainer handles a report as a GitHub Security Advisory:

1. Confirm the report and assess it in a draft advisory (affected versions, severity).
2. Fix it in the advisory's **temporary private fork**, not in a public branch or pull request, and run the
   unit and smoke tests locally (CI does not run there). Nothing about the issue becomes public before the
   merge.
3. Merge the fix from the advisory, start *Actions → Release* (`release.yml`) right after it and publish the
   advisory with the fixed version (request a CVE if the issue is worth one); link it in the release notes.
   Then update the installations you run.

Only the latest release gets security fixes.

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
