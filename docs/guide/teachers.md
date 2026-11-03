---
title: Teacher guide
description: Create rounds, invite parents and manage bookings.
---

Teachers log in at `/admin` with their e-mail address. They see and change only their own rounds.
Labels are those of the German admin area, with a translation in brackets.

## Create a round

*+ Neue Runde* (new round) opens the form. The appointments are created together with the round:

- **Title** and optional **description** (*Beschreibungstext*), shown to parents. Plain text.
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

- **Edit** (*Bearbeiten*): change name or e-mail, or **move** the booking to another slot, optionally with
  a mail to the parent.
- **Delete** (*Löschen*) a booking.
- **Add** (*Eintragen*) an appointment yourself, e.g. after a phone call, optionally with a confirmation mail.
- **Block** (*Sperren*) a slot so nobody can book it, and release it again.
- **Days**: edit times (*Tag bearbeiten*), add breaks, print a day (*Tag drucken*), add more days
  (*+ Tag hinzufügen*) or delete a day.

If you change times or breaks, **booked appointments are never deleted**; slots that no longer fit are
marked with ⚠ so you can move them.

Further actions on the round: edit, deactivate, **print view / PDF** (*Druckansicht / PDF*), duplicate
(a copy with new link and code, including translations), delete.

## Translations

Parents see the title and description in their language when a translation exists. Under *Übersetzungen
von Titel und Beschreibung* you can:

- **translate in the browser** (*Im Browser übersetzen*): Chrome 138+ or Edge 148+ on a computer translate
  with a local model on your device. The text is not sent to any service; only the language model is
  downloaded once per language (one click per language the first time);
- enter or correct translations by hand, confirm an outdated one as still correct, or leave a language
  untranslated on purpose (empty title).

Missing or outdated translations show the original. Please do not use the browser's own "Translate page"
feature in the admin area: it sends the whole page, including bookings, to Google or Microsoft.

## Summary mails

In *Mein Profil* you choose whether you receive an overview of the next day in the evening and an
overview after the booking deadline of each day. They list time and name, without the parents' e-mail
addresses, and include a calendar file. Parents' replies to their confirmation mails reach your e-mail
address (reply-to).
