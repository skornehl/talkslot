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
| `db.ssl_ca` | – | `DB_SSL_CA`; absolute path of a CA certificate for an encrypted connection, see below |

### Encrypted database connection {#database-tls}

Usually not needed: on most shared hosting `db.host` is `localhost` or a name in the hoster's internal
network, and the connection does not leave it. Set `db.ssl_ca` when the database runs elsewhere and the
connection crosses a network you do not control, or when your hoster asks for TLS and gives you a CA
certificate.

`db.ssl_ca` is the **absolute path, on the web server,** of the CA certificate (a PEM file) that signed the
database server's certificate: the file your hoster names, or the system's bundle
(often `/etc/ssl/certs/ca-certificates.crt`) if the certificate comes from a public CA. A file of your own
belongs outside the version directories, which deployments replace, e.g. in the home directory of the
hosting account; PHP must be able to read it. The connection is then encrypted, and it only works if the
server shows a certificate of that CA for exactly the name in `db.host` (a host name, not an IP address,
unless the certificate names it).

If something does not fit, the site answers 503 as for any other database problem, the
[health check]({{ '/guide/deployment/' | relative_url }}#health-check) of a deployment reports
`db_unreachable`, and the alert mail (only with `alert_email`: without the database there is no admin
address) says *Cannot connect to MySQL using SSL* (wrong or unreadable file, certificate for another name)
or *MySQL server has gone away* (the server offers no TLS), followed by a hint about `db.ssl_ca`.

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
| `mail.from_address` | – | sender address (domain with SPF/DKIM); also the envelope sender, so bounces come back to this mailbox (see [Bounces](#bounces)); use a mailbox somebody reads, not a no-reply address | `MAIL_FROM` |
| `mail.from_name` | `TalkSlot` | sender name; follows `APP_NAME` when deployed | `MAIL_FROM_NAME` |
| `mail.reply_to` | – | optional; mails to parents use the teacher's reply address (*Mein Profil*) or else the teacher's e-mail address as reply-to | `MAIL_REPLY_TO` |
| `mail.smtp.host` / `port` | – / `587` | | `SMTP_HOST` / `SMTP_PORT` |
| `mail.smtp.encryption` | `tls` | `tls` (STARTTLS), `ssl` or `none` | `SMTP_ENCRYPTION` |
| `mail.smtp.user` / `pass` | – | | `SMTP_USER` / `SMTP_PASS` (secrets) |
| `alert_email` | – | recipient of error alerts; empty = the admin's address (unavailable while the database is down) | `ALERT_EMAIL` |

### Bounces {#bounces}

A mail the mail server accepted can still turn out to be undeliverable later, e.g. because
of a typo in a parent's address. The receiving server then sends a bounce to the envelope sender, which is
`mail.from_address` (`-f` with `mail()`, the sender with SMTP). TalkSlot never sees it: the booking list marks
only mails the mail server refused right away (*E-Mail nicht zugestellt*). Make sure somebody reads that
mailbox, and tell the teacher when a bounce names a parent's address, so they can reach the parents another
way.

### Addresses with umlauts

Addresses with umlauts in the domain (`eltern@müller.de`) are accepted in every form and in the
configuration. They are stored and sent in their ASCII form (`eltern@xn--mller-kva.de`, punycode), which
mail servers use; the booking list, the print view, the user list and the profile show that form, and the
username of a new account is that form too (signing in and *Passwort vergessen* also take the address with
umlauts). Umlauts or other special letters (ß, é) before the `@` are not accepted: few mail servers can
deliver to such addresses.

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
| `security_events_days` | `30` | security events of an account (see [Admin guide]({{ '/guide/admin/' | relative_url }}#protection-against-misuse)), which only its holder sees, are deleted after this many days. At most 90; agree on the period with the staff council |
| `cron_alert_hours` | `12` | without a completed cron run for this many hours, admins see a warning on the dashboard and the operator gets an alert mail ([cron monitoring]({{ '/guide/operations/' | relative_url }}#cron-monitoring); `0` = off); deployment variable `CRON_ALERT_HOURS` |

## Summaries and accounts {#summaries-and-accounts}

| Option | Default | Meaning | Deployment |
|---|---|---|---|
| `summary_names` | `false` | the school allows names in the summaries for teachers, with a calendar file of the appointments (itself without names); each account then chooses *Mit Namen* in its profile, new ones start without. `false`: per day the number of appointments, anything worth a look and the link to the list only ([Teacher guide]({{ '/guide/teachers/' | relative_url }}#summary-mails)) | `SUMMARY_NAMES` (`1`) |
| `allowed_account_domains` | `[]` | accounts may only use addresses at these domains, e.g. `['schule.example.org']`: the exact domain (umlauts as they are or as punycode, `xn--…`). A new or changed address elsewhere (account, reply address for parents, installer) is refused; an address from before at another domain gets no summaries and no replies of parents until it is changed (instead of such a reply address, the account's e-mail address is used if it is allowed). `[]` = any address | `ALLOWED_ACCOUNT_DOMAINS` (comma-separated) |

Set `summary_names` only where all accounts use work addresses whose deletion rules the school controls,
for instance together with `allowed_account_domains`: summary mails stay in mailboxes and synchronised
calendars that TalkSlot cannot delete.

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
| `login_alarm` | 3 per hour | not a limit: when the `login_user` limit of more accounts than this is reached within the window, all admins get one alarm a day (without names) |
| `reauth` | 5 per 15 min | wrong passwords when confirming a change of admin rights, an e-mail address or ending all sessions, and when changing the own password (one counter per sign-in, not per IP address) |
| `invite` | 20 per hour | invalid invitation and password links |
| `forgot` | 5 per hour | requests under *Passwort vergessen* (forgotten password) |
| `teacher_mail` | 30 per hour | mails to parents a teacher sends from an entry (confirmation, change notice), per account, not per IP address |
| `teacher_bulk` | 200 per hour | change notices to parents after a teacher changed a day or a round, per account; parents above the limit are named to the teacher instead. Together with `teacher_mail` this keeps an account from sending mails to addresses of its choice in bulk |

`csrf_min_age_seconds` (default 2) is the minimum time between loading and submitting a public form, a
simple brake for bots. `csrf_max_age_seconds` (default 14400, 4 hours) is how long a public form and the
access granted by a round code stay valid.
