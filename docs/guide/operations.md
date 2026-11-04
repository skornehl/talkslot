---
title: Operations
description: Updates, migrations, error alerts, logs, backups and troubleshooting.
---

## Updates

**With GitHub Actions** every push to `main`, or a new version number in your configuration-only
repository, deploys the update atomically; see [Deployment]({{ '/guide/deployment/' | relative_url }}).

**By hand:** upload the new `app/`, `bin/` and `public/` (keep `config/config.php` and `app/var/`).
Upload `app/` and `bin/` first and `public/` last. During the upload old and new files are mixed for a
moment; use a quiet time, or switch to the atomic deployment.

Read the [release notes](https://github.com/skornehl/talkslot/releases) before updating.

## Database migrations

There is nothing to run by hand. On the first request after an update TalkSlot raises the schema step
by step (`app/src/Migrations.php`). A database lock makes sure only one request migrates; the others wait
briefly. Fresh installations get the newest schema directly from `app/schema.sql`.

Migrations only add columns (with defaults) and tables, so a rollback to an older version keeps working.
Imprint and privacy texts that were never edited are replaced by newer templates automatically; edited
texts stay untouched and admins see a hint under *Texte* when something needs attention. If a new template
brings new `[PLACEHOLDERS]`, rounds that are already active stay online; the dashboard lists the
placeholders, and the next round can only be activated once they are filled in.

## Error alerts

Unhandled exceptions, fatal PHP errors, PHP warnings, failed migrations or clean-ups, an unreachable
database and failed mail delivery are caught. Visitors only see a neutral error page (503 with
`Retry-After` if the database is down). The operator gets a mail:

- **Recipient:** `alert_email`, otherwise the address of the oldest active admin. Set `alert_email`: while
  the database is down, no address can be read from it.
- **Throttling:** the same error (type and place) at most once per hour, at most 30 mails per day.
  Important alerts (database down, fatal errors) are sent even after the daily limit. The next mail says
  how often the error occurred in between.
- **Content:** time, version, method and path (tokens and round links replaced by "…", no query string),
  the cleaned error message, place and stack trace without arguments. **No personal data.**

Limits: if mail delivery itself is broken, no alert arrives. If the whole site is down (web host, PHP),
it cannot report itself. The hourly cron workflow then fails and GitHub notifies you
(*GitHub → Settings → Notifications → Actions*).

## Logs

TalkSlot writes its own log to `app/var/php-error.log`; PHP's own logging is switched off because its
messages may contain input. Values in quotes and brackets and e-mail addresses are removed before
writing. The log is rotated at 5 MB (one previous file `php-error.log.1`).

## Backups

Back up the **database** (e.g. `mysqldump --single-transaction`) and `config/config.php`. `app/var/` only
holds sessions, the alert counter and logs and does not need a backup.

Keep in mind that bookings are personal data with a short life: restore a backup only as far as
necessary and let the clean-up run afterwards.

## Clean-up schedule

| What | When |
|---|---|
| Bookings (name, e-mail) of a day | from 0:00 of the following day |
| Rounds | deactivated after their last day, deleted 24 h later by default (per round, empty = never) |
| Rate-limit data | after 24 hours at the latest |
| Inactive teacher accounts without rounds | 15 months after the last login |
| Admin log (*Protokoll*) | after 90 days (`audit_log_days`) |

The clean-up runs in the cron job and, as a fallback, on page views at most every 15 minutes. The same
runs send the teachers' summary mails and the parents' reminders.

## Troubleshooting

**503 on every page.** The database is unreachable, or (with the
atomic deployment, plain text "Temporarily unavailable.") no complete version is on the server. Check the alert mail and `app/var/php-error.log`.

**Rounds are not reachable for parents.** The *Angaben für Impressum und Datenschutz* under *Texte* are
missing, the round or the teacher's account is inactive, or the round has ended.

**Parents get "too many attempts".** Many parents may share one IP address (mobile networks, school
Wi-Fi). Raise the [rate limits]({{ '/guide/configuration/' | relative_url }}#rate-limits) if this happens
during a busy booking phase.

**Mails do not arrive.** Check the sender domain's SPF and DKIM records, try `smtp` instead of `mail`, and
look for the alert "Mailversand fehlgeschlagen".

**The privacy policy shows `[HOSTER MIT ANSCHRIFT]`.** Set `hosting` (or `HOSTING_DE`/`HOSTING_EN`); see
[Privacy and legal]({{ '/guide/privacy/' | relative_url }}).
