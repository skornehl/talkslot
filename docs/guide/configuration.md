---
title: Configuration
description: All options of config/config.php, and the matching variables for deployments with GitHub Actions.
---

TalkSlot reads `config/config.php`, a PHP file returning an array. Start from
[`config/config.sample.php`](https://github.com/skornehl/talkslot/blob/main/config/config.sample.php).
When you deploy with GitHub Actions, the file is generated from repository variables and secrets
(right column); see [Deployment]({{ '/guide/deployment/' | relative_url }}).

## Checking the configuration {#checking}

TalkSlot knows every option (`app/src/ConfigSchema.php`) and checks `config.php` on every request:

- **Mistakes it cannot run with** stop the site with a page that names them, in German and English (on the
  command line, in English): `secret` missing or shorter than 32 characters, a `timezone` PHP does not know
  (for example `Europe/Berllin`; this used to fall back to UTC without a word, which moved every deadline,
  the reminders and the clean-up by one or two hours), a language in `languages` without its file in
  `app/lang/`, and a `db.prefix` with other characters than letters, digits and `_`.
- **Options that do not work as written** are listed for admins on the dashboard, without their values:
  unknown keys (for example a typo such as `sumary_evening_hour`, with the option probably meant), values of
  the wrong type or out of range (`'false'` in quotes is not `false`, `summary_evening_hour` 24, a rate limit
  `[0, 900]`), a `default_language` that is not offered, a missing `mail.from_address`, and the transport
  `mail` on a host that has switched off PHP's `mail()`.

The same check covers the [requirements]({{ '/guide/installation/' | relative_url }}#requirements) of the
server: PHP version and extensions on every request, the database server in the installer, on the dashboard and
before a migration.

## General

| Option | Default | Meaning | Deployment |
|---|---|---|---|
| `app_name` | `TalkSlot` | name in the header, page titles, mails and alert subjects | `APP_NAME` |
| `base_url` | – | public address without trailing slash; a path is allowed (`https://example.org/termine`) | `BASE_URL` |
| `force_https` | `true` | redirect plain HTTP | – |
| `debug` | `false` | show error details; never in production | – |
| `timezone` | `Europe/Berlin` | | – |
| `secret` | – | at least 32 random characters; signs tokens and hashes IP addresses | `APP_SECRET` |
| `install_key` | – | at least 16 characters, only for `install.php` | `INSTALL_KEY` (with `ALLOW_INSTALL=1`) |

Changing `secret` only affects short-lived things: forms that are open at that moment, access granted by
a round code in the last hours, the web cron token and the key of the deployment's
[health check]({{ '/guide/deployment/' | relative_url }}#health-check) (the included workflows derive both
again). Bookings, change links, invitation and password links are random tokens and keep working. It does not sign anybody out of the admin area; for that, use
*Benutzer → Alle Sitzungen beenden*.

## Database

| Option | Default | Deployment |
|---|---|---|
| `db.host` / `db.port` | `localhost` / `3306` | `DB_HOST` / `DB_PORT` |
| `db.name` | – | `DB_NAME` (defaults to the user name) |
| `db.user` / `db.pass` | – | `DB_USER` / `DB_PASS` |
| `db.prefix` | `tp_` | `DB_PREFIX`; letters, digits and `_`, empty for no prefix |

## Languages

| Option | Default | Meaning | Deployment |
|---|---|---|---|
| `languages` | `de, en, pl, ru, uk, tr` | parent-facing languages, in the order of the language choice at the top of the page | `LANGUAGES`, e.g. `de,en,tr` |
| `default_language` | `de` | shown until the visitor picks a language; must be in `languages` | `DEFAULT_LANGUAGE` |

The build aborts if a language has no file in `app/lang/` or the default is not offered.

The admin area is in German or English, chosen per account under *Mein Profil*. `admin_language`
(`de` or `en`, variable `ADMIN_LANGUAGE` when deploying) is the default for accounts that have not chosen
and for the login page when the browser asks for neither language. Entries in the admin log are written in
this language.

**Adding a language:** copy `app/lang/en.php` to e.g. `app/lang/it.php` and translate it (`lang.name` is
the language's own name, shown in the language choice), add a flag `public/assets/flags/it.svg` (used in
the translation form of the admin area) and the code to `languages`. Optionally add the legal templates
`app/templates/pages/{imprint,imprint-private,privacy,privacy-private}.it.md`, all four (one per page and
operator type, so that both operated by the school and operated privately get them), with their hashes in
`Migrations::CURRENT_PAGE_HASHES` (the unit test names any missing one); without them the legal pages fall
back to English.
Please have native speakers review translations.

## Mail

| Option | Default | Meaning | Deployment |
|---|---|---|---|
| `mail.transport` | `mail` | `mail` (PHP `mail()`), `smtp` (bundled PHPMailer) or `none` | `MAIL_TRANSPORT` |
| `mail.from_address` | – | sender address (domain with SPF/DKIM) | `MAIL_FROM` |
| `mail.from_name` | `TalkSlot` | sender name; follows `APP_NAME` when deployed | `MAIL_FROM_NAME` |
| `mail.reply_to` | – | optional; mails to parents use the teacher as reply-to | `MAIL_REPLY_TO` |
| `mail.smtp.host` / `port` | – / `587` | | `SMTP_HOST` / `SMTP_PORT` |
| `mail.smtp.encryption` | `tls` | `tls` (STARTTLS), `ssl` or `none` | `SMTP_ENCRYPTION` |
| `mail.smtp.user` / `pass` | – | | `SMTP_USER` / `SMTP_PASS` (secrets) |
| `alert_email` | – | recipient of error alerts; empty = the admin's address (unavailable while the database is down) | `ALERT_EMAIL` |

## Legal pages

| Option | Meaning | Deployment |
|---|---|---|
| `hosting.de` / `hosting.en` | your web host, replaces `[[HOSTER]]` in the privacy policy | `HOSTING_DE` / `HOSTING_EN` |

Example for `hosting.en`: *Example Hosting GmbH, Musterweg 1, 12345 Musterstadt, Germany, as processor
under Art. 28 GDPR. The servers are located in Germany.*

## Sessions, links and clean-up

| Option | Default | Meaning |
|---|---|---|
| `session_idle_minutes` | `30` | admin area: logout after inactivity |
| `session_absolute_hours` | `8` | admin area: maximum session length |
| `access_link_hours` | `48` | validity of invitation and password links |
| `cleanup_on_request` | `true` | also run the clean-up on page views … |
| `cleanup_interval_minutes` | `15` | … at most this often (database steps only: summaries and reminders are queued there, not sent) |
| `summary_evening_hour` | `18` | from this hour, teachers get the summary for the next day and parents the reminder |
| `audit_log_days` | `90` | entries of the admin log (*Protokoll*) are deleted after this many days |
| `cron_alert_hours` | `12` | without a completed cron run for this many hours, admins see a warning on the dashboard and the operator gets an alert mail ([cron monitoring]({{ '/guide/operations/' | relative_url }}#cron-monitoring); `0` = off) |

## Mail queue

Reminders, summaries, mails to parents after a change by a teacher and confirmations that could not be sent
at once wait in a mail queue (outbox) that the cron job sends; see [Operations]({{ '/guide/operations/' | relative_url }}#mail-queue).

| Option | Default | Meaning |
|---|---|---|
| `mail_queue.batch` | `100` | at most this many mails per cron run |
| `mail_queue.seconds` | `20` | time budget per cron run (a mail that has started is finished) |
| `mail_queue.hourly_limit` | `0` | at most this many reminders, summaries and mails to many parents after one change by a teacher per hour, e.g. your host's mail quota (`0` = no limit); single confirmations, change notices and cancellations go first and are never held back |
| `mail_queue.after_response` | `true` | with PHP-FPM or LiteSpeed, a page view also sends up to 10 due mails after its response has gone out |

## Rate limits

`rate_limits` holds `[maximum, window in seconds]` per shortened IP address (IPv4 /24, IPv6 /48), unless
noted otherwise. They are generous on purpose: many parents may share one address (mobile networks, school
Wi-Fi).

| Key | Default | Counts |
|---|---|---|
| `code` | 30 per 15 min | wrong round codes on the start page |
| `attempt` | 150 per hour | all booking attempts, including input errors |
| `booking` | 30 per hour | successful bookings |
| `manage` | 300 per hour | requests to change links |
| `move` | 10 per hour | rebookings by parents (each sends a mail), per booking, not per IP address |
| `confirm` | 2 per 24 hours | confirmation mails parents send to themselves from the appointment page, per booking, not per IP address (the window cannot be longer than 24 hours) |
| `login` | 5 per 15 min | failed admin logins |
| `login_user` | 20 per hour | failed logins **per account**, from devices that have not signed in to it before |
| `login_device` | 20 per hour | failed logins **per known device** (instead of `login_user`) |
| `login_notice` | 1 per day | e-mails to an account whose `login_user` limit was reached |
| `reauth` | 5 per 15 min | wrong passwords when confirming a change of admin rights, an e-mail address or ending all sessions, and when changing the own password (one counter per sign-in, not per IP address) |
| `invite` | 20 per hour | invalid invitation and password links |
| `forgot` | 5 per hour | requests under *Passwort vergessen* (forgotten password) |
| `teacher_mail` | 30 per hour | mails to parents a teacher sends from an entry (confirmation, change notice), per account, not per IP address |
| `teacher_bulk` | 200 per hour | change notices to parents after a teacher changed a day or a round, per account; parents above the limit are named to the teacher instead. Together with `teacher_mail` this keeps an account from sending mails to addresses of its choice in bulk |

`csrf_min_age_seconds` (default 2) is the minimum time between loading and submitting a public form, a
simple brake for bots. `csrf_max_age_seconds` (default 14400, 4 hours) is how long a public form and the
access granted by a round code stay valid.
