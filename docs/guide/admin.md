---
title: Admin guide
description: Admin accounts set up the platform, invite teachers, see all rounds, maintain templates and the legal texts.
---

The first **admin account** is created during installation; it can make further accounts admins. Admins
see and edit all rounds, create rounds for teachers, manage the accounts and maintain the legal texts. The
operator of the platform is the controller under the GDPR for all rounds: the school if it runs TalkSlot
itself, otherwise the private person, association or independent provider whose service the teachers use
(the *Betriebsart*, operator type, under *Texte*). The admin area is available in German and English (*Mein Profil* →
*Sprache / Language*); labels below are the German ones, with the English label in brackets.

![Admin overview of all rounds]({{ '/assets/img/admin-dashboard.webp' | relative_url }})

## First steps

1. **Profile** (*Mein Profil*): enter your display name (shown to parents for your own rounds) and your
   e-mail address.
2. **Details for imprint and privacy policy** (*Texte → Angaben für Impressum und Datenschutz*): check the
   **operator type** (*Betriebsart*: *Wird von der Schule betrieben* / *Operated by the school* or *Wird
   privat betrieben* / *Operated privately*, chosen during installation) and enter the name of the school or
   operator, who represents it, full address and e-mail address. This text replaces `[[VERANTWORTLICHE]]` in
   both legal pages and is shared by all admins. Without it **no round is publicly reachable**.
3. **Legal texts** (*Texte*): imprint and privacy policy come from the templates of the operator type.
   Review them for every language and replace the remaining `[PLACEHOLDERS]`. Until then no round can be
   activated. Details in [Privacy and legal]({{ '/guide/privacy/' | relative_url }}).
4. **Mail check:** *Texte → E-Mail-Versand* shows the transport and sender. Make a test booking with your
   own e-mail address and check that the confirmation arrives (and is not marked as spam).
5. **Contact for parents** (*Texte → Kontakt für Eltern bei Fragen*, optional): for example the school
   office with its phone number and hours. Parents see it when they are stuck: on the "not found" page, on
   rounds with a round code before the code is entered, after a wrong code on the start page, and on the
   rounds of teachers who have not given a contact of their own in their profile. It is not translated, so
   keep it short and without abbreviations. Without any contact, these places refer parents to the imprint.
6. **Templates** (*Vorlagen*, optional): set up the usual formats once, e.g. your parent-teacher day.
7. **Invite teachers** and, if you like, further admins (*Benutzer*).

If the dashboard warns that the configuration can be read by every user of the server, set the
permissions of the files it lists to `600` or pass the warning on to whoever runs the server: they contain
the database and mail passwords. If these may have got out in the meantime, see
[Changing secrets]({{ '/guide/operations/' | relative_url }}#changing-secrets).

If the dashboard says that the cron job has not completed a run since a given time, or that no completed run
has been recorded yet, check the cron job on the server or the GitHub workflow *Cron*, or pass the warning on to whoever runs
the server; see [cron monitoring]({{ '/guide/operations/' | relative_url }}#cron-monitoring).

If the dashboard says that the server setup needs attention, pass the list on to whoever runs the server: it
names options in `config/config.php` that do not work as written (for example a typo) and a database server
other than MariaDB 10.6 or newer; see
[Checking the configuration]({{ '/guide/configuration/' | relative_url }}#checking).

## Accounts

Under *Benutzer* (users) → *+ Neuer Benutzer* (new user) enter **name and e-mail address** and choose the
**role** (*Rolle*): *Lehrkraft* (teacher, manages their own rounds) or *Admin*. The person
receives an **invitation mail** with a link to set their own password (single use, valid for 48 hours by
default). No password is ever sent by mail. The username is the e-mail address, unless you enter one of
its own under *Benutzername (Login)* (*Username (sign-in)*): that is harder to guess, as parents see the e-mail
address when they reply to a mail; the invitation names it. If the mail cannot be
sent, you only see a hint, not the link (with it you could set the password of somebody else's account):
check the mail delivery (*Texte → E-Mail-Versand*) and send the invitation again.

Summaries, password links and the replies of parents go to the addresses of the accounts. To keep them in
the school's own mailboxes, where its deletion rules apply, the technical side can restrict the addresses to
the school's domains (`allowed_account_domains` in `config/config.php`, see
[Configuration]({{ '/guide/configuration/' | relative_url }}#summaries-and-accounts)). A new or changed
address elsewhere is then refused; accounts that already have one keep working, but get no summaries and
no replies of parents at that address until it is changed (a reply address for parents of that kind gives
way to the account's e-mail address, if that is allowed). The overview tells the account, and admins how
many accounts have such an address.

The column *Letzter Login* (last sign-in) only shows *nie* (never), *in den letzten 12 Monaten* (within
the last 12 months) or *vor über 12 Monaten* (more than 12 months ago), no times: enough to tidy up
accounts, without showing everybody's sign-in times to all admins. The overview tells admins how many
active accounts nobody has used for over 12 months.

For an existing account you can:

- **deactivate** it (*Konto aktiv* unticked): no login, the teacher's rounds are not publicly reachable;
- send a new **invitation** or a **password reset link** (*Einladung erneut senden* / *Link zum
  Zurücksetzen senden*). The old password stops working and all sessions of the account end;
- change its **role**, e.g. make a teacher an admin or the other way round;
- **delete** it, including all its rounds and bookings. With *Eltern per E-Mail informieren* ticked (the
  default), parents of upcoming appointments get a cancellation by e-mail, as when a teacher deletes a round
  (see the [Teacher guide]({{ '/guide/teachers/' | relative_url }})); the dialog gives their number, and the
  message afterwards names those who could not be mailed, so they can be told in person.

Nobody can change their own role, deactivate or delete their own account, and at least one active admin
always remains, even when two admins demote each other at the same moment.

### Protection against misuse

A taken-over session or a misused admin account must not be able to take over other accounts unnoticed:

- **Your password again** (*Ihr Passwort (zur Bestätigung)*) is needed to invite an admin, to give or take
  away admin rights, to deactivate, reactivate or delete an admin account and to change an e-mail address
  (password links go there; this applies to your own address too). After 5 wrong entries in 15 minutes
  (wrong current passwords when changing your own password count too) the confirmation is refused for a
  while; signing out and in again starts afresh.
- **Security mails:** the account holder gets a mail when its password was changed or set; the previous
  address when an admin changed the e-mail address or the username; all active admins (you included) and
  the person concerned when admin rights change or an admin account is deactivated, reactivated or
  deleted. Please do not filter these mails away: they are often the only way to notice misuse.
- **Password links** are only sent by mail and never shown on screen. A reset by an admin tells the person
  that their previous password no longer works.
- **Misuse of the public pages:** when more than 5 bookings of a round come from one network within 5
  minutes, the teacher of the round and all admins get a mail (*Auffällig viele Buchungen in der Runde …*),
  at most one per round and hour. Check the bookings; *Mehrere Buchungen löschen …* under each day deletes
  those of a time span at once, and *Nur mit Rundencode* keeps it from happening again. The operator
  (`alert_email`) gets an alert when parents trigger more than 100 mails in an hour; to one address at most 10
  such mails per hour go out (see [Security]({{ '/guide/security/' | relative_url }}#built-in-protections)).
- **Alle Sitzungen beenden** (end all sessions, at the bottom of *Benutzer*) signs out every account on
  every device except your current session, e.g. after a lost device or a suspected break-in. It needs
  your password, and all admins get a mail. What else to do in such a case is described under
  [Security]({{ '/guide/security/' | relative_url }}#if-you-suspect-misuse).

### When somebody leaves

1. Move the rounds that are still needed to another teacher (field *Lehrkraft* in the round form).
2. Deactivate the account (*Konto aktiv* unticked), or delete it once nothing of it is needed any more.
3. For an admin: take away the role first, and change the secrets they knew (e.g. hosting, database,
   `APP_SECRET`, GitHub).

The hint on the overview about accounts unused for over 12 months helps you not to forget anybody.

## Rounds of all teachers

Admins see and edit **all rounds** (*Alle Runden*) and how they are booked, and can create a round for a
teacher or move a round to another teacher (field *Lehrkraft* in the round form). The round then appears
for that teacher, parents see their name and summaries go to them. Parents who have already booked get a
change notice with the new teacher if *Eltern per E-Mail informieren* stays ticked; the message after saving
names those who could not be mailed. Teachers
only ever see their own rounds; this is enforced centrally and covered by tests.

**Names and e-mail addresses of parents in another teacher's round** stay hidden: such a round shows *gebucht*
(booked), without the print view or the actions on bookings. If you stand in for the teacher, choose
***Vertretung übernehmen*** (take over as substitute) on the round page and confirm with your password. You then
see the parents' data of this round and can edit its bookings until this sign-in ends; a new sign-in or a new
teacher of the round means taking over again. The teacher gets an e-mail (if there is no address, the message says
so and you tell them yourself), and the log says who took over which round. Handing a round with bookings to
another teacher, and deleting a day or a round with upcoming appointments, need this too; a round that is over can
be cleaned up without it. If you change a round without taking it over, the message after saving gives only the
number of parents to inform. Deleting an account with upcoming bookings asks for your password, as the message
afterwards names their parents (nobody else can tell them any more).

How rounds work is described in the [Teacher guide]({{ '/guide/teachers/' | relative_url }}).

## Templates

Under *Vorlagen* (templates) admins set up formats that everybody can pick when creating a round, e.g.
"Elternsprechtag ganzer Tag: 08:00–16:00, 15 minutes, lunch break 12:00–13:00, one appointment per person".
A template holds times, duration, breaks, deadlines, multiple bookings, *Nur mit Rundencode*, the reminder,
location and description. In the new round form, *Vorlage* → *Übernehmen* fills these in; title and date
are entered by hand and everything stays editable.

*Als Vorlage speichern* (save as template) on a round page creates a template from it: the times and
breaks of its first day and the settings of the round. Changing or deleting a template does not affect
rounds created with it.

## Log

*Protokoll* (log) lists the changes made by admin accounts, newest first: who created, edited,
(de)activated, duplicated, moved to another teacher or deleted which round, changed days, breaks, blocked
slots or bookings, created, changed or deleted accounts and roles, sent links, and edited templates or
legal texts. With several admins this makes it traceable who did what. Changes TalkSlot makes itself on an
update are entered as *TalkSlot*: an operator type taken over from unedited templates and legal texts
replaced by newer templates. The admin who then saves the operator type is logged as confirming it, and a
legal text saved after reconciling it with a newer template is logged as such.

The log does **not** record what teachers do in their own rounds, and it never contains data of parents:
a booking appears only as date and time (e.g. "Buchung 12.11.2026 14:00 gelöscht"). Entries are deleted
after 90 days (configurable, `audit_log_days`). Only admins can see the log.

## Legal texts

*Texte* lists imprint (*Impressum*) and privacy policy (*Datenschutz*) per language. They are written in
simple Markdown (headings with `#`, lists, bold, links). Two placeholders are filled in automatically:

| Placeholder | Filled with |
|---|---|
| `[[VERANTWORTLICHE]]` | the details for imprint and privacy policy (*Angaben für Impressum und Datenschutz*, at the top of *Texte*) |
| `[[HOSTER]]` | the web host from the configuration (`hosting`) |

Languages without their own version fall back to English. Both texts have one template per **operator
type** (*Von der Schule betrieben* / *Operated by the school*, *Privat betrieben* / *Operated privately*).
*Vorlage laden* (*Load template*) on the text form puts one of them into the form, the one of the operator
type first; the stored text only changes when you save. If you change the operator type at the top of
*Texte*, TalkSlot replaces every text that is still an unedited template by the templates of the new type
and tells you how many; edited texts stay as they are. The change is recorded in the log.

**Newer templates for edited texts.** TalkSlot never overwrites a text you have edited. Instead it remembers
for every text the template it was stored from or last reconciled with (its *version marker*). When an update
brings a newer template of your operator type, the dashboard and *Texte* name the texts and the sections the
template has changed since, e.g. *Datenschutzerklärung (DE): 5. Speicherdauer und Löschung, 6. Empfänger*,
and the list under *Texte* marks them (*Vorlage geändert*, *template changed*). The text form shows for each
of these sections which lines the template removed (−) and added (+). You can then:

- **take the sections over:** *Abschnitte aus der Vorlage übernehmen* (*Take over the sections from the
  template*) puts your text into the form with these sections as worded in the template. Your details in
  lines the template did not change (e.g. the supervisory authority) are kept, also where they go on in the
  lines below (e.g. name, address and e-mail of the data protection officer), and so is what stands in place
  of `[[VERANTWORTLICHE]]`; your own changes in these sections are not. Whatever of your text is left out
  (own lines, your details in a line the template changed) the notice lists as *nicht übernommen* (*not taken
  over*): copy back what still belongs in the text. A section the template has dropped is removed, and the
  notice names it as removed. The differences stay visible below for comparison. Check the form, fill in new
  `[PLACEHOLDERS]` and save;
- **adjust the text yourself** and tick *Abgleich erledigt* (*Reconciled*) when saving; or
- **load the whole template** with *Vorlage laden* and fill it in again.

Saving after taking over, loading or ticking the box makes the current template the text's version marker, the
note disappears, and the log records who reconciled the text. If the template changed while the form was open
(e.g. by an update), TalkSlot saves the text but does not record the reconciliation, and tells you so. A text
that already has everything the template changed since its version marker (or, without a marker, the wording
of the current template with only its placeholders filled in) needs nothing: TalkSlot records the marker
itself. A placeholder within a line stands for text up to the end of that line: if you have filled one in
over several lines (e.g. the address of the data protection officer below the name), a text without a
marker shows them as a difference until you confirm the reconciliation once. For a text
edited before TalkSlot kept version markers, or one from the template of the other operator type, the template
it started from is unknown: the note then lists the sections in which it differs from the current template
(*weicht von der Vorlage ab*, *differs from the template*; filled-in placeholders do not count), with the lines
only in your text (−) and only in the template (+). This includes sections only your text has: without a
marker it is not known whether they are your own or from an earlier template, so taking over leaves them as
they are (the notice names them as left unchanged); check yourself whether they still apply. Confirm the
reconciliation once, and from then on you only see what later templates change.

If a section in your text has another heading than in the template (e.g. "# 5. How long we keep data"), taking
over adds the template's section as well; delete the old one before saving.

If no operator type is chosen (installations from before it, with edited texts), the dashboard shows
*Bitte Betriebsart wählen* (*Please choose the operator type*), and no round can be activated until an
admin chooses one; rounds that are already active stay online.

Details only you can provide are `[PLACEHOLDERS]` in square brackets. As long as a text of an offered
language still contains one, no round can be activated; rounds that are already active stay online. Hints
on the dashboard and under *Texte* list the open placeholders per text and tell you when a text is missing
a placeholder, is outdated or does not fit the operator type (e.g. the legal bases of the school template
when TalkSlot is operated privately).

## Notifications

Under *Mein Profil* every account chooses its **summary mails** (*Zusammenfassungen per E-Mail*): an
overview of the next day in the evening (from 18:00 by default) and an overview after the booking
deadline of a day. By default they give, per round and day, the number of appointments, anything worth a
look (the same name or address more than once, parents without an address, undelivered mails, conflicts)
and the link to the list, but **no names** and never e-mail addresses of parents; see the
[Teacher guide]({{ '/guide/teachers/' | relative_url }}#summary-mails). The mails stay in mailboxes and
calendars the school's deletion rules may not reach, possibly with providers outside the EU.

Names (and a calendar file of the appointments, itself without names) are only possible if the school
allows them: `summary_names` in `config/config.php` (deployment: variable `SUMMARY_NAMES=1`). Then each
account chooses *Mit Namen* in its profile; new accounts start without. Allow it only if all accounts use
work addresses whose deletion rules the school controls (`allowed_account_domains`), and recommend
deleting the mails by the end of the school year. After the update, accounts from before see a notice on
the overview saying what changed for them. Where names are allowed, their summaries keep them; where not,
they are without names, and once the notice is confirmed the account counts as a new one (names only if
it ticks them).

Parents who gave an e-mail address get a **reminder** the evening before their appointment (at the same
time, from 18:00 by default), with a link to cancel. It can be turned off per round (*Eltern am Vorabend an
ihren Termin erinnern*). The privacy policy template names the reminder as a purpose of the e-mail
address; if you have edited your privacy policy, a hint on the dashboard asks you to add it.

Summaries and reminders are sent by the cron job through the
[mail queue]({{ '/guide/operations/' | relative_url }}#mail-queue); the overview tells admins when mails
have been waiting longer than `cron_alert_hours` (at least two hours).
