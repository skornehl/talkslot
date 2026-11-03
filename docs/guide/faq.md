---
title: FAQ
---

## Can parents book without an e-mail address?

Yes. The e-mail address is optional. Without it, parents get their personal change link only on the
confirmation page and should bookmark it.

## Can I use TalkSlot for something other than parent-teacher conferences?

The wording is made for schools ("teacher", "parents"), but any setting where one person offers time
slots and others book one works: consultation hours, school enrolment talks, internship interviews.

## Does it need a cron job?

It is recommended, so that bookings are deleted and summary mails are sent on time. Without shell access,
use the web cron (the included GitHub workflow calls it hourly). As a fallback, clean-up also runs on page
views at most every 15 minutes.

## Why is the admin area only in German?

TalkSlot started at a German school. Parent pages and mails are translated into six languages, because
parents come from many backgrounds. Translating the admin area is welcome as a contribution.

## How do I add a language for parents?

Copy `app/lang/en.php`, translate it, add a flag under `public/assets/flags/` and the code to `languages`.
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
