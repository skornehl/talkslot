---
title: Documents for the school and the operator
description: Templates for the record of processing, technical and organisational measures, the DPIA check, data breaches, requests of data subjects and an information sheet for teachers.
---

The GDPR asks whoever is responsible for TalkSlot to be able to show how personal data is handled
(accountability, Art. 5(2) GDPR). Most of the facts are already known: they follow from how TalkSlot works.
This page collects them as **templates** that you copy into your own documents and complete. It is not
legal advice: check the result with your data protection officer, and adapt it to your state and your setup.

- Text in `[SQUARE BRACKETS]` is yours to fill in.
- **Operated by the school**: the school is the controller and keeps these documents; the school
  management decides, the school's data protection officer advises. **Operated privately**: you (a person,
  association or independent provider) are the controller for the booking service and keep them; the
  meetings themselves, the summary mails in the teachers' mailboxes and printed lists belong to the school
  (see [Privacy and legal]({{ '/guide/privacy/' | relative_url }}#operated-by-the-school-or-privately)).
  Where the two differ, this page says so.
- Related runbooks: [backup and restore]({{ '/guide/operations/' | relative_url }}#backups),
  [changing secrets]({{ '/guide/operations/' | relative_url }}#changing-secrets),
  [if you suspect misuse]({{ '/guide/security/' | relative_url }}#if-you-suspect-misuse),
  [threat model]({{ '/guide/security/' | relative_url }}#threat-model).

## Checklist before the introduction {#checklist}

| Step | Operated by the school | Operated privately |
|---|---|---|
| Decision | School management decides to use TalkSlot for parent meetings, date: `[DATE]` | You decide to offer it; the teachers' school decides whether its teachers may use it |
| Data protection officer | Involve the school's DPO before the start | If one is appointed for you, involve them; teachers ask the school's DPO |
| Staff council (*Personalrat*) | Involve it before the start: TalkSlot processes staff data (accounts, sign-in times, admin log) and may count as a facility able to monitor staff under your state's staff representation act | Not for a service teachers merely use; their schools may involve theirs. If the users are your own staff (e.g. an independent school or an association): your works council or staff representation (e.g. section 87(1) no. 6 BetrVG, church law) |
| Processors (Art. 28) | Agreements with the web host and, if separate, the mail provider. If someone else runs the server for the school (e.g. a volunteer with their own hosting contract): an agreement with that person, the host as sub-processor (Art. 28(2), (4)) | Agreements with the web host and, if separate, the mail provider |
| Record of processing | [Entry below](#record-of-processing), in the school's record | [Entry below](#record-of-processing), in your own record |
| TOMs | [Description below](#toms) plus the host's TOMs from its agreement | The same |
| DPIA | [Threshold check below](#dpia-check), documented | The same |
| Legal texts | Privacy policy and imprint under *Texte*, all placeholders filled in, translations included | The same |
| Teachers | [Information sheet](#information-sheet-for-teachers), work e-mail addresses (`allowed_account_domains`) | The same; teachers check with their school whether they may use the service |
| Admin accounts | Only school staff or persons bound to confidentiality in writing | Only your staff or persons bound to confidentiality in writing |
| Backups | [Two kinds, with retention](#backups-and-retention) | The same |
| Web host | [Log retention, statistics](#web-host-logs-and-statistics) | The same |

## Record of processing activities (Art. 30 GDPR) {#record-of-processing}

The exception for small organisations (Art. 30(5)) does not apply: the processing is not occasional.

| Field | Entry |
|---|---|
| Name of the processing | Online booking of appointments for parent-teacher meetings (TalkSlot) |
| Controller | School: `[NAME AND ADDRESS OF THE SCHOOL, REPRESENTED BY THE HEAD]`. Privately: `[NAME AND ADDRESS OF THE OPERATOR]` |
| Data protection officer | `[NAME AND CONTACT]` (privately: or "none appointed, not required (Art. 37 GDPR, section 38 BDSG)") |
| Purposes | Booking, changing and cancelling appointments; confirmations, reminders and changes by e-mail; summaries to the teacher; protection against abuse; accounts and administration for teachers and admins; accountability (admin log) |
| Legal basis | School: Art. 6(1)(e), (3) GDPR with `[PROVISION OF THE STATE SCHOOL ACT]` and `[PROVISION OF THE STATE DATA PROTECTION ACT]`; staff data `[STATE PROVISION ON STAFF DATA]`. Privately: Art. 6(1)(b) GDPR (arranging the appointment), (b) and (f) (summaries), (f) (abuse protection); accounts Art. 6(1)(b). Both: "Remember my appointment" in the browser only with consent (section 25(1) TDDDG, Art. 6(1)(a)) |
| Data subjects | Parents and guardians; the children named in a booking; teachers and admins |
| Categories of data | Parents: the name entered (usually the child's name and class), optional e-mail address, appointment, booking language, hash of the personal change link, shortened and hashed IP address (abuse protection). Teachers and admins: user name, display name, e-mail and reply address, optional contact for parents, language, password hash, sign-in time, settings, known-device cookie in their browser, entries in the admin log |
| Special categories (Art. 9) | Not intended. Round titles and descriptions are kept neutral: `[HOW TEACHERS ARE INSTRUCTED]` |
| Recipients | The teacher of the round; the admins; the web host and the mail provider as processors; whoever runs the server for the school, as a processor. Privately: the teacher acts for the school, a controller of its own |
| Third countries | None, unless `[MAIL PROVIDER OUTSIDE THE EU/EEA AND ITS SAFEGUARD]` |
| Retention | Bookings from 0:00 the day after the appointment, at cancellation immediately; queued mails after sending, at most 48 hours; abuse protection data 24 hours; admin log 90 days; record of sent summaries (no names) 60 days; rounds after their last day (`[DELETION PERIOD PER ROUND, DEFAULT 24 HOURS]`); teacher accounts while in use, deactivated ones without rounds in use 15 months after the last sign-in; summary mails with names in the mailbox by the end of the school year (operated privately: recommended, the school's rules apply); error log rotated at 5 MB; configuration backup `[E.G. 12 MONTHS]`, full backup `[AT MOST 14 DAYS]`; web server logs `[RETENTION AT THE HOST]` (details: [Privacy and legal]({{ '/guide/privacy/' | relative_url }}#what-is-stored)) |
| Technical and organisational measures | See [TOMs](#toms) and the processors' agreements |
| Date, responsible person | `[DATE, NAME]` |

The teachers' own processing around the meeting (notes, the summary mails in their mailbox, printed lists)
belongs, as a rule, to the school's record under its existing entry for parent meetings, whichever the operator
type. A teacher who uses a privately run service without the school's approval may become a controller.

## Technical and organisational measures (Art. 32 GDPR) {#toms}

In plain words, for the record and the data protection officer. What the web host does (data centre,
physical access, its own backups) is in its processing agreement; attach its TOMs.

**Confidentiality**
- Access only with a personal account; passwords of at least 12 characters, checked against common passwords
  and the user's own data; limits on failed sign-ins per address, account and device; sessions end after 30
  minutes without activity; admins can end all sessions at once.
- Roles: teachers see only their own rounds (enforced centrally, every other ID returns "not found"); only
  admins see all rounds, accounts, legal texts and the log.
- Parents need no account. Their personal change link is stored only as a hash; where a round requires
  it (*Nur mit Rundencode*, *Round code required*), the round code keeps strangers away from the list of slots.
- Data minimisation: a name (the child's), optionally an e-mail address, nothing else; no tracking, no
  cookies on public pages, nothing loaded from third parties.
- Transport encryption: HTTPS for the site (`force_https`); to the mail server `[TLS WITH MAIL.TRANSPORT SMTP AND MAIL.SMTP.ENCRYPTION / THE HOST'S MAIL SERVER WITH PHP MAIL()]`.
- Summary mails to teachers contain no names unless the school allows them, never e-mail addresses.

**Integrity**
- Forms are protected against cross-site requests; all database access uses prepared statements; input is
  checked and output escaped.
- The admin log records who changed which round, account or text (no names or e-mail addresses of parents,
  bookings with their date only).
- Updates arrive as tested releases (automated tests on every change); `[WITH THE AUTOMATIC DEPLOYMENT: VERSIONS
  ARE SWITCHED ATOMICALLY AND THE PREVIOUS ONE IS KEPT FOR A ROLLBACK]`.

**Availability and resilience**
- Backups: [two kinds with fixed retention](#backups-and-retention), restore tested `[HOW OFTEN]`.
- Monitoring: error reports by e-mail, a warning when the cron job stops, a health check of the deployment.

**Deletion**
- Automatic deletion as in the record above; the clean-up runs with the cron job and on page views.

**Organisation**
- Admin accounts only for `[SCHOOL STAFF / OPERATOR'S STAFF]` or persons bound to confidentiality in writing.
- Accounts of people who leave are deactivated ([offboarding](#offboarding)); the dashboard lists accounts
  nobody has signed in to for a long time.
- Secrets are changed when they may have got out ([runbook]({{ '/guide/operations/' | relative_url }}#changing-secrets)).
- Data breaches follow the [procedure below](#data-breach).
- Responsible for the technical operation: `[NAME, CONTACT]`.

## DPIA threshold check (Art. 35 GDPR) {#dpia-check}

A data protection impact assessment is needed when the processing is likely to result in a high risk. Check your
supervisory authority's list of processing that always needs one: `[LIST OF YOUR AUTHORITY, CHECKED ON]`.
The usual check uses the nine criteria of the European data protection authorities (WP248 rev.01): two or
more usually mean a DPIA is needed, one can be enough in a single case, and if you do without one despite two,
document why. Complete and keep this:

| Criterion | Assessment for TalkSlot |
|---|---|
| Evaluation or scoring | No |
| Automated decisions with legal effect | No |
| Systematic monitoring | No (no tracking; the admin log covers admin actions only) |
| Sensitive or highly personal data | Not intended; risk from free-text titles reduced by the hint to keep them neutral |
| Large scale | No (`[NUMBER]` parents of one school or of the teachers using the service) |
| Matching or combining data sets | No |
| Vulnerable data subjects | Yes in part: children are named; parents may feel they cannot refuse a school tool; teachers are employees |
| Innovative technology | No |
| Prevents data subjects from using a service | No: booking is also possible `[ALTERNATIVE, E.G. BY PHONE OR IN PERSON]` |

Result: `[CRITERIA MET; RESULT WITH REASONS]`. Date: `[DATE]`, checked by: `[NAME]`, DPO
consulted on: `[DATE]`. Repeat the check when something important changes (e.g. names in the summaries
allowed, a mail provider outside the EU, rounds on sensitive topics).

## Information sheet for teachers (Art. 13, 14 GDPR) {#information-sheet-for-teachers}

Teachers are data subjects too. Hand them this sheet with their invitation, completed:

> **TalkSlot: what happens with your data**
>
> **Controller:** `[SCHOOL / OPERATOR]`, contact `[CONTACT]`; data protection officer `[CONTACT]`.
>
> **What is stored:** your user name (usually your e-mail address), display name, e-mail address, an optional
> reply address and contact for parents, your language and settings, your password as a secure hash, the
> time of your last sign-in (admins only see "never", "within the last 12 months" or "more than 12 months
> ago"), failed sign-ins and a shortened, hashed IP address (to limit attempts, 24 hours), and the changes admins make to your account or rounds in the
> admin log (90 days). While you are signed in your browser keeps a session cookie; after a successful sign-in it
> also keeps a known-device cookie for 365 days, so that this device can still sign in when somebody else has used up the limit for your account.
>
> **What for:** your account, your rounds and the bookings in them; mails to you (invitation, password link,
> summaries, security notices about your account); protection against misuse.
>
> **Legal basis:** `[SCHOOL: STATE PROVISION ON STAFF DATA, ART. 6(1)(C)/(E) GDPR / PRIVATELY, TEACHERS USING THE SERVICE: ART. 6(1)(B) GDPR AND (F) (SECURITY OF THE SERVICE) / PRIVATELY, YOUR OWN STAFF: PROVISION ON STAFF DATA]`.
>
> **Who sees it:** the admins `[NAMES OR ROLE]`; parents see your display name, the contact you give, and the reply
> address you give, otherwise your account's e-mail address when they reply to a mail; the web host and the mail provider as
> processors. Third countries: `[NONE / MAIL PROVIDER AND SAFEGUARD]`.
>
> **Summary mails:** by default they give the number of appointments per day and a link, no names. If names
> are allowed and you turn them on, delete those mails once you no longer need them, by the end of the school
> year at the latest. `[SCHOOL'S RULES FOR WORK MAILBOXES]`
>
> **How long:** while you use TalkSlot. The admins delete or deactivate your account when you leave; a deactivated
> account without rounds in use is deleted automatically 15 months after its last sign-in.
>
> **Do you have to provide this?** Without a user name and password there is no account; `[OBLIGATION TO USE IT, IF ANY]`.
>
> **Your rights:** access, rectification, erasure, restriction, objection (where the basis is Art. 6(1)(e) or (f)),
> `[OPERATED PRIVATELY: DATA PORTABILITY]`, and a complaint to `[SUPERVISORY AUTHORITY]`.

## Data breaches (Art. 33, 34 GDPR) {#data-breach}

A personal data breach is any loss of confidentiality, integrity or availability of personal data: an admin
account in the wrong hands, a lost backup, a mail with change links to the wrong person, the database
readable by others.

1. **Contain it**: follow [If you suspect misuse]({{ '/guide/security/' | relative_url }}#if-you-suspect-misuse)
   (end sessions, check accounts, change passwords and secrets).
2. **Report internally, right away**, along this chain: `[ADMIN / TECHNICAL CONTACT]` → `[SCHOOL MANAGEMENT /
   OPERATOR]` → `[DATA PROTECTION OFFICER]`, deputy `[NAME]`. A processor (web host, mail provider, whoever runs the server for the
   school) has to tell the controller without undue delay (Art. 33(2)). Operated privately, you report for the
   booking service (database, its mails, your backups) and tell the schools concerned; a lost printed list or an exposed
   summary in a teacher's mailbox is for the school to report.
3. **Assess the risk** for the people concerned: what data, how many people, who may have seen it, can it be
   undone?
4. **Report to the supervisory authority within 72 hours** of becoming aware of it (weekends and holidays count), unless the breach is
   unlikely to result in a risk: `[SUPERVISORY AUTHORITY, ONLINE FORM]`. At a school, `[WHO REPORTS: SCHOOL
   MANAGEMENT / VIA THE SCHOOL AUTHORITY]`. Late or partial reports are allowed with reasons.
5. **Inform the people concerned** if the risk is high (Art. 34): in plain words, what happened and what they
   can do.
6. **Document every breach**, also those not reported (Art. 33(5)):

| Field | Entry |
|---|---|
| Date and time noticed, by whom | |
| What happened, since when | |
| Data and people concerned (number) | |
| Consequences for them | |
| Measures taken | |
| Reported to the authority (date, reference) or why not | |
| People informed (date, how) or why not | |

## Requests of data subjects {#requests-of-data-subjects}

Answer without undue delay, at the latest within a month (Art. 12(3)). Bookings live only until the day after the appointment, so most
requests concern data that is already gone or a booking still ahead.

- **Identity:** the personal change link is the key to a booking. Do not hand out booking data to somebody who
  only names a child; ask for the link, or answer in person.
- **Access (Art. 15):** the teacher or an admin looks up the booking in the round (name, e-mail address,
  appointment, language). Also check your backups (`[BACKUP RETENTION]`). Summary mails in the teacher's mailbox:
  operated by the school, check them too; operated privately, refer the person to the teacher or the school.
- **Rectification (Art. 16):** the teacher edits the entry; parents can move or cancel themselves until the
  deadline for changes.
- **Erasure (Art. 17):** the teacher deletes the entry (parents can cancel with their link); the booking is
  gone immediately, backups follow with their retention. Summary mails: the teacher deletes them (operated privately:
  ask the teacher or the school).
- **Restriction (Art. 18):** usually settled by the short retention; otherwise note it with the entry and do not use it.
- **Portability (Art. 20):** operated privately only (the basis is Art. 6(1)(b)): name, e-mail address and appointment in a common format.
- **Objection (Art. 21, where the basis is Art. 6(1)(e) or (f)) and not wanting reminders:** in practice the teacher
  removes the e-mail address from the entry or deletes it.
- **Teachers' requests:** the admins see the account under *Benutzer*; the admin log lists changes to it.
- Record each request and answer: `[WHERE]`.

## Backups and retention {#backups-and-retention}

Keep two kinds of backup with a fixed retention, encrypted, and test a restore `[ONCE A YEAR]`:

- **Configuration backup** (`config/config.php` and the database without the tables of parents' data; it does
  contain the teachers' accounts): may be kept for `[E.G. 12 MONTHS]`.
- **Full backup**: kept for at most `[14]` days, then overwritten.

The commands and the restore are in [Operations]({{ '/guide/operations/' | relative_url }}#backups). Ask your
web host how long its own backups keep the database and the files, and enter both retentions in the record
above. The privacy template has no text on backups yet: add a point to section 5, e.g. "Backups: your details can remain
in backups for up to `[DAYS]` days; they are not used there and are then overwritten."

## Web host logs and statistics {#web-host-logs-and-statistics}

TalkSlot keeps no access logs; the web server's are the host's. They contain the parents' change links
(`/b/…`) and round addresses, so:

- Ask the host how long access and error logs are kept; choose the shortest option, ideally with shortened
  IP addresses, and enter it in the privacy policy (the placeholder *[ASK THE HOST FOR THE RETENTION PERIOD AND STATE IT HERE.]*).
- Turn off visitor statistics of the hosting package (e.g. Webalizer, AWStats) for the TalkSlot domain, or
  make sure they neither keep full IP addresses nor show the visited addresses to others.

## Database privileges {#database-privileges}

At runtime TalkSlot needs `SELECT`, `INSERT`, `UPDATE` and `DELETE` on its tables. The installation and the
database migrations, which run automatically on the first request after an update, also need `CREATE`,
`ALTER`, `INDEX`, `REFERENCES` and `DROP`. With your own server or a managed database, grant exactly these on
the database of TalkSlot. On shared hosting there is usually only one user with all privileges; give it
its own database (or at least its own table prefix) and a password used nowhere else.

## Mail delivery

- The privacy template says mails go through the web host's mail server. If you use another provider
  (e.g. a school mail service or Microsoft 365), you need a processing agreement with it, and section 6 of the
  privacy policy has to name it (and any transfer outside the EU/EEA with its safeguard).
- With `mail.transport` `smtp`, use transport encryption to the mail server (`mail.smtp.encryption` `tls` or `ssl`) and a
  provider that delivers with TLS; with PHP `mail()` (the default) ask your host how it sends. Mails to parents contain names, the teacher, the appointment and the personal link.

## Offboarding {#offboarding}

When a teacher or admin leaves:

1. **Rounds:** an admin moves rounds that continue to another teacher (*Runde bearbeiten → Lehrkraft*), or
   deletes them (parents can be told by mail).
2. **Account:** deactivate it under *Benutzer* (it can no longer sign in; deactivated accounts without rounds in
   use are deleted 15 months after the last sign-in), or delete it.
3. **Admins:** take away the admin role before; if they had access to the server, FTP, GitHub or
   `config.php`, [change the secrets]({{ '/guide/operations/' | relative_url }}#changing-secrets).
4. **What they keep outside TalkSlot:** summary mails, printed lists and local copies of backups are deleted or handed
   over.
5. Remove them from the list of admins in your documents.

When the service ends altogether: hand over or delete the data, delete database, files and backups, and end the
processors' agreements.

The dashboard lists active accounts nobody has signed in to for a long time; check them `[EACH TERM]`.
