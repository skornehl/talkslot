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
  Bookings are deleted the day after the appointment either way.
- **Reminder** (*Eltern am Vorabend an ihren Termin erinnern*, on by default): parents who gave an e-mail
  address get a reminder the evening before, with the link to cancel. Who booked on that same day gets
  none, as the confirmation has just been sent.
- **Random part in the address** (recommended) so the round cannot be found by guessing, and optionally
  **round code required** (*Nur mit Rundencode*).
- **Active**: parents can book. Inactive rounds take no bookings, but parents can still cancel.

![New round form]({{ '/assets/img/admin-round-form.webp' | relative_url }})

## Invite parents

The round page shows everything you need:

- the **link for parents** (*Link kopieren*, *Öffnen*),
- the **round code**, e.g. `3MMP-WYA9`. Parents can enter it on the start page instead of using the link,
  handy for paper invitations. Typos like `0`/`O`, dashes and case do not matter.
- a ready-made **invitation text** with link and code (*Einladungstext kopieren*, *Als E-Mail öffnen*).

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
- **Block** (*Sperren*) a slot so nobody can book it, and release it again.
- **Days**: edit times and the location of a single day (*Tag bearbeiten*), add breaks, print a day (*Tag drucken*), add more days
  (*+ Tag hinzufügen*) or delete a day.

If you change times or breaks, **booked appointments are never deleted**; slots that no longer fit are
marked with ⚠ so you can move them.

**Parents are not notified automatically** when you delete a booking, a day or a round, when a day gets
another date or location, the round another location or (by an admin) another teacher, or when a booking
becomes a conflict; the same goes for an admin deleting an account. The confirmation dialogs, the hints in
the forms and the message afterwards say so, with the number of bookings concerned. Please inform the
parents yourself, in person if no e-mail address is stored. If the booking still exists, the simplest way
is the entry itself: open *Bearbeiten* and save with *Änderung per E-Mail mitteilen* ticked, and parents
with an e-mail address receive the new details in their language (at most 30 mails per hour from your
account; beyond that the message asks you to save again later). Before deleting a day or a round, open the
print view: afterwards nobody can see who had booked. To move a conflicting booking, edit it and leave
*Änderung per E-Mail mitteilen* ticked.

Further actions on the round: edit, deactivate, **print view / PDF** (*Druckansicht / PDF*), delete, and
**duplicate** (*Duplizieren …*): a copy with new link and code, including times, breaks, blocked slots,
settings and translations, but no bookings; the copy is inactive. Enter the **first day of the copy** and
all days move by the same distance (days on Tuesday and Thursday stay on Tuesday and Thursday), handy for
the next term. Without a date the copy keeps the old dates.

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
