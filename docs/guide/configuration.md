---
title: Configuration
description: All options of config/config.php, and the matching variables for deployments with GitHub Actions.
---

TalkSlot reads `config/config.php`, a PHP file returning an array. Start from
[`config/config.sample.php`](https://github.com/skornehl/talkslot/blob/main/config/config.sample.php).
When you deploy with GitHub Actions, the file is generated from repository variables and secrets
(right column); see [Deployment]({{ '/guide/deployment/' | relative_url }}).

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
a round code in the last hours, and the web cron token. Bookings, change links, invitation and password
links are random tokens and keep working. It does not sign anybody out of the admin area; for that, use
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
the translation form of the admin area) and the code to `languages`. Optionally add
`app/templates/pages/imprint.it.md` and `privacy.it.md`; without them the legal pages fall back to English.
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
| `cleanup_interval_minutes` | `15` | … at most this often |
| `summary_evening_hour` | `18` | from this hour, teachers get the summary for the next day and parents the reminder |
| `audit_log_days` | `90` | entries of the admin log (*Protokoll*) are deleted after this many days |

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
| `login` | 5 per 15 min | failed admin logins |
| `login_user` | 20 per hour | failed logins **per account**, from devices that have not signed in to it before |
| `login_device` | 20 per hour | failed logins **per known device** (instead of `login_user`) |
| `login_notice` | 1 per day | e-mails to an account whose `login_user` limit was reached |
| `reauth` | 5 per 15 min | wrong passwords when confirming a change of admin rights, an e-mail address or ending all sessions, and when changing the own password (one counter per sign-in, not per IP address) |

`csrf_min_age_seconds` (default 2) is the minimum time between loading and submitting a public form, a
simple brake for bots.
