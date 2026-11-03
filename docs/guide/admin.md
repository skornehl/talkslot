---
title: Admin guide
description: The admin account sets up the platform, invites teachers and is responsible for the legal texts.
---

There is exactly **one admin account**. It is created during installation. The admin is the controller
under the GDPR for all rounds; teachers use the platform on the admin's behalf. Labels below are those of
the German admin area, with a translation in brackets.

![Admin overview of all rounds]({{ '/assets/img/admin-dashboard.webp' | relative_url }})

## First steps

1. **Profile** (*Mein Profil*): enter the display name (shown to parents for your own rounds), your e-mail
   address and above all the **details for imprint and privacy policy** (*Angaben für Impressum und
   Datenschutz*): name of the school or person, full address and e-mail address. This text replaces
   `[[VERANTWORTLICHE]]` in both legal pages. Without it **no round is publicly reachable**.
2. **Legal texts** (*Texte*): review the imprint and privacy policy for every language and replace the
   remaining `[PLACEHOLDERS]`. Details in [Privacy and legal]({{ '/guide/privacy/' | relative_url }}).
3. **Mail check:** *Texte → E-Mail-Versand* shows the transport and sender. Make a test booking with your
   own e-mail address and check that the confirmation arrives (and is not marked as spam).
4. **Invite teachers** (*Benutzer*).

## Teacher accounts

Under *Benutzer* (users) → *+ Neuer Benutzer* (new user) enter **name and e-mail address**. The teacher
receives an **invitation mail** with a link to set their own password (single use, valid for 48 hours by
default). No password is ever sent by mail. The username is the e-mail address. If the mail cannot be
sent, the link is shown once so you can pass it on yourself.

For an existing account you can:

- **deactivate** it (*Konto aktiv* unticked): no login, the teacher's rounds are not publicly reachable;
- send a new **invitation** or a **password reset link** (*Einladung erneut senden* / *Link zum
  Zurücksetzen senden*). The old password stops working and all sessions of the account end;
- **delete** it, including all its rounds and bookings.

New accounts are always teachers; there is no second admin.

## Rounds of all teachers

The admin sees and edits **all rounds** (*Alle Runden*) with their bookings, and can create a round for a
teacher or move a round to another teacher (field *Lehrkraft* in the round form). The round then appears
for that teacher, parents see their name and summaries go to them. Teachers only ever see their own
rounds; this is enforced centrally and covered by tests.

How rounds work is described in the [Teacher guide]({{ '/guide/teachers/' | relative_url }}).

## Legal texts

*Texte* lists imprint (*Impressum*) and privacy policy (*Datenschutz*) per language. They are written in
simple Markdown (headings with `#`, lists, bold, links). Two placeholders are filled in automatically:

| Placeholder | Filled with |
|---|---|
| `[[VERANTWORTLICHE]]` | the admin's details for imprint and privacy policy |
| `[[HOSTER]]` | the web host from the configuration (`hosting`) |

Languages without their own version fall back to English. Hints on the dashboard and under *Texte* tell
you when a text is missing a placeholder or is outdated.

## Notifications

Under *Mein Profil* every account chooses its **summary mails** (*Zusammenfassungen per E-Mail*): an
overview of the next day in the evening (from 18:00 by default) and an overview after the booking
deadline of a day. They contain time and name only, no e-mail addresses of parents, plus a calendar file.
