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
- **Active**: parents can book. Inactive rounds take no bookings, but parents can still cancel.

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
  it again with the right address instead, so that the parents get the link.
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
  cron job or, on PHP-FPM, right after the page;
- a **change notice** with the current date, time, location and teacher when these changed (from the
  mail queue). Their personal link stays valid.

Mails go to parents who booked with an e-mail address themselves, and to entries you made yourself once
their parents got a confirmation or change notice from TalkSlot. Everybody else, and everybody if you untick
the box, is **not** notified: the dialogs and forms give the number, and the message afterwards names them
with date, time and address (once; after a deletion nothing else shows them), so that you can tell them in
person. Appointments that are over concern nobody.

Bookings that no longer fit after a change of times or breaks become **conflicts** (⚠). Their parents are
not mailed yet: they hear of it when you move the booking (*Bearbeiten*, *Änderung per E-Mail mitteilen*
ticked) or delete it (*Eltern per E-Mail informieren* ticked). Saving an entry with *Änderung per E-Mail
mitteilen* also sends the current details without a change (at most 30 mails per hour from your account).

Further actions on the round: edit, deactivate, **print view / PDF** (*Druckansicht / PDF*), and
**duplicate** (*Duplizieren …*): a copy with new link and code, including times, breaks, blocked slots,
settings and translations, but no bookings; the copy is inactive. Enter the **first day of the copy** and
all days move by the same distance (days on Tuesday and Thursday stay on Tuesday and Thursday), handy for
the next term. Without a date the copy keeps the old dates; as it was never active, it is not deleted
automatically, so you can prepare it at your own pace.

**Delete a round** in its own section at the end of the round page (*Runde löschen*; *Löschen …* among the
actions jumps there). It deletes the round with all its days, breaks, translations and bookings and
cannot be undone. If the round has bookings, tick *inkl. N Buchung(en)* (incl. N bookings) first: without
the tick nothing is deleted, even if your browser does not show the confirmation dialog. If you only want
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
overview after the booking deadline of each day. They list time and name, without the parents' e-mail
addresses, and include a calendar file. Parents' replies to their confirmation mails reach your e-mail
address (reply-to).
