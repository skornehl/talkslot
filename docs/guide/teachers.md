---
title: Teacher guide
description: Create rounds, invite parents and manage bookings.
---

Teachers log in at `/admin` with their e-mail address. They see and change only their own rounds.
The admin area is available in German and English (*Mein Profil* → *Sprache / Language*). Labels below are
the German ones, with the English label in brackets.

## Create a round

*+ Neue Runde* (new round) opens the form. The appointments are created together with the round:

- **Template** (*Vorlage*, if your admins have set up any): *Übernehmen* fills in times, breaks and
  settings, e.g. "parent-teacher day: 08:00–16:00, 15 minutes, lunch break". Everything stays editable;
  only title and date are left to fill in.
- **Title** and optional **description** (*Beschreibungstext*), shown to parents. Plain text.
- **Location** (*Ort*, optional), e.g. "Room 1.12". Shown on the booking page, the confirmation page, in
  the confirmation mail, the calendar file, the print view and the invitation text. If one day takes place
  somewhere else, set it under *Tag bearbeiten* (edit day).
- **Dates** (*Datum*): one or more days with the same times (*+ weiteren Tag*).
- **First slot**, **last slot ends at** and **duration per slot** in minutes.
- **Breaks** (*+ Pause hinzufügen*): any number per day; slots continue after the break.
- A **preview** shows the resulting slots before you save.
- **Multiple bookings** (*Mehrfachbuchungen*): one appointment per person in this round (default), one
  per person and day, or allowed with a hint. The same person means the same name (case and spaces
  ignored) and, if both bookings have one, the same e-mail address. Siblings book with each child's name.
  Your own entries are not limited.
- **Deadlines**: booking closes *N* hours before a slot (*Buchungsschluss*), parents can change or cancel
  until *N* hours before (*Ändern/Absagen bis*).
- **Delete after** (*Runde löschen nach Ablauf*): hours after the last day, 24 by default, empty = never.
  Bookings are deleted the day after the appointment either way. Only a round that was in use expires:
  one that was active at some point or got an entry from you. A round that was never active, e.g. a copy
  that still has the old dates, is kept until you use or delete it (*Noch nie aktiv: wird nicht
  automatisch gelöscht*). The round page shows the date of the deletion (*Runde wird am … gelöscht*); an
  expired round also says how to keep it: add a day from today on, or clear this field.
- **Reminder** (*Eltern am Vorabend an ihren Termin erinnern*, on by default): parents who gave an e-mail
  address get a reminder the evening before, with the link to cancel. Who got a confirmation or change
  notice on that same day gets none (editing a name without a mail does not count). It applies to bookings
  made while it is on; your own entries only get it once their parents received a confirmation or change
  notice by mail.
- **Random part in the address** (recommended) so the round cannot be found by guessing, and optionally
  **round code required** (*Nur mit Rundencode*).
- **Active**: parents can book. Inactive rounds take no bookings, but parents can still cancel. While no
  round can be activated yet (for example because the admins still have to fill in the placeholders of the
  legal texts), the form says why below the checkbox and a new round starts inactive. Save it like that,
  prepare everything, and activate it on the round page (*Aktivieren*) once the notice on the overview is
  gone.

![New round form]({{ '/assets/img/admin-round-form.webp' | relative_url }})

## Invite parents

The round page shows everything you need:

- the **link for parents** (*Link kopieren*, *Öffnen*),
- the **round code**, e.g. `3MMP-WYA9`. Parents can enter it on the start page instead of using the link,
  handy for paper invitations. Typos like `0`/`O`, dashes and case do not matter.
- a ready-made **invitation text** (*Einladungstext kopieren*, *Als E-Mail öffnen*) with the link, a direct
  link for every further parent language (`Türkçe: …?lang=tr`), the location and code if any, and the
  same sentence on multiple bookings that parents see on the round page (for example the sibling rule).

Parents choose their language at the top of every page, by its own name (*Deutsch, English, Polski,
Русский, Українська, Türkçe*). If their browser is set to another offered language, a link to it appears
above the page.

With *Nur mit Rundencode* the link alone shows only a code prompt; the title and slots stay hidden from
strangers. A new code (when editing) makes the old one invalid immediately; existing bookings stay
reachable via their personal links.

## Manage bookings

![Round page with bookings]({{ '/assets/img/admin-round.webp' | relative_url }})

Per day you see all slots with the bookings:

- **Edit** (*Bearbeiten*): change name or e-mail, or **move** the booking to another slot, with a mail to
  the parents (*Änderung per E-Mail mitteilen*, ticked by default). The mail gives the current date, time
  and location in the parents' language, so it also goes out when you save without a change here, e.g.
  after the day got a new date or location; untick the box if you do not want to send it.
- **Delete** (*Löschen*) a booking.
- **Add** (*Eintragen*) an appointment yourself, e.g. after a phone call. The confirmation mail with the
  change link is ticked as soon as you enter an e-mail address; it is the only way the parents learn about
  the entry.
- ***E-Mail nicht zugestellt*** next to an entry: the mail to the parents could not be sent. While it says
  *neuer Versuch folgt*, it is retried automatically; otherwise please inform the parents in person or
  check the address: correct it under *Bearbeiten* and save with the mail ticked. That mail carries no
  change link; if the confirmation of an entry you added yourself never arrived, delete the entry and add
  it again with the right address instead, so that the parents get the link. A mail the mail server first
  accepted can still bounce later (e.g. a typo in the address); such an entry has no badge, and whoever reads
  the sender mailbox of the installation should tell you.
- **Addresses like `eltern@xn--mller-kva.de`** are addresses with umlauts in the domain (`eltern@müller.de`),
  written the way mail servers need them. They work as they are, also when you copy them.
- **Block** (*Sperren*) a slot so nobody can book it, and release it again.
- **Days**: edit times and the location of a single day (*Tag bearbeiten*), add breaks, print a day (*Tag drucken*), add more days
  (*+ Tag hinzufügen*) or delete a day.

If you change times or breaks, **booked appointments are never deleted**; slots that no longer fit are
marked with ⚠ so you can move them.

**Parents hear about your changes by e-mail.** When you delete a booking, a day or a round, give a day
another date, time or location, or the round another location or (as an admin) another teacher, the box
***Eltern per E-Mail informieren*** is ticked in the dialog or form. Parents then get a mail in their language:

- a **cancellation** when their booking is deleted, with a calendar file that removes the appointment from
  their calendar, and the link to the round page to book again if the round still takes bookings. It has
  no personal link, as the booking is gone. A single entry is sent at once; several (a day, a round) go
  out from the [mail queue]({{ '/guide/operations/' | relative_url }}#mail-queue), with the next run of the
  cron job or, on PHP-FPM, right after the page; cancellations of appointments within the next 24 hours go
  first there;
- a **change notice** with the current date, time, location and teacher when these changed (from the
  mail queue). Their personal link stays valid, and the calendar file updates the appointment in their
  calendar. If you change the same day again before the notice has gone out, the parents get one mail with
  the latest details.

Mails go to parents who booked with an e-mail address themselves, and to entries you made yourself once
their parents got a confirmation or change notice from TalkSlot. Everybody else, and everybody if you untick
the box, is **not** notified: the dialogs and forms give the number, and the message afterwards names them
with date, time and address (once; after a deletion nothing else shows them), so that you can tell them in
person. Appointments that are over concern nobody.

If you give a booking (*Bearbeiten*) a **new e-mail address**, that address only gets change notices,
cancellations and reminders once you have saved the entry with *Änderung per E-Mail mitteilen* and the mail
went out; until then the message after saving says so, and the booking counts as your own entry
(*selbst eingetragen*). Change notices after a change of a day or round are limited to 200 per hour from your
account (set in the configuration, `rate_limits.teacher_bulk`); the message names the parents above it,
so that you can tell them in person or send them the details later from their entry.

Bookings that no longer fit after a change of times or breaks become **conflicts** (⚠). Their parents are
not mailed yet: they hear of it when you move the booking (*Bearbeiten*, *Änderung per E-Mail mitteilen*
ticked) or delete it (*Eltern per E-Mail informieren* ticked). If a conflict gets a new date, location or
teacher at the same time, the warning after saving says that its parents do not know the new details yet.
When a later change (of the day or a break) makes a conflict fit again, its parents get a change notice with
the current details, if these changed since their last mail. Saving an entry with *Änderung per E-Mail
mitteilen* also sends the current details without a change (at most 30 mails per hour from your account).

Further actions on the round: edit, deactivate, **print view / PDF** (*Druckansicht / PDF*), and
**duplicate** (*Duplizieren …*): a copy with new link and code, including times, breaks, blocked slots,
settings and translations, but no bookings; the copy is inactive. Enter the **first day of the copy** and
all days move by the same distance (days on Tuesday and Thursday stay on Tuesday and Thursday), handy for
the next term. Without a date the copy keeps the old dates; as it was never active, it is not deleted
automatically, so you can prepare it at your own pace.

**Delete a round** in its own section at the end of the round page (*Runde löschen*; *Löschen …* among the
actions jumps there). A round **without bookings** goes to the **trash** (*Papierkorb*, at the end of the
overview while something is in it) for 14 days: there you can restore it with its address and round code, or
delete it for good. A round **with bookings** is deleted at once with all its days, breaks, translations and
bookings, which cannot be undone: tick *inkl. N Buchung(en)* (incl. N bookings) first; without the tick nothing
is deleted, even if your browser does not show the confirmation dialog. If you only want
to stop new bookings, choose *Stattdessen deaktivieren* (deactivate instead): days and bookings are kept,
and parents can still cancel.

## Translations

Parents see the title and description in their language when a translation exists. Under *Übersetzungen
von Titel und Beschreibung* you can:

- **translate in the browser** (*Im Browser übersetzen*): Chrome 138+ or Edge 148+ on a computer translate
  with a local model on your device. The text is not sent to any service; only the language model is
  downloaded once per language (one click per language the first time);
- enter or correct translations by hand, confirm an outdated one as still correct, or leave a language
  untranslated on purpose (empty title).

Missing or outdated translations show the original. The admin area and the print view are marked
`translate="no"`, so browsers do not offer their own "Translate page" feature there: it would send the
whole page, including bookings, to Google or Microsoft. Browsers or add-ons that ignore this mark could
still do so, so please do not translate admin pages by other means either.

## Summary mails

In *Mein Profil* you choose whether you receive an overview of the next day in the evening and an
overview after the booking deadline of each day. For each round and day they give the number of
appointments with the first and last time, anything worth a look and the link to the list in the admin area:

```
Elternsprechtag 7b – Monday, 17 August 2026
  6 appointments between 14:00 and 15:30
  Worth a look: 2× the same name, 1× without an email address
  Overview and print view: https://termine.example.org/admin/round/5
```

*Worth a look* (*Auffällig*) counts the same name or the same e-mail address more than once (siblings, or
someone booking many slots), parents who booked without an e-mail address (they cannot be told about
changes), mails that could not be delivered and appointments that no longer fit the schedule.

The mails contain **no names** and never the parents' e-mail addresses: they stay in mailboxes and calendars
that your school's deletion rules may not reach. The names are in the list behind the link (after signing
in). Only if your school allows it (an admin sets `summary_names`) can you tick *Mit Namen* (*With names*
in the English admin area) in the profile: the mails then list time and name of each appointment and come
with a calendar file of the appointments, which itself has no names (each appointment is titled with its
round). Delete such mails as
soon as you no longer need them, at the latest at the end of the school year, and use your work e-mail
address for your account. For accounts from before this change, a notice on the overview says what
changed. Where the school allows names, your summaries keep them, and you can turn them off in the profile.
Where it does not, they are without names from the update on; once you confirm the notice (*Verstanden*,
*Got it*),
your account is treated like a new one, so if the school allows names later, tick *Mit Namen* yourself.

### Replies of parents

Parents' replies to the mails about their appointment go to your e-mail address (reply-to), so parents see
it once they reply. Under *Mein Profil → Antwortadresse für Eltern* (*Reply address for parents*) you can
give another address of your own for that, e.g. your work address; changing it asks for your password, like
the e-mail address. Use an address that you read yourself: the mails tell parents that their replies go to
the teacher. If the school allows only its own addresses (`allowed_account_domains`), both have to be at its
domain. A reply address from before that rule is then not used (the replies go to your e-mail address, if
that is at the school's domain), and the overview asks you to change it.

### Contact for parents

Under *Mein Profil → Kontakt für Eltern bei Fragen* (*Contact for parents with questions*) you can say how
parents reach you, for example your work e-mail address or the school office with its phone number and
hours. It is shown on the booking page of your rounds and on the page of booked appointments, where parents
need it most when all slots are taken or the deadline for changes has passed. Left empty, parents see the
contact the admins set for all, if any. Parents see it as you write it (not translated); please no private
phone numbers.

### Links from the mails

The link in a summary opens the page directly. If you are signed in, it opens signed in (the browser keeps
back the session cookie for links from other sites, for example a webmailer, so a short interim page
*Weiterleitung* (*Redirecting*) opens it once more from TalkSlot). If you are not signed in, or the browser
still holds the cookie back, you sign in and land on that page, not on the overview.
