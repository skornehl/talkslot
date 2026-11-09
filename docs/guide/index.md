---
title: Overview
description: What TalkSlot is, the words it uses, and where to find what in this documentation.
---

TalkSlot is a web application for booking parent-teacher conferences (*Elternsprechtag*). Teachers
publish time slots, parents book one, without creating an account. It is written in plain PHP with a
MariaDB database and runs on ordinary shared hosting.

![Booking page of a round]({{ '/assets/img/parent-round.webp' | relative_url }})

## Who uses it

| Role | Where | What they do |
|---|---|---|
| **Parents** | public pages | open a round, book a slot, move or cancel it with their personal link |
| **Teachers** | `/admin` | create rounds, manage bookings of their own rounds |
| **Admins** | `/admin` | one or more accounts: invite teachers, see and edit all rounds, create rounds for teachers, maintain templates, imprint and privacy policy on behalf of the operator (the school, or the private person, association or provider running TalkSlot), which is the controller under the GDPR |
| **Operator** | server, GitHub | installs, configures and updates TalkSlot (often one of the admins) |

The admin area is in German or English (per account). Parent pages and mails are available in German, English, Polish,
Russian, Ukrainian and Turkish; more languages can be added.

## Concepts

Round (*Terminrunde*)
: A set of appointments offered by one teacher, e.g. "Parent-teacher conference class 7b". It has a
  readable link, a round code, a description, an optional location, deadlines and a booking limit.

Template (*Vorlage*)
: Times, breaks and settings that admins prepare once (e.g. "parent-teacher day, 08:00–16:00,
  15 minutes"); anybody can start a new round from it.

Day and slots
: A round has one or more days. Each day has a time range, a slot length and optional breaks; the slots
  are generated from that. Single slots can be blocked.

Booking
: A parent's appointment: name, optional e-mail address, slot. Deleted automatically from midnight after
  the appointment.

Round code
: Eight characters such as `K7MP-3QXA`. Parents can enter it on the start page instead of using the link.
  With *Nur mit Rundencode* ("round code required") the link alone shows nothing.

Change link
: Personal link shown after booking (and sent by mail if an address was given). It lets parents move or
  cancel their appointment. Only a hash of it is stored.

## How this documentation is organised

- **[Getting started]({{ '/guide/getting-started/' | relative_url }})**: run TalkSlot locally with demo data.
- **Running TalkSlot**: [installation]({{ '/guide/installation/' | relative_url }}),
  [configuration]({{ '/guide/configuration/' | relative_url }}),
  [deployment with GitHub Actions]({{ '/guide/deployment/' | relative_url }}) and
  [operations]({{ '/guide/operations/' | relative_url }}) (updates, cron, alerts, backups).
- **Using TalkSlot**: guides for the [admin]({{ '/guide/admin/' | relative_url }}),
  [teachers]({{ '/guide/teachers/' | relative_url }}) and a page you can link for
  [parents]({{ '/guide/parents/' | relative_url }}).
- **Background**: [privacy]({{ '/guide/privacy/' | relative_url }}), [documents for the operator]({{ '/guide/operator-documents/' | relative_url }}),
  [security]({{ '/guide/security/' | relative_url }}),
  [development]({{ '/guide/development/' | relative_url }}) and the [FAQ]({{ '/guide/faq/' | relative_url }}).
