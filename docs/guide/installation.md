---
title: Installation
description: Put TalkSlot on a web server by hand. For automatic deployments see the next chapters.
---

## Requirements {#requirements}

- **PHP 8.3 or newer** (end-to-end tests on 8.3 and 8.5, unit tests on 8.3–8.5) with `pdo_mysql`, `mbstring` and `iconv`;
  `openssl` for SMTP with TLS
- **MariaDB 10.6 or newer** (tested on 10.6 and 11.8). Migrations use MariaDB syntax; MySQL is not supported.
- **Apache** with `mod_rewrite`, ideally `mod_headers`. The rules are in `public/.htaccess`; other web
  servers need equivalent rules (front controller, deny hidden and internal files, security headers).
- **HTTPS** with a valid certificate. TalkSlot redirects plain HTTP.
- A **sender address** whose domain has SPF and DKIM, otherwise mails end up in spam.

TalkSlot checks this itself. If the PHP version is too old or an extension is missing, every page says so
in German and English instead of showing a blank error page (from PHP 7.1 on; on the command line, for
example in the cron job, in English). Hosts often run an older `php` on the command line than on the web
server: then use the full path of the newer one in the cron job (for example `/usr/bin/php8.3`). The
installer refuses MySQL and MariaDB before 10.6, and an `app/var/` that PHP cannot write to; on a site that is
already installed, the dashboard tells admins. See also
[Checking the configuration]({{ '/guide/configuration/' | relative_url }}#checking).

## 1. Upload the files

Only `public/` may be reachable from the web. `app/`, `bin/` and `config/` must live outside the
document root.

**If you can choose the document root** (recommended): upload the whole project, for example to
`/var/www/talkslot`, and point the document root of your (sub)domain to `/var/www/talkslot/public`.

**If you cannot** (typical shared hosting with a fixed web directory): upload the *contents* of
`public/` into the web directory and everything else into a sibling directory, for example
`talkslot-app/`. Then create `app_root.php` in the web directory so `index.php` finds the code:

```php
<?php
return dirname(__DIR__) . '/talkslot-app';
```

`app/var/` must be writable by PHP: sessions, the install lock file, the error log and the alert
counter are stored there.

## 2. Configure

Copy `config/config.sample.php` to `config/config.php` and fill in at least:

| Key | Value |
|---|---|
| `base_url` | address of the site without trailing slash, e.g. `https://termine.example.org` |
| `secret` | random, at least 32 characters: `php -r 'echo bin2hex(random_bytes(32));'` |
| `install_key` | random, at least 16 characters, only needed for the web installer |
| `db` | host, name, user, password; `prefix` if the database is shared |
| `mail` | `from_address` and the transport (`mail` or `smtp`) |
| `hosting` | your web host for the privacy policy, see [Privacy]({{ '/guide/privacy/' | relative_url }}) |

All options are explained in [Configuration]({{ '/guide/configuration/' | relative_url }}). Keep
`config.php` readable only for the PHP user (`chmod 600` or `640`): it contains the database and mail
passwords and the secret. The dashboard warns admins as long as every user of the server can read it.

## 3. Install

Open `https://your-site/install.php`, enter the install key and create the admin account (username,
password with 12 characters up to 72 bytes that is not easy to guess, e-mail address; the same
[password rules]({{ '/guide/security/' | relative_url }}) apply as for every account). Choose the
**operator type** (*Betriebsart*): *operated by the school* (a public body) or *operated privately* (a private
person, association or independent provider whose service teachers use). It decides the templates for imprint
and privacy policy, see [Privacy and legal]({{ '/guide/privacy/' | relative_url }}#operated-by-the-school-or-privately);
you can change it later under *Texte*. The installer creates the tables, copies the imprint and privacy
templates of that type into the database, locks itself and tries to delete itself. Delete
`public/install.php` yourself if it is still there.

With shell access you can install from the command line instead:

```bash
php bin/install.php <username> [email]   # asks for the password and the operator type (school or private)
```

In a script, pass the three answers on standard input, one per line: password, password again, `school` or
`private`.

## 4. Set up the cron job

TalkSlot deletes past bookings, deactivates and deletes finished rounds and sends teacher summaries,
reminders to parents and mails that could not be sent at once in a cron job. Run it hourly:

```cron
5 * * * * php /path/to/talkslot/bin/cron.php
```

Without shell access use the **web cron**: `POST /cron` with the header `X-Cron-Token`. The token is the
hex-encoded HMAC-SHA256 of the word `cron` with your `secret`:

```bash
TOKEN=$(printf cron | openssl dgst -sha256 -hmac "$SECRET" | sed 's/.*= //')
curl -fsS -X POST -H "X-Cron-Token: $TOKEN" https://your-site/cron
```

The included GitHub workflow `cron.yml` does exactly this every hour. As a fallback, clean-up also runs on
page views at most every 15 minutes; mails only go out there with PHP-FPM or LiteSpeed, after the page has
been delivered. Without a cron job, reminders and summaries may therefore not be sent at all (see
[mail queue]({{ '/guide/operations/' | relative_url }}#mail-queue)).

## 5. Fill in the legal details

Log in as admin and open *Texte*. Fill in *Angaben für Impressum und Datenschutz* (name of the
school or person, address, e-mail) and check the operator type there. Until then **no round is public**.
Then check *Texte*: imprint and privacy policy are installed from the templates of the operator type (the
templates of the other type can be loaded on the text form). They contain `[PLACEHOLDERS]` (data protection
officer, for a school the provisions of your state, server log retention at your web host, supervisory
authority) that you must replace; until then **no round can be activated**. See
[Privacy and legal]({{ '/guide/privacy/' | relative_url }}).

## Go-live checklist

- [ ] HTTPS active, `http://` redirects to `https://`
- [ ] `install.php` returns 404; `config/config.php` is not readable by others
- [ ] `debug` is `false`, `alert_email` is set
- [ ] Cron job or web cron runs (`bin/cron.php -v` prints the counts); if it stops, admins see a warning on
      the dashboard and you get an alert mail ([cron monitoring]({{ '/guide/operations/' | relative_url }}#cron-monitoring))
- [ ] Test booking with e-mail address: the mail arrives and is not marked as spam
- [ ] Admin details, imprint and privacy policy complete in all languages you offer (no `[PLACEHOLDERS]`
      left; the dashboard shows no hint)
- [ ] Operator type under *Texte* matches who really runs TalkSlot (the school or privately)
- [ ] Data processing agreement (DPA, *AVV*) with your web host; if the school is the operator but someone
      else runs the server, an agreement under Art. 28 GDPR between the school and that person
- [ ] Teacher invitation tested; a test round booked, moved and cancelled on a phone, then deleted
