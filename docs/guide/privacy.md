---
title: Privacy and legal
description: How TalkSlot handles personal data, and what you need to fill in before going live.
---

TalkSlot was built for schools in Germany, so it follows the GDPR (*DSGVO*) and the German TDDDG closely.
This page explains the design decisions; it is not legal advice.

## What is stored

| Data | Where | Deleted |
|---|---|---|
| Name and optional e-mail address of a booking | database | from 0:00 the day after the appointment, or immediately on cancellation |
| Hash of the personal change link (and of the second link in the reminder mail) | database | with the booking |
| Shortened, HMAC-hashed IP addresses; HMAC hashes of account IDs and known-device cookies with failed logins | database (rate limiting) | after 24 hours at the latest |
| Known-device cookie of an admin or teacher account (random number, time, signature) | that person's browser | after 365 days, renewed at every sign-in |
| Teacher accounts | database | by an admin; inactive accounts without rounds 15 months after the last login |
| Summary mails to teachers (time and name, no e-mail addresses) | teacher's mailbox | the privacy policy promises at most 15 months |
| Log of admin changes (who, when, what; no data of parents) | database | after 90 days (`audit_log_days`) |

TalkSlot keeps **no** access logs of its own. The web server's logs are the web host's responsibility;
ask how long they are kept and state it in the privacy policy.

## Design decisions

- **Data minimisation:** a free-text name and an optional e-mail address, nothing else.
- **E-mail address only for the appointment:** confirmation, changes, cancellation and, if the teacher
  turned it on for the round, a reminder the evening before. The privacy template names all of these.
- **Confirmation by e-mail afterwards:** parents who gave no address when booking can have the
  confirmation sent from the appointment page to an address they enter there. That address is used for
  this one mail and not stored; its footer says so. At most twice per booking within 24 hours (rate limit
  `confirm`, counted per booking), so that a change link cannot turn the school's sender address into a
  mail relay.
- **Entries made by the teacher:** the confirmation mail with the change link is ticked by default as soon
  as an address is entered. It is how parents who never saw the booking form learn about the entry, who is
  responsible and where to find the privacy notice (Art. 14 GDPR).
- **Changes made by the teacher:** when the teacher edits or moves a single booking, a mail with the
  current details goes to the parents by default (if an address is stored; the box can be unticked).
  Deleting a booking, a day, a round or an account, a new date or location for a day and a new location or
  teacher for the round send no mail to the parents yet; confirmation dialogs, form hints and the message
  afterwards say so. A `mailto:` link to the parents was deliberately left out, so that their addresses do
  not end up in private mailboxes through the browser's mail program. After deleting a booking, the
  message names the parents with their address once (the round page showed both before), so that the
  teacher can inform them, ideally from the school's mail account.
- **Admin log without monitoring teachers:** only changes made by admin accounts are logged, so it shows
  who among several admins did what. What teachers do in their own rounds is not recorded, and bookings
  appear only as date and time. Depending on your state, a log of staff actions may still be a matter for
  the staff council (*Personalrat*); you can shorten the retention with `audit_log_days`.
- **Sign-in times only in coarse steps:** the time of the last sign-in is stored (to tidy up unused
  accounts), but the users list only shows *never*, *within the last 12 months* or *more than 12 months
  ago*, so admins do not see when colleagues work. Security mails about account changes name the account
  concerned and who made the change, never data of parents.
- **No cookies on parent pages.** The language travels in the URL (`?lang=`), CSRF protection is
  stateless. No cookie banner is needed. Only the admin area uses cookies: the session cookie, limited to
  `/admin`, and, after signing in, a known-device cookie limited to `/admin/login`. It keeps strangers from
  locking a teacher out of their own devices with wrong passwords (see
  [Security]({{ '/guide/security/' | relative_url }})). Both are strictly necessary for the sign-in the
  teacher asked for, so no consent is needed (§ 25 (2) no. 2 TDDDG); mention them in the information for
  teachers.
  The browser language (`Accept-Language`, sent by every browser anyway) only decides whether a link to
  that language is shown at the top; the page never switches by itself, and nothing is stored.
- **No third parties:** no CDNs, web fonts, analytics or external scripts. Translations of a round's title
  happen in the teacher's browser with a local model; no text is sent to a translation service.
- **No page translation of booking lists:** the admin area and the print view carry `translate="no"`,
  `class="notranslate"` and `<meta name="google" content="notranslate">`, so browsers do not offer their
  page translation there, which would send names and e-mail addresses to a cloud service (a transfer to a
  third country that the privacy policy rules out). Parent pages stay translatable on purpose: parents
  may need a language beyond the six offered and only see their own data there.
- **Remember on this device** (§ 25 TDDDG): storing something in the browser without consent is only
  allowed if it is strictly necessary for a service the user explicitly asked for. Preventing double
  bookings is in the teacher's interest, not the parents', so it would not qualify. That is why the
  "remember my appointment" feature is an **unticked checkbox**. Only when ticked, the browser stores the
  link, label and date of the appointment in `localStorage`, one entry per booking (siblings). Nothing is
  sent to the server. Entries are removed after a cancellation, when their link no longer finds a booking
  and on the first page view after the day of the appointment, the day the booking itself is deleted.
- **Keeping the change link:** the appointment page offers *Copy link* and *Add to calendar* (the
  calendar file is answered to a POST button, so there is no second address with the token that browsers
  or calendars could fetch on their own) and, where the device has it, *Share …* with the hint that anyone
  holding the link can change the appointment.
- **Light/dark choice:** only stored when chosen explicitly (`light` or `dark`); strictly necessary for
  that function, so no consent is needed. "Automatic" removes the entry.
- **Booking limits** only compare with bookings of the same round, using data that is stored anyway. The
  rejection does not reveal when or by whom a slot was booked.
- **Acknowledgement, not consent:** the privacy notice does not need to be "accepted" under the GDPR; it
  informs (Art. 13). The required checkbox is worded as *having read* the notice, and the notice states
  that it is not consent.

## Legal templates

Imprint and privacy policy templates in German and English are copied into the database at installation
and can be edited by admins under *Texte* (*Texts*). The privacy template covers the information required
by Art. 13 GDPR: controller, data protection officer, purposes, legal bases, recipients, third countries,
retention, rights of data subjects including the right to object, right to lodge a complaint, whether
providing data is required, and automated decision-making.

The privacy policy comes in two variants. On the text form, *Vorlage laden* (*Load template*) puts the
matching one into the form; it only replaces the stored text when you save.

| Variant | For | Legal bases |
|---|---|---|
| *Öffentliche Schule* (*Public school*), installed | a state school as controller | Art. 6(1)(e) and (3) GDPR in conjunction with the school act of your state (bookings, mails, summaries) and its data protection act (abuse protection, server logs); right to object under Art. 21, no right to data portability (Art. 20(3)) |
| *Privater Träger* (*Private operator*) | e.g. an independent school or a parents' association | Art. 6(1)(b) and (f) GDPR; right to object for (f), right to data portability |

A public authority cannot rely on legitimate interests (Art. 6(1), second subparagraph, GDPR), and meetings
at a state school are part of its statutory tasks, not of a contract. That is why the public school variant
cites the law of your state. Which provisions apply differs from state to state, so the template leaves them
as placeholders.

Before going live:

1. **Who is responsible:** at a state school the school is the controller, and the school management
   decides to use TalkSlot. If someone else runs the server for the school (e.g. a volunteer with their own
   hosting contract), the school needs an agreement under Art. 28 GDPR with that person (instructions,
   confidentiality, the web host as sub-processor, handover of all access, deletion when operation ends),
   or the school (or its school authority) concludes the hosting contract itself.
2. **Controller:** fill in *Texte → Angaben für Impressum und Datenschutz*. These details replace
   `[[VERANTWORTLICHE]]`; without them no round is public.
3. **Web host:** set `hosting` in the configuration (or `HOSTING_DE`/`HOSTING_EN` when deploying). It
   replaces `[[HOSTER]]`. Unset, the page shows the placeholders `[HOSTER MIT ANSCHRIFT]` and `[LAND]`
   (English: `[WEB HOST WITH ADDRESS]`, `[COUNTRY]`), and admins see a hint.
4. **Remaining placeholders** in square brackets, in every language you keep a text for: contact details of
   the data protection officer, the provisions of your state (school act, data protection act), the
   retention of the web server logs (ask your web host) and the competent supervisory authority. The
   school's data protection officer can tell you the provisions.
5. **Data processing agreement** (DPA, *AVV*) with your web host.
6. **Admin accounts** only for school staff or for persons bound to confidentiality in writing: the privacy
   policy tells parents that, apart from the teacher, only such persons can see their bookings.

### No activation while placeholders are open

TalkSlot checks the stored imprint and privacy policy of every offered language as parents see them, i.e.
with the controller details and the web host filled in. An open placeholder is text in square brackets
without lower-case letters, at least four characters long, such as `[LAND]`. A Markdown link like
`[PDF](https://…)` or mixed-case text like `[Hinweis]` does not count. As long as one is left:

- **No round can be activated:** neither a new active round, nor *Aktivieren* (*Activate*), nor assigning an
  active round to another teacher. The message names the affected texts.
- **Admins** see on the dashboard and under *Texte* which placeholders are open in which text and language.
  **Teachers** see that the admins still have to fill in the texts.
- **Rounds that are already active stay online**, and parents can still use their change links. An
  existing installation therefore does not go offline after an update: the dashboard announces the open
  placeholders, and only the next activation waits for them.

On an update, texts that were never edited are replaced by the new templates (see
[Operations]({{ '/guide/operations/' | relative_url }})), new placeholders included. Edited texts stay as
they are. If they still contain the old sentences about a "private service" without a data protection
officer or about cancelling "at any time", a hint asks you to compare them with the new template.

Have the texts reviewed by someone who knows your situation, ideally the school's data protection officer;
the templates do not replace legal advice.
