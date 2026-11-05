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

## Changing secrets {#changing-secrets}

Change the passwords and the secret in `config.php` if they may have got out, for example when the
dashboard warned that every user of the server could read the file, someone with FTP access has left,
or an old backup is lost.

1. **Set new values at the source:** a new database password at your web host, a new SMTP password at
   your mail provider, a new `APP_SECRET` (`openssl rand -hex 32`). Between the new database password and
   the deployment the site cannot reach the database, so choose a quiet time.
2. **Update the secrets where they are stored and deploy.** In the recommended environment `production`:
   `gh secret set DB_PASS --env production`, likewise `SMTP_PASS` and `APP_SECRET`. Leave out `--env` only
   if your secrets are repository secrets; if you deploy from your
   [own repository]({{ '/guide/deployment/' | relative_url }}#deploying-from-your-own-repository), set
   them there. A secret in the environment takes precedence over a repository secret of the same name, so
   updating the wrong one changes nothing: `gh secret list --env production` shows when each one was last
   updated. Then deploy (*Actions → Deploy → Run workflow*). Without GitHub Actions edit
   `config/config.php` instead.
3. **Delete the old versions.** With the atomic deployment every version on the server keeps its own
   `config.php` with the old values. *Actions → Switch version* with the input `prune` (or
   `bin/deploy.sh prune`) deletes all versions except the active one. A rollback to an older version is
   no longer possible (with a changed database password it would not work anyway).
4. **Check your backups** of `config.php` and replace or delete the old ones.
5. **If the database password may have been read:** look for accounts you do not know under *Benutzer*,
   and sign everyone out by deleting the files in `app/var/sessions/` (with the deployment:
   `<APP_DIR>/app/var/sessions/`). The database holds the teachers' accounts and the parents' bookings, so
   the school has to assess whether this is a personal data breach: it documents every breach
   (Art. 33(5) GDPR) and reports it to the supervisory authority within 72 hours unless it is unlikely to
   put the people concerned at risk; at a high risk they have to be told as well (Art. 33, 34 GDPR).

A new `APP_SECRET` changes the web cron token. The included `cron.yml` derives it from the same secret; an
external cron service needs the new token (see [web cron]({{ '/guide/installation/' | relative_url }})),
otherwise the clean-up only runs on page views and queued mails wait. It also resets the rate limits, and parents have to
reload an open booking form or enter a round code again. Admin sessions, change links and bookings stay
valid.

## Clean-up schedule

| What | When |
|---|---|
| Bookings (name, e-mail) of a day | from 0:00 of the following day |
| Rounds | deactivated after their last day, deleted 24 h later by default (per round, empty = never) |
| Rate-limit data | after 24 hours at the latest |
| Inactive teacher accounts without rounds | 15 months after the last login |
| Admin log (*Protokoll*) | after 90 days (`audit_log_days`) |
| Mail queue entries (no address or content) | once the mail is sent; mails that could not be sent after 48 hours |

The clean-up runs in the cron job and, as a fallback, on page views at most every 15 minutes. The same
runs queue the teachers' summary mails and the parents' reminders; the cron job sends them.

## Mail queue

Apart from the confirmations sent right away (below), no visitor waits for the mail server: page views
only run the database steps of the clean-up, and reminders, summaries and mails that failed go to a
**mail queue** (outbox) that the **cron job** sends (`bin/cron.php` or the web cron). Per run it sends at most 100 mails within about 20 seconds over one SMTP
connection; single confirmations, change notices and cancellations to parents go first, then the mails to
many parents after one change by a teacher (a day or round deleted or moved) and summaries, then reminders
(see [configuration]({{ '/guide/configuration/' | relative_url }}#mail-queue), also for an hourly limit).
Only one run sends at a time, per database, so installations sharing a database server do not hold each
other up; the cron job waits up to 15 seconds for a run in progress.

- **Confirmations stay immediate.** When parents book or move an appointment, or a teacher saves an entry
  with a mail, the mail is sent right away. If the mail server refuses it, it goes to the queue, and the
  page says the confirmation follows. The queue retries after 5, 15, 30, 60, 120 and then every 240 minutes.
- **References only.** A queue entry says which mail is due for which booking or account, nothing else. The
  address and text are taken from the booking when sending, and a confirmation sent later gets a new
  personal link of its own (the first one stays valid), so the queue holds no address and no link. Entries
  go with their booking and are deleted once sent, after 48 hours at the latest.
- **Except cancellations by the teacher.** When a teacher deletes a booking, a day, a round or an admin an
  account, with *Eltern per E-Mail informieren* ticked, the booking is gone before its cancellation is
  sent. Such an entry therefore keeps what this one mail needs (address, language, name, appointment and
  teacher, as JSON in `mail_outbox.payload`, schema version 20), never a link or token, and is deleted like
  the others: once sent, after 48 hours at the latest. A cancellation whose appointment has begun meanwhile
  is dropped. Older versions drop such entries unsent, so a rollback loses at most the cancellations still
  waiting.
- **Nothing gets lost unnoticed.** The booking list shows *E-Mail nicht zugestellt* at an entry whose mail
  failed (with *neuer Versuch folgt* while it is retried), and admins see a notice on the overview when
  mails have waited for more than two hours, usually because the cron job does not run or the mail server
  keeps refusing.
- **Without a cron job** mails can only go out on page views if PHP runs as PHP-FPM or LiteSpeed: the page
  is delivered first, then up to 10 due mails are sent (`mail_queue.after_response`). With Apache's
  mod_php that is not possible, so set up the cron job; the included `cron.yml` runs it hourly. Without it,
  reminders and summaries are not sent there, and queued mails are given up after 48 hours.

## Troubleshooting

**503 on every page.** The database is unreachable, or (with the
atomic deployment, plain text "Temporarily unavailable.") no complete version is on the server. Check the alert mail and `app/var/php-error.log`.

**Rounds are not reachable for parents.** The *Angaben für Impressum und Datenschutz* under *Texte* are
missing, the round or the teacher's account is inactive, or the round has ended.

**Parents get "too many attempts".** Many parents may share one IP address (mobile networks, school
Wi-Fi). Raise the [rate limits]({{ '/guide/configuration/' | relative_url }}#rate-limits) if this happens
during a busy booking phase.

**Mails do not arrive.** Check the sender domain's SPF and DKIM records, try `smtp` instead of `mail`, and
look for the alert "Mailversand fehlgeschlagen". Reminders and summaries are sent by the cron job: if the
overview says mails are waiting, check that it runs (`php bin/cron.php -v` prints `mails_sent`).

**The privacy policy shows `[HOSTER MIT ANSCHRIFT]`.** Set `hosting` (or `HOSTING_DE`/`HOSTING_EN`); see
[Privacy and legal]({{ '/guide/privacy/' | relative_url }}).

**An invitation or password link could not be sent.** The admin only sees a hint; the link is never shown
on screen. Fix the mail delivery (see above), then send it again under *Benutzer → Bearbeiten*.

**A device was lost or an account may be misused.** See
[If you suspect misuse]({{ '/guide/security/' | relative_url }}#if-you-suspect-misuse): end all sessions,
check the accounts and the log, change passwords and, if personal data may be affected, report it in time.
