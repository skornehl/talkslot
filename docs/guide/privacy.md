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
| Language of a request for an interpreter (optional, only in rounds that ask for it) | database | with the booking |
| Hash of the personal change link (and of the links in the reminder and in a confirmation sent later) | database | with the booking |
| Mail queue entry: which mail is due for which booking or account (no address, text or link) | database | once sent, after 48 hours at the latest, with the booking |
| Cancellation by the teacher in the mail queue: address, language, name, appointment and teacher (name and address for replies; no address once the account is deleted) of the deleted booking (no link) | database | once sent, after 48 hours at the latest |
| Shortened, HMAC-hashed IP addresses; HMAC hashes of account IDs and known-device cookies with failed logins; HMAC hashes of e-mail addresses with the number of mails parents triggered to them | database (rate limiting) | after 24 hours at the latest |
| Known-device cookie of an admin or teacher account (random number, time, signature) | that person's browser | after 365 days, renewed at every sign-in; invalid after a new password or when all sessions are ended; at most 20 per browser |
| Teacher accounts | database | by an admin; inactive accounts without rounds in use 15 months after the last login, together with their drafts |
| Summary mails to teachers (by default the number of appointments and the link, no names; time and name only where the school allows them; never e-mail addresses) | teacher's mailbox | with names: by the end of the school year at the latest (privacy template; operated privately the template recommends it, the school's rules apply) |
| Log of admin changes (who, when, what; no names or e-mail addresses of parents, bookings with their date only) | database | after 90 days (`audit_log_days`) |
| Security events of an account (password, e-mail address, admin rights, requested reset links, sign-in limit; time, whether an admin made the change; no IP address or device), visible only to the account holder | database | after 30 days (`security_events_days`), and with the account |

TalkSlot keeps **no** access logs of its own. The web server's logs are the web host's responsibility;
ask how long they are kept and state it in the privacy policy.

## Design decisions

- **Data minimisation:** a free-text name and an optional e-mail address, nothing else (only in rounds that ask for it, also the language of a request for an interpreter, see below). The field asks for the
  **child's name** (*Name des Kindes*), first and last name, with the class if needed, so that teachers can
  match the booking and parents do not enter their own name for two children; the info box of a round with a
  booking limit says one appointment per child and how to book siblings. The round form asks teachers for
  **neutral titles** (e.g. "Elternsprechtag 7b"): title and description appear in the mails to parents and in
  calendar files, the title also in the log and, when the round is created, in its address (and so in the web
  server's logs), and with a round such as "consultation on special needs" every booking could come close to a
  hint at health data (Art. 9 GDPR). The column for notes in the print view is labelled
  *Organisatorisches* (organisational notes only, nothing about the conversation), since the list is meant
  to be destroyed after the appointment.
- **E-mail address only for the appointment:** confirmation, changes, cancellation and, if the teacher
  turned it on for the round, a reminder the evening before. The privacy template names all of these.
- **Mail queue with references only:** reminders, summaries and mails the mail server refused wait in a
  queue that the cron job sends. An entry holds no address, text or link: they are created from the booking
  when sending, a confirmation sent later with a new link of its own (only its hash is stored, like the
  reminder's). Entries are deleted once sent, after 48 hours at the latest and with their booking; the
  privacy template says so. The only exception is a cancellation by the teacher: the booking is already
  deleted then, so the entry keeps what this one mail needs (address, language, name, appointment,
  teacher; no link, token or description) until it is sent, at most 48 hours. The template says so too.
- **Summaries without names:** the mails to teachers stay in their mailboxes, maybe private ones with a
  provider outside the EU, and in calendars synchronised from them; TalkSlot cannot delete them there, and
  requests for access or erasure would mean searching mailboxes. By default they therefore give, per day,
  only the number of appointments, anything worth a look (so that someone booking many slots still shows)
  and the link to the list in the admin area. Names, and a calendar file whose entries carry the round
  instead of names, only where the school allows them (`summary_names`) and the account ticks them; new
  accounts start without. `allowed_account_domains` keeps the addresses of accounts, and with them the
  summaries and the replies of parents, in the school's own mailboxes. Teachers can give their own reply
  address for parents instead of their account's address.
- **Reminders only where the parents know about them:** parents who book get the reminder if the round
  had it turned on when they booked (the form says so). Entries made by the teacher get it only once a
  confirmation or change notice reached the parents, as that mail tells them about the entry and the
  privacy notice. Turning the reminder on later does not affect earlier bookings.
- **A request for an interpreter, not the language of the page:** which language parents use on the page says
  little about whether they need an interpreter and could hint at their origin, so it is not shown in the list of
  appointments or on the printout and never used to suggest an interpreter (a teacher sees it only as the language
  for mails when editing a booking, and in a request for access). A round can ask instead (off by default):
  parents who would like an interpreter enter the language themselves in an optional field. It serves only to prepare the meeting: it is shown to the teacher and admins in the list of
  appointments, on the printout and in the search for a request for access, and to the parents on the page of
  their appointment. It appears in no mail or summary and is deleted with the booking. The privacy
  templates of both operator types, in all six languages, describe it.
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
  Deleting a booking, a day, a round or an account sends a cancellation, a new date, time or location for a
  day and a new location or teacher for the round a change notice, each in the parents' language and with
  *Eltern per E-Mail informieren* ticked by default. That is what the address was given for (the privacy
  policy names changes and cancellations). Mails only go to parents who booked themselves, or whose entry
  by the teacher already got a confirmation or change notice; parents who never got a mail from TalkSlot
  get no cancellation either (the rule of the reminder). A new address the teacher enters for a booking
  counts as unknown until such a mail from the entry reached it, and change notices after a change of a day
  or round are limited per account and hour (`rate_limits.teacher_bulk`, like the 30 mails per hour from
  entries). This also keeps an account from mailing arbitrary addresses by adding entries with them, or
  changing the addresses of bookings, and then changing or deleting the day. The admin log only gives the number of
  parents notified. A `mailto:` link to the parents was deliberately left out, so that their addresses do
  not end up in private mailboxes through the browser's mail program. Whoever gets no mail is named once
  in the message afterwards, with the appointment and the address if there is one (the round page showed
  both before), so that the teacher can tell them in person, ideally from the school's mail account.
- **Admins and the parents' data:** an admin sees how another teacher's round is booked, but names and e-mail
  addresses only after choosing *Vertretung übernehmen* (take over as substitute) for it, with the password. That lasts
  for the sign-in, the teacher gets an e-mail (if the account has an address; otherwise the admin is told) and the log records it. This is the technical measure against unnecessary disclosure
  (Art. 5(1)(c), (f), Art. 25 GDPR), for example where a parent is the volunteer admin; it does not replace
  instructing admins and committing them to confidentiality.
  The one exception is the search for an access request (*Auskunft zu einer E-Mail-Adresse*): it shows the bookings of
  one given address across all rounds, only with the box that it answers such a request, and it is logged.
- **Admin log without monitoring teachers:** only changes made by admin accounts are logged, so it shows
  who among several admins did what. What teachers do in their own rounds is not recorded, and bookings
  appear with their date only: with the time, an entry together with a summary mail or a printed list would
  tell whose booking it was, 90 days after the booking itself was deleted. Round, teacher and date can still
  point to a family (one booking on a day), so the entries remain pseudonymous data, visible to admins only.
  Entries from before this change lose their time on the next clean-up run. Depending on your state, a log of staff actions may still be a matter for
  the staff council (*Personalrat*); you can shorten the retention with `audit_log_days`.
- **Security events only for the account holder:** after an incident a teacher can see what happened to the
  account (*Mein Profil → Sicherheit*), without a means to monitor the staff: no sign-ins, no IP address, nothing
  visible to admins in the application (only whoever can read the database or its backups), failed sign-ins only
  as a number. Involve the staff council before introducing it and agree on the retention (30 days by default,
  `security_events_days`, 90 at most).
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
- **Informing, not ticking:** under the GDPR the privacy notice informs (Art. 13); nobody has to accept or
  confirm it, and a required checkbox would only have been mistaken for consent and stopped bookings with an
  error. The booking form therefore has none. Right above the button it gives the information in two levels
  (Art. 12): who is responsible (the first line of the operator's details), the purpose (no sharing with third
  parties for other purposes) and the deletion, plus a link to the full privacy policy that names the rights
  (access, erasure, objection, complaint; Art. 21(4) asks to point out the right to object at the first
  contact); a folded *Mehr zum Datenschutz* (*More about privacy*) adds the data stored, the summary mails to
  the teacher (names only if this has been allowed for the school), the legal basis of the operator type
  (Art. 6(1)(e) or (b) GDPR; none while no type is chosen) and the rights including the complaint. The
  privacy policy says that booking needs no consent; only "remember on this device" does.
- **Deadlines and the chosen appointment before booking:** the round page names the booking deadline and the
  deadline for changes of the round (in hours, a deadline of 0 is not mentioned), and the line above the
  button shows the chosen slot with date, time and location (filled in by JavaScript, announced by screen
  readers; after an error also without JavaScript). The appointment page and the confirmation, change and
  reminder mails give the date and time until which the booking can still be changed or cancelled online, or
  say that this is no longer possible. The footer of every mail to parents links the privacy policy in their
  language.

## Requests for access {#requests-for-access}

Parents can ask what is stored about them (Art. 15 GDPR). An admin finds the data for such a request under
*Protokoll → Auskunft zu einer E-Mail-Adresse* (request for access by email address): enter the exact address and
confirm that you are answering a request for access. The page lists, across all teachers, the bookings with that address (appointment, round,
teacher, the name as booked, a request for an interpreter, language, when and by whom it was entered, reminder and delivery status) and
cancellation mails that are still waiting to be sent. Upper and lower case do not matter; parts of an address
are not searched for, and the search is a form post, so the address appears in no URL.

Every search is entered in the [log]({{ '/guide/admin/' | relative_url }}#log) with who searched, when and how
many entries were found, not the address. Only admins can use it.

The page does not cover mails that were already sent (the teachers' mailboxes, summaries, the mail server),
backups and the web host's logs, and it cannot find a booking without an e-mail address; there, the teacher can
look in the round's booking list. As bookings are deleted at midnight after the appointment, the answer will
often be that nothing is stored in TalkSlot any more.

To answer the request: make sure it really comes from the person the address belongs to (reply only to that
address, or check their identity first, Art. 12(6) GDPR), and answer within one month (Art. 12(3)), also if
nothing was found. Copy the result into your reply and refer to the privacy policy for purposes, recipients and
retention periods. Ask the teachers and the web host about what the page does not cover. If the parents want a
booking deleted, delete it in the round's booking list.

## Legal templates

Imprint templates in German and English and privacy policy templates in all six parent languages (German,
English, Polish, Russian, Ukrainian, Turkish) are copied into the database at installation and can be
edited by admins under *Texte* (*Texts*). The privacy template covers the information required by Art. 13
GDPR: controller, data protection officer, purposes, legal bases, recipients, third countries, retention,
rights of data subjects including the right to object, right to lodge a complaint, whether providing data is
required, and automated decision-making.

### The privacy policy in the parents' languages

Art. 12(1) GDPR asks for the information in a clear and plain form, and the EDPB's transparency guidelines
(WP260 rev.01, para. 13) add that information addressed to speakers of other languages should be translated
for them. TalkSlot addresses parents in six languages. The translations follow the German templates of both operator types
section by section and start with a note that the German version is authoritative (so does the English
one). They have the **same placeholders as the German template, in German**, so admins fill them in with
the details they put into the German text; short details such as names, addresses and legal provisions can
be copied as they are, a sentence such as the retention of the web server logs is best written in the
language of the text. `[[HOSTER]]` takes `hosting.<language>` if the configuration has it, otherwise the
English text (then the German one) of the web host; German and English need their own.

- **Which text parents get:** the one in their language if it is complete, i.e. not empty and without open
  placeholders; otherwise the English one, then the German one (the authoritative version), then the one of the
  default language (these as they are, open placeholders included, as before: they keep rounds from being
  activated). English and German only if they are offered (`languages`): a text in a language that is not
  offered is never shown, since admins can neither see nor edit it. If none of these languages has a text
  for a page, no round can be activated. The page then says in the parents' language that the text is not
  available in it, and marks the text and the name of its language with `lang` (screen readers). The
  imprint has no translations and always takes this way.
- **Open placeholders in a translation do not keep rounds from being activated:** until they are filled in,
  parents in that language get the English (or German) text with a notice, which was the state before the
  translations existed. That is a gap, not a solution: **fill in the translations before you invite parents
  in these languages**. Admins see *Übersetzungen noch nicht vollständig* (*Translations not complete yet*)
  with the texts concerned on the dashboard and under *Texte*. Emptying a translation brings back the state
  before (the English text, no note); to stop addressing parents in a language at all, remove it from
  `languages`.
- **The German version is authoritative**, so a translation has to say the same. When the German privacy
  policy is saved after a complete translation (or English text), admins see that this version was saved
  before the German one and should be checked; saving it again (also unchanged) confirms it. If the German
  text says more than the template, complete translations that are still the template are listed until your
  changes are carried over. An empty German privacy policy blocks activating rounds as long as German is
  offered. The note
  "German version authoritative" does not make a wrong translation right: the operator remains responsible
  for each language.
- **Existing installations** get the translations of their operator type on the first request after the
  update, as long as there is no text in that language yet (or an unedited template); edited texts stay. They
  show the English text until the admins have filled in the placeholders, as before.
- The translations were made with care but **should be reviewed by native speakers**, ideally with legal
  knowledge, before you rely on them. Every change to the German templates has to be made in all six languages
  and both operator types (see [Development]({{ '/guide/development/' | relative_url }})).

### Operated by the school or privately

TalkSlot can be used at a school without being run by the school. Who runs it decides who the controller is,
which legal bases apply and what the imprint says. The installer therefore asks for the **operator type**
(*Betriebsart*); admins can change it later under *Texte → Angaben für Impressum und Datenschutz*.

| Operator type | Controller | Privacy policy template | Imprint template |
|---|---|---|---|
| *Wird von der Schule betrieben* (*Operated by the school*): a state school as a public body | the school | Art. 6(1)(e) and (3) GDPR in conjunction with the school act of your state (bookings, mails, summaries) and its data protection act (abuse protection, server logs); the school's data protection officer; right to object under Art. 21, no right to data portability (Art. 20(3)) | the school as provider |
| *Wird privat betrieben* (*Operated privately*): a private person, an association or an independent provider (e.g. an independent school) | the operator, for the booking service; the meeting itself, the summary mails in the teacher's mailbox and printed lists are the teacher's work at their school (as a rule the school is responsible, its rules apply) | Art. 6(1)(b) and (f) GDPR; right to object for (f), right to data portability; no data protection officer as the rule for private persons, with a placeholder to check that | a private, non-commercial service (as up to v1.5) |

A public authority cannot rely on legitimate interests (Art. 6(1), second subparagraph, GDPR), and meetings
at a state school are part of its statutory tasks, not of a contract. That is why the school template cites
the law of your state. Which provisions apply differs from state to state, so the template leaves them as
placeholders.

**Templates follow the operator type.** The installer stores the templates of the chosen type. When an admin
changes the type, every text that is still an unedited template (of either type, current or earlier) is
replaced by the template of the new type, and the message says how many; edited texts are never replaced.
The admin log (*Protokoll*) records who set the operator type and when (accountability, Art. 5(2) GDPR):
each change by an admin with the number of texts replaced, a type taken over on an update (entered as
*TalkSlot*) and the admin who then confirmed it, and texts replaced by newer templates on an update.
On the text form, *Vorlage laden* (*Load template*) offers the templates of both types, the one of the
operator type first; it only replaces the stored text when you save. Hints compare the texts with the type:

- **Operated by the school:** a hint if a text still follows the old template ("private service" without a
  data protection officer, cancelling "at any time") or relies on Art. 6(1)(b) or (f) GDPR.
- **Operated privately:** a hint if a text contains the school template (Art. 6(1)(e) GDPR, school act, the
  school's data protection officer) or still says that parents can cancel "at any time".
- **Either type:** a hint if a privacy policy still mentions the required checkbox in the booking form, which
  no longer exists.

The hints also recognise the translations, which cite the GDPR as "6(1)(e)" or, in Polish, "art. 6 ust. 1
lit. e RODO". For installations from before the operator type, only the German and English texts count, as
there were no translations then.

**Installations from before the operator type** get one on the first request after the update:

- If all their texts are still unedited templates up to v1.6, which described a private service, the type
  becomes *operated privately* (the meaning so far) and the privacy policy the current private template. The
  dashboard says so until an admin has saved the details once.
- Otherwise (edited texts, or the unedited school templates that v1.7.0 put in) no type is set: admins see
  *Bitte Betriebsart wählen* (*Please choose the operator type*) on the dashboard and under *Texte*. **Until
  one of them chooses, no round can be activated**; rounds that are already active stay online, and teachers
  see that the admins still have to choose. Choosing *operated privately* turns the school templates of
  v1.7.0 back into the private ones, as long as nobody has edited them.

Before going live:

1. **Who is responsible**, depending on the operator type:
   - **Operated by the school:** the school is the controller, and the school management decides to use
     TalkSlot. The template has a required placeholder for the school's data protection officer. If someone
     else runs the server for the school (e.g. a volunteer with their own hosting contract), the school needs an
     agreement under Art. 28 GDPR with that person (instructions, confidentiality, the web host as
     sub-processor, handover of all access, deletion when operation ends), or the school (or its school
     authority) concludes the hosting contract itself.
   - **Operated privately:** the operator (you, your association or your organisation) is the controller for
     the booking service, including the mails it sends; teachers use the service for their parent meetings.
     The meeting itself, notes, the summary mails in the teacher's mailbox and printed lists are the teacher's
     work at their school: as a rule the school is responsible for them and its rules apply (the summaries
     contain no names unless names are allowed; the template recommends deleting those with names by the end
     of the school year). Teachers should give a work e-mail address for their account; otherwise the
     summaries end up in a private mailbox, possibly with a provider outside the EU
     (`allowed_account_domains` can require the school's domain). Schools may have their own rules on which services their teachers may use, so teachers should
     check with their school management or the school's data protection officer. If the school has a say in
     the booking service itself – e.g. it introduces it as its tool for parent meetings, requires its teachers
     to use it, or sets its settings, retention or admin accounts – the private template no longer fits: you
     then need the operator type *operated by the school* with an agreement under Art. 28 GDPR, or an
     arrangement under Art. 26 GDPR (the texts then need adjusting, Art. 26(2) GDPR). Rules the school sets
     only for its teachers, such as approving the service, the work e-mail address or how long to keep mails
     in the mailbox, do not change this. A teacher who uses the service without the school's approval may
     become a controller themselves. If a data protection officer has been appointed for the operator, name
     them in the privacy policy instead of the sentence that none is appointed. The imprint template is
     written for a private person: an association or independent provider adds the person authorised to
     represent it and, if it has one, its register entry (section 5 DDG).
2. **Controller:** fill in *Texte → Angaben für Impressum und Datenschutz*. These details replace
   `[[VERANTWORTLICHE]]`; without them no round is public. Their first line also names the controller in the
   privacy information of the booking form.
3. **Web host:** set `hosting` in the configuration (or `HOSTING_DE`/`HOSTING_EN` when deploying). It
   replaces `[[HOSTER]]`. Unset, the page shows the placeholders `[HOSTER MIT ANSCHRIFT]` and `[LAND]`
   (English: `[WEB HOST WITH ADDRESS]`, `[COUNTRY]`), and admins see a hint. The translations use the English
   text unless you add one per language (e.g. `hosting.pl`).
4. **Remaining placeholders** in square brackets, in every language you keep a text for: the data
   protection officer (contact details; for a private operator the check whether one is appointed), for a
   school the provisions of your state (school act, data protection act), the retention of the web server
   logs (ask your web host) and the competent supervisory authority. At a school, its data protection
   officer can tell you the provisions.
5. **Data processing agreement** (DPA, *AVV*) with your web host (and, if separate, your mail provider), and
   your **documentation**: record of processing, TOMs, DPIA check, procedures for data breaches and requests,
   information sheet for teachers. [Documents for the operator]({{ '/guide/operator-documents/' | relative_url }})
   has templates for all of them, for both operator types.
6. **Admin accounts** only for staff of the operator (at a school: school staff) or for persons bound to
   confidentiality in writing: the privacy policy tells parents that, apart from the teacher, only such
   persons can see their bookings.
7. **Students of age:** whether and on what teachers may talk with the parents of students aged 18 or over
   is a matter of your state's school law (often only with the student's consent or in cases the law names).
   If rounds are meant for such classes, clarify this with the school management or the data protection
   officer first; TalkSlot does not check it.

### No activation while placeholders are open

TalkSlot checks the stored imprint and privacy policy of every offered language as parents see them, i.e.
with the controller details and the web host filled in, whatever the operator type. An open placeholder is
text in square brackets without lower-case letters, at least four characters long, such as `[LAND]`. A
Markdown link like `[PDF](https://…)` or mixed-case text like `[Hinweis]` does not count. As long as one is
left in English or in the default language (the texts parents get when there is none in their language):

- **No round can be activated:** neither a new active round, nor *Aktivieren* (*Activate*), nor assigning an
  active round to another teacher. The message names the affected texts.
- **Admins** see on the dashboard and under *Texte* which placeholders are open in which text and language.
  **Teachers** see that the admins still have to fill in the texts.
- **Rounds that are already active stay online**, and parents can still use their change links. An
  existing installation therefore does not go offline after an update: the dashboard announces the open
  placeholders, and only the next activation waits for them.

Open placeholders in the other languages (the translations of the privacy policy) do not stop anything:
parents in that language get the English text with a notice until they are filled in (see above).

On an update, texts that were never edited are replaced by the new templates of the operator type (see
[Operations]({{ '/guide/operations/' | relative_url }})), new placeholders included.

### Template updates for edited texts

Edited texts are never replaced automatically: what an admin has written stays. So that corrections to the
templates – e.g. on retention periods, recipients, mails or the legal bases – still reach installations
whose texts have been filled in, TalkSlot keeps a **version marker** for every text: the template it was
stored from (installation, change of the operator type, *Vorlage laden*) or last reconciled with. When a newer
template of the operator type arrives with an update, admins see on the dashboard and under *Texte* which
sections it has changed since, and on the text form the old and new wording of each, with *Abschnitte aus der
Vorlage übernehmen* (*Take over the sections from the template*) to take them over section by section,
keeping the details filled in (see [Admins]({{ '/guide/admin/' | relative_url }}#legal-texts)). Sections an
admin has deliberately reworded are not flagged unless the template changes them too.

- The marker is the wording of the template itself, stored in the settings table (`legal_base.<page>.<lang>`,
  no personal data). A text that is exactly the template of its marker counts as unedited and is replaced
  by a newer template, even if that template's hash is missing from `OLD_PAGE_HASHES`.
- Texts edited before TalkSlot kept version markers get one on the first request after the update if they
  have the wording of the current template (only the placeholders filled in). Otherwise the hint lists the
  sections in which they differ from the current template; once an admin has reconciled the text and
  confirmed it, only later template changes are shown.
- With a marker, a section only counts as taken over once the text no longer has any line the template has
  dropped since – also next to a placeholder or in place of a new placeholder – and has every placeholder the
  template has added since, also one without new wording around it (e.g. a note to check in square brackets
  at the end of a line, or a paragraph that is only a placeholder): a placeholder may be filled in by
  nothing, so otherwise the earlier line would look like the new one filled in. Without a marker it cannot be
  told whether such a line fills in a placeholder or still has the wording of an earlier template, so confirm
  the reconciliation once.
- Taking over keeps the details filled in, also where they go on in the lines below a placeholder at the end
  of a line (e.g. name, address and e-mail of the data protection officer). Whatever else the text had in
  the sections taken over (own lines, details in a line the template changed) the form lists as not taken
  over, so that nothing goes missing unnoticed, e.g. the contact details required by Art. 13(1)(b) GDPR.
- Reconciling is logged with the admin's name (accountability, Art. 5(2) GDPR), so you can show when a
  correction of the template was taken over. The log is deleted after `audit_log_days` (90 days by default);
  if you need to show the reconciliation for longer, keep a copy of the entry before, e.g. together with the
  saved version of the text.
- Templates exist in German and English. Privacy policies in the other languages (Polish, Russian, Turkish,
  Ukrainian) are not compared with any template: when the notice reports a change to the German or English
  version, please update the translations yourself.

The hints on the operator type above stay: they explain why a wording does not fit, whatever the marker says.

Have the texts reviewed by someone who knows your situation, at a school ideally its data protection
officer; the templates do not replace legal advice.
