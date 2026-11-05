---
title: FAQ
---

## Can parents book without an e-mail address?

Yes. The e-mail address is optional. Without it, parents get their personal change link on the
confirmation page. There they can copy it, add the appointment to their calendar (the link is in the
calendar entry) or have the confirmation sent to an e-mail address after all; that address is not stored.

## Are parents told when a teacher cancels or moves their appointment?

Yes, by e-mail in their language, if they gave an address: a cancellation when the teacher deletes the
booking, its day or the round (or an admin the teacher's account), and the new details when a day gets
another date, time or location, or the round another location or teacher. The box *Eltern per E-Mail
informieren* is ticked by default. Parents without an address, or entries the teacher made without ever
sending a mail, are named in the message afterwards so the teacher can tell them in person. See the
[Teacher guide]({{ '/guide/teachers/' | relative_url }}).

## Can I use TalkSlot for something other than parent-teacher conferences?

The wording is made for schools ("teacher", "parents"), but any setting where one person offers time
slots and others book one works: consultation hours, school enrolment talks, internship interviews.

## Does it need a cron job?

Yes, so that bookings are deleted on time and summary mails, reminders and retried confirmations are sent:
apart from the confirmations sent right away, no mail is sent while a visitor waits. Without shell access, use the web cron (the included GitHub
workflow calls it hourly). As a fallback, clean-up also runs on page views at most every 15 minutes, and
with PHP-FPM or LiteSpeed a page view sends up to 10 waiting mails after its response.

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

No. TalkSlot is meant to be run by the school or its IT provider, so the data stays under their control.

## What does the license mean for me?

TalkSlot is licensed under the [GNU AGPL v3](https://github.com/skornehl/talkslot/blob/main/LICENSE). You
can use, change and host it freely. If you run a **modified** version for others, you must offer them the
source code of your changes.
