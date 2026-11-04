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
| Shortened, HMAC-hashed IP addresses | database (rate limiting) | after 24 hours at the latest |
| Teacher accounts | database | by an admin; inactive accounts without rounds 15 months after the last login |
| Summary mails to teachers (time and name, no e-mail addresses) | teacher's mailbox | the privacy policy promises at most 15 months |
| Log of admin changes (who, when, what; no data of parents) | database | after 90 days (`audit_log_days`) |

TalkSlot keeps **no** access logs of its own. The web server's logs are the web host's responsibility;
ask how long they are kept and state it in the privacy policy.

## Design decisions

- **Data minimisation:** a free-text name and an optional e-mail address, nothing else.
- **E-mail address only for the appointment:** confirmation, changes, cancellation and, if the teacher
  turned it on for the round, a reminder the evening before. The privacy template names all of these.
- **Admin log without monitoring teachers:** only changes made by admin accounts are logged, so it shows
  who among several admins did what. What teachers do in their own rounds is not recorded, and bookings
  appear only as date and time. Depending on your state, a log of staff actions may still be a matter for
  the staff council (*Personalrat*); you can shorten the retention with `audit_log_days`.
- **Sign-in times only in coarse steps:** the time of the last sign-in is stored (to tidy up unused
  accounts), but the users list only shows *never*, *within the last 12 months* or *more than 12 months
  ago*, so admins do not see when colleagues work. Security mails about account changes name the account
  concerned and who made the change, never data of parents.
- **No cookies on parent pages.** The language travels in the URL (`?lang=`), CSRF protection is
  stateless. No cookie banner is needed. Only the admin area uses a session cookie, limited to `/admin`.
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
  link and label of the appointment in `localStorage`. Nothing is sent to the server.
- **Light/dark choice:** only stored when chosen explicitly (`light` or `dark`); strictly necessary for
  that function, so no consent is needed. "Automatic" removes the entry.
- **Booking limits** only compare with bookings of the same round, using data that is stored anyway. The
  rejection does not reveal when or by whom a slot was booked.
- **Acknowledgement, not consent:** the privacy notice does not need to be "accepted" under the GDPR; it
  informs (Art. 13). The required checkbox is worded as *having read* the notice, and the notice states
  that it is not consent.

## Legal templates

Imprint and privacy policy templates in German and English are copied into the database at installation
and can be edited by admins under *Texte*. The privacy template covers the information required by
Art. 13 GDPR: controller, purposes, legal bases including legitimate interests, recipients, third
countries, retention, rights of data subjects including the right to object, right to lodge a complaint,
whether providing data is required, and automated decision-making.

Before going live:

1. **Controller:** fill in *Texte → Angaben für Impressum und Datenschutz*. It replaces
   `[[VERANTWORTLICHE]]`; without it no round is public.
2. **Web host:** set `hosting` in the configuration (or `HOSTING_DE`/`HOSTING_EN` when deploying). It
   replaces `[[HOSTER]]`. Unset, the page shows a visible placeholder and admins see a hint.
3. **Remaining placeholders** in square brackets: retention of the web server logs (ask your web host) and
   the competent data protection supervisory authority.
4. **Data processing agreement** (DPA, *AVV*) with your web host.
5. If teachers use the platform: the operator (usually the school) is responsible for all rounds, the
   teachers act on its behalf and instructions.

Have the texts reviewed by someone who knows your situation; the templates do not replace legal advice.
