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

A migration that fails is reported by alert mail; pages keep being served. With the atomic deployment the
[health check]({{ '/guide/deployment/' | relative_url }}#health-check) after switching runs the migrations
first and switches back to the previous version if one fails, if the database cannot be reached or if it
is not MariaDB 10.6 or newer.

On MySQL or a MariaDB before 10.6 a pending migration does not start at all (its MariaDB syntax would fail
half-way): the alert mail and the admins' dashboard name the database server. The cron job reports it as
well: `db_server` is `ok` or `unsupported` in the output of `php bin/cron.php -v` and of the web cron.

Migrations only add columns, tables and indexes, so a rollback to the previous version of the same major
version keeps working ([rules for migrations]({{ '/guide/development/' | relative_url }}#migrations)).
Imprint and privacy texts that were never edited are replaced by newer templates of the operator type
(operated by the school or privately) automatically; edited texts stay untouched, and admins see on the
dashboard and under *Texte* which sections a newer template has changed since the text was based on it, to
take them over (version markers, see
[Privacy and legal]({{ '/guide/privacy/' | relative_url }}#template-updates-for-edited-texts)). On the first
request after the update to the version with version markers, texts that have the wording of the current
templates get them as their markers; for other edited texts the hint lists the sections that differ. If a new template brings new `[PLACEHOLDERS]`, rounds that are
already active stay online; the dashboard lists the placeholders, and the next round can only be activated
once they are filled in.

**Update to the version with the operator type:** an installation whose texts are all still unedited
templates up to v1.6 becomes *operated privately* (what those templates described), and the dashboard tells
the admins. Every other installation has no operator type afterwards: admins see *Bitte Betriebsart wählen*
on the dashboard, and **no round can be activated until an admin has chosen it** under *Texte*. Rounds that
are already active stay online. Plan a minute for this right after the update, see
[Privacy and legal]({{ '/guide/privacy/' | relative_url }}#operated-by-the-school-or-privately).

## Error alerts

Unhandled exceptions, fatal PHP errors, PHP warnings, failed migrations or clean-ups, an unreachable
database and failed mail delivery are caught. Visitors only see a neutral error page (503 with
`Retry-After` if the database is down) in their language, with a link back to the start page and, unless the
database is down, to the legal notice that names the operator; whatever the page had shown so far is
discarded. The operator gets a mail:

- **Recipient:** `alert_email`, otherwise the address of the oldest active admin. Set `alert_email`: while
  the database is down, no address can be read from it.
- **Throttling:** the same error (type and place) at most once per hour, at most 30 mails per day.
  Important alerts (database down, fatal errors) are sent even after the daily limit. The next mail says
  how often the error occurred in between.
- **Content:** time, version, method and path (tokens and round links replaced by "…", no query string),
  the cleaned error message, place and stack trace without arguments. **No personal data.**

Limits: if mail delivery itself is broken, no alert arrives. If the whole site is down (web host, PHP),
it cannot report itself. The hourly cron workflow then fails and GitHub notifies you
(*GitHub → Settings → Notifications → Actions*). A cron job that stops without failing is reported by the
site itself, see [cron monitoring](#cron-monitoring).

## Cron monitoring {#cron-monitoring}

The cron job deletes past bookings and sends the summaries, reminders and queued mails. If it stops,
nothing fails visibly: the clean-up still runs on page views, but later and only when someone visits, and
reminders and summaries may not go out at all (see [mail queue](#mail-queue)). So TalkSlot
records every completed run of `bin/cron.php` or the web cron (`last_cron` in the `settings` table, apart
from `last_cleanup`, which page views set as well) and checks it on page views:

- **No completed run for 12 hours** (`cron_alert_hours`, deployment variable `CRON_ALERT_HOURS`): GitHub
  starts scheduled workflows late when it is busy, in practice often only every 4 to 8 hours instead of
  hourly, so with the included workflow *Cron* a shorter limit would raise alarms all the time. With the
  server's own hourly cron job, 3 hours fit (two missed runs). Admins see a warning on the dashboard with
  the time of the last run and a link to this section, and the operator gets the alert mail *Cronjob läuft
  nicht* (same recipient as the error alerts above). It is sent once per outage and again every 24 hours
  while the outage lasts, with the time of the last run and what to check; no personal data. The next
  completed run ends the warning.
- **No completed run recorded at all** (a new installation, an update while the cron job was already down,
  or a cron job that fails every time): `cron_alert_hours` hours after the monitoring began, the dashboard
  asks the admins
  to set up the cron job, or to look with `php bin/cron.php -v` where it fails. There is no
  mail for this, so that installations relying on page views on purpose (with PHP-FPM or LiteSpeed, see
  [mail queue](#mail-queue)) are not reminded every day.
- **`cron_alert_hours = 0`** turns both off.

**Punctual reminders need a punctual cron job.** Reminders the evening before, the summaries and retries of
mails go out when the cron job runs. With the GitHub workflow they can therefore arrive hours late, e.g. a
reminder for 18:00 only at night. If that matters, set up a cron job at your web host that runs
`php bin/cron.php` every hour (or calls the [web cron]({{ '/guide/installation/' | relative_url }}) with `POST /cron` and the header `X-Cron-Token`,
e.g. with `curl`; just calling the address is not enough), and set `CRON_ALERT_HOURS` (without the GitHub
deployment: `cron_alert_hours` in `config/config.php`) to 3.

The check runs on page views: a site nobody visits cannot report anything, but any visit, by parents as
well, sends the alert mail when it is due. Teachers do not see the warning; only admins can have it fixed.

A cron job that is repaired and stops again later counts as a new outage: it is mailed as soon as
`cron_alert_hours` hours have passed, even if the last mail is less than a day old.

**False alarm after a rollback or a restored backup:** a version from before the cron monitoring does not
record its runs, and a database backup holds the `last_cron` of its time. After a [rollback]({{ '/guide/deployment/' | relative_url }}#rollback)
to such a version and the next deployment, or after restoring a backup, the first page view may therefore
report an outage although the cron job kept running. The next run ends it (with the server's own hourly cron job at the latest after an hour, with the GitHub
workflow possibly hours later);
to avoid the mail, start the cron job once by hand right after such a step (`php bin/cron.php`,
*Run workflow* or `gh workflow run cron.yml`).

### GitHub disables the cron workflow after 60 days {#github-60-days}

If you use the included `cron.yml` in a **public** repository (a fork of TalkSlot or your own
[configuration-only repository]({{ '/guide/deployment/' | relative_url }}#deploying-from-your-own-repository)),
GitHub disables scheduled workflows when the repository has had no activity for 60 days. In a fork,
scheduled workflows are off from the start. The workflow does not fail then, it simply stops running, so
GitHub sends no failure notice; the cron monitoring notices it after `cron_alert_hours`
(12 hours by default).

It stays off until you enable it again: *Actions → Cron → Enable workflow*, or
`gh workflow enable cron.yml`. Then start it once by hand to check it (*Run workflow*, or
`gh workflow run cron.yml`); the warning on the dashboard disappears after the first completed run.

GitHub also delays scheduled runs at busy times, especially at the start of every hour, and may drop some.
That is why `cron.yml` runs at minute 17. As runs still often come hours late, the monitoring warns only
after 12 hours by default.

## Logs

TalkSlot writes its own log to `app/var/php-error.log`; PHP's own logging is switched off because its
messages may contain input. Values in quotes and brackets and e-mail addresses are removed before
writing. The log is rotated at 5 MB (one previous file `php-error.log.1`).

## Backups

Back up the **database** (e.g. `mysqldump --single-transaction`) and `config/config.php`. `app/var/` only
holds sessions, the alert counter and logs and does not need a backup. Take a fresh database backup
before an update whose release notes mention a database migration: a rollback switches the code, not the
database.

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

A new `APP_SECRET` changes the web cron token and the key of the
[health check]({{ '/guide/deployment/' | relative_url }}#health-check). The included workflows derive both
from the same secret; an external cron service needs the new token (see [web cron]({{ '/guide/installation/' | relative_url }})),
otherwise the clean-up only runs on page views and queued mails wait. It also resets the rate limits, and parents have to
reload an open booking form or enter a round code again. Admin sessions, change links and bookings stay
valid.

## Clean-up schedule

| What | When |
|---|---|
| Bookings (name, e-mail) of a day | from 0:00 of the following day |
| Rounds | deactivated after their last day, deleted 24 h later by default (per round, empty = never); only rounds that were in use (active at some point or with an entry by the teacher); drafts that were never active stay |
| Rate-limit data | after 24 hours at the latest |
| Deleted rounds without bookings (trash) | after 14 days |
| Inactive teacher accounts without rounds in use | 15 months after the last login, together with their drafts |
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
  teacher, as JSON in `mail_outbox.payload`, added with schema version 21), never a link or token, and is
  deleted like the others: once sent, after 48 hours at the latest. After an admin deleted an account, it
  keeps no address of the teacher (the mail goes without Reply-To). A cancellation whose appointment has
  begun meanwhile is dropped; one for an appointment within the next 24 hours goes out like a single mail,
  not held back by the hourly limit. Versions with schema version 20 or older drop such entries unsent, so
  a rollback to one of them loses the cancellations still waiting.
- **Nothing gets lost unnoticed.** The booking list shows *E-Mail nicht zugestellt* at an entry whose mail
  failed (with *neuer Versuch folgt* while it is retried), and admins see a notice on the overview when
  mails have waited longer than `cron_alert_hours` (at least two hours), usually because the cron job does not run or the mail server
  keeps refusing. Cancellations are the exception: their booking is gone, so one that is given up after
  48 hours leaves no mark at an entry, only the notice on the overview while it waits.
- **Without a cron job** mails can only go out on page views if PHP runs as PHP-FPM or LiteSpeed: the page
  is delivered first, then up to 10 due mails are sent (`mail_queue.after_response`). With Apache's
  mod_php that is not possible, so set up the cron job; the included `cron.yml` runs it hourly. Without it,
  reminders and summaries are not sent there, and queued mails are given up after 48 hours.

## Troubleshooting

**"TalkSlot kann nicht starten / cannot start" on every page.** The page lists what is wrong: a PHP version
before 8.3, a missing PHP extension, a missing `config/config.php` or a mistake in it (see
[Checking the configuration]({{ '/guide/configuration/' | relative_url }}#checking)). The cron job prints the
same in English. Visitors see it too, as the site cannot work until it is fixed.

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
