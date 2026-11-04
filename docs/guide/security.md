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
  `Cache-Control: no-store`. They are sent on every answer, including 404 and error pages and the 403 pages
  Apache creates itself.
- CSRF protection: session token in the admin area; a stateless HMAC token with minimum and maximum age on
  public pages.
- Form posts that the browser marks as coming from another website (`Sec-Fetch-Site: cross-site`) are
  refused before anything is processed. This also stops login CSRF (a foreign page signing you in to an
  account of the attacker). `Origin` is deliberately not checked: with `Referrer-Policy: no-referrer`
  browsers send `Origin: null` on TalkSlot's own forms too. Browsers without the header are still
  protected by the tokens.
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
- Brute-force protection: at most 5 failed logins per shortened IP address in 15 minutes and 20 per
  username and hour. Responses and timing do not reveal whether an account exists.
- Passwords from 12 characters up to 72 bytes (the bcrypt limit).
- Every admin route names the role it needs (`teacher` for every account, or `admin`) in the route table of
  `AdminController`. A route without a known role is admin-only, so a forgotten role fails closed.
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

**Tested invariants**

Tests guard the rules an extension could break without anybody noticing:
- Every admin route has a role, and teachers get 404 for every admin route (with a valid form token).
- No GET route changes data: the smoke test opens every admin GET route of the route table and the public
  pages, also with form fields in the query string, and the database and the outgoing mail must be unchanged
  afterwards. A new route with an unknown placeholder makes the test fail until it is covered.
- Opening a change link (`GET /b/…`, which mail scanners and link previews do) never cancels, moves or
  sends mail.
- Every way of booking or moving an appointment, by parents and by the teacher, waits for the lock on the
  day that recalculating the slots holds, and only `BookingService` writes bookings.
- 404, 403 and error pages carry the security headers, and `.htaccess` sends the same values as PHP.

## Known limitations

- Rate limits work per shortened IP address. Somebody in the same network (e.g. school Wi-Fi) who keeps
  sending wrong passwords blocks logins from that network for 15 minutes.
- The round code is stored in plain text, because teachers need to pass it on. It protects the list of
  slots from strangers, not accounts.
