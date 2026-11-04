---
title: Security
description: The protections built into TalkSlot, and how to report a vulnerability.
---

## Reporting a vulnerability

Please use GitHub's **private vulnerability reporting**
([*Security → Report a vulnerability*](https://github.com/skornehl/talkslot/security/advisories/new)),
not public issues.

## Built-in protections

**Database**
- PDO prepared statements everywhere, without emulation, in strict SQL mode. A unit test parses every
  database call and fails if a variable is concatenated into SQL text.
- Smoke tests send typical SQL injection payloads to every input.
- Double bookings are prevented twice: `SELECT … FOR UPDATE` in a transaction and a unique key on the slot.

**Output and input**
- Every output is escaped. Markdown is escaped first; links only allow http(s), mailto and relative paths.
- Strict Content Security Policy without `unsafe-inline`, `X-Frame-Options: DENY`, `nosniff`,
  `Referrer-Policy: no-referrer` (change links do not leak via the referrer), HSTS, Permissions-Policy and
  `Cache-Control: no-store`.
- CSRF protection: session token in the admin area; a stateless HMAC token with minimum and maximum age on
  public pages.
- Spam protection: honeypot field, minimum time to fill in a form, rate limits.

**Tokens**
- Change links, invitation and password links are 256-bit random tokens; only their SHA-256 hash is stored.
- Round codes: 8 characters from 31 symbols (31⁸ combinations), wrong attempts limited to 30 per 15 minutes
  and shortened IP address.

**Admin accounts**
- Session cookie only for `/admin`, `HttpOnly`, `Secure`, `SameSite=Strict`, strict mode, own session
  directory, 30 minutes idle and 8 hours absolute timeout, new session ID on login. Anonymous visitors of
  the login page get no session at all.
- Changing or resetting a password ends all other sessions of the account.
- Brute-force protection: at most 5 failed logins per shortened IP address in 15 minutes, and 20 per
  account and hour (counted by account, so different spellings of a name share the counter). Only
  usernames an account can have (`A–Z a–z 0–9 . _ @ + -`) reach the database: its collation ignores case
  and accents, so `SÁBRİNA` or a name with a zero-width space would otherwise find `sabrina` under a
  counter of its own. Responses and timing do not reveal whether an account exists.
- The per-account limit is a lockout for *new* devices only. A browser that signed in to the account
  before, or set its password through an e-mailed link, keeps a signed **known-device cookie**
  (`tp_device_…`, only sent to `/admin/login`, `HttpOnly`, `SameSite=Strict`, 365 days, renewed at every
  sign-in). With it, only the IP limit and a limit of 20 failed attempts per device apply, so strangers
  cannot lock teachers out of their own devices. When the account limit is reached, the owner gets an
  e-mail (at most one a day) explaining the way out: a known device, or *Passwort vergessen?*.
- Passwords: 12 characters up to 72 bytes (the bcrypt limit), and not easy to guess: no common password
  from a local list (`app/data/common-passwords.txt`, nothing is sent anywhere), also not as a word with
  digits or symbols around it (`Sommer2024!!!!`), no repetitions or keyboard runs (`passwortpasswort`,
  `123456789012`, `1q2w3e4r5t6z`), and neither the username, the e-mail address nor the app name in it.
  The installer applies the same rules to the first admin account.
- Changing the e-mail address requires the current password and notifies the old address. "Forgot
  password" answers identically for every input and does not replace a link younger than 15 minutes.

**Operations**
- No error details in production; `public/.user.ini` also hides errors that occur before the app starts.
  The app writes its own log with values, quotes and e-mail addresses removed.
- `app/` and `config/` live outside the web root and are additionally denied by `.htaccess`.
- The deployment validates configuration values with a fixed set of options (a typo in `SMTP_ENCRYPTION`
  would otherwise mean plain-text passwords) and never ships the installer after installation.
- CI and deployment use no third-party GitHub Actions; code is fetched with plain `git` and the token is
  only sent in a request header.

## Known limitations

- Rate limits work per shortened IP address. Somebody in the same network (e.g. school Wi-Fi) who keeps
  sending wrong passwords blocks logins from that network for 15 minutes.
- Somebody who knows a username (for new accounts it is the e-mail address, which parents see as the
  reply address) can use up its per-account limit. For up to an hour, the account can then only be used
  on devices that signed in to it before, or after setting a new password through *Passwort vergessen?*
  in that browser. An e-mail to the account's address says so.
- There is no second factor. Accounts are protected by the password rules and the limits above.
- The round code is stored in plain text, because teachers need to pass it on. It protects the list of
  slots from strangers, not accounts.
