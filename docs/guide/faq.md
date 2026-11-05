---
title: FAQ
---

## Can parents book without an e-mail address?

Yes. The e-mail address is optional. Without it, parents get their personal change link on the
confirmation page. There they can copy it, add the appointment to their calendar (the link is in the
calendar entry) or have the confirmation sent to an e-mail address after all; that address is not stored.

## Can I use TalkSlot for something other than parent-teacher conferences?

The wording is made for schools ("teacher", "parents"), but any setting where one person offers time
slots and others book one works: consultation hours, school enrolment talks, internship interviews.

## Does it need a cron job?

It is recommended, so that bookings are deleted and summary mails and reminders are sent on time. Without shell access,
use the web cron (the included GitHub workflow calls it hourly). As a fallback, clean-up also runs on page
views at most every 15 minutes.

## Which languages does the admin area speak?

German and English. Every account picks its language under *Mein Profil* (*Sprache / Language*); mails to
teachers (invitation, password link, summaries) follow it. Before signing in, the browser language decides;
`admin_language` sets the default. Parent pages and mails are translated into six languages, because
parents come from many backgrounds.

## A teacher cannot sign in, although the password is right

After too many failed attempts TalkSlot refuses further ones for a while, even with the right password:
from the same network (shortened IP address) for 15 minutes, and for an account after 20 failed attempts
within an hour from devices that never signed in to it. Devices that signed in before are exempt from the
second limit, and the account gets an e-mail when it is reached. Ways out: wait, use a device that signed
in before, or set a new password with *Passwort vergessen?*; afterwards that browser can sign in at once.

## How do I add a language for parents?

Copy `app/lang/en.php`, translate it, add a flag under `public/assets/flags/` (for the admin translation
form) and the code to `languages`.
See [Configuration]({{ '/guide/configuration/' | relative_url }}#languages).

## Does it work with MySQL, PostgreSQL or SQLite?

No. TalkSlot needs MariaDB 10.6 or newer; migrations use MariaDB syntax.

## Does it work with nginx?

The shipped rules are for Apache (`public/.htaccess`). With nginx you need equivalent rules: route all
requests that are not existing files to `index.php`, deny hidden files and `app_root.php`, and send the
security headers.

## Why so many tests for such a small app?

Because it handles personal data of families and runs where you cannot easily debug: on shared hosting,
often maintained by volunteers. Every bug found by a test is one a school does not find on the evening
of the conference.

## Is there a hosted version?

No. TalkSlot is meant to be run by whoever is responsible for the data: the school or its IT provider, or
a private person, association or independent provider whose service teachers use (choose the operator type
when installing, see [Privacy and legal]({{ '/guide/privacy/' | relative_url }}#operated-by-the-school-or-privately)).

## What does the license mean for me?

TalkSlot is licensed under the [GNU AGPL v3](https://github.com/skornehl/talkslot/blob/main/LICENSE). You
can use, change and host it freely. If you run a **modified** version for others, you must offer them the
source code of your changes.
