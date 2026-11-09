---
title: Security
description: The protections built into TalkSlot, and how to report a vulnerability.
---

## Reporting a vulnerability

Please use GitHub's **private vulnerability reporting**
([*Security → Report a vulnerability*](https://github.com/skornehl/talkslot/security/advisories/new)),
not public issues. [`SECURITY.md`](https://github.com/skornehl/talkslot/blob/main/SECURITY.md) says which
details help and what happens next: the fix is prepared privately in a GitHub Security Advisory and published
together with the release that contains it (see
[Security fixes]({{ '/guide/development/' | relative_url }}#security-fixes)).

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
- Slot squatting (one person taking all slots of a round with made-up names): successful bookings are
  limited per round and shortened IP address (15 per hour by default, generous because of school Wi-Fi).
  More than 5 bookings of a round from one network within 5 minutes send a warning to the teacher of the
  round and all admins; under each day, *Mehrere Buchungen löschen …* (delete several bookings) deletes the
  bookings of a time span at once. A round with *Nur mit Rundencode* (round code required) is only open to
  those who got the code, the best protection for rounds meant for one class.
- Mails under the school's address: the mails parents trigger themselves (confirmation, change notice,
  cancellation, the confirmation sent again) go to one address at most 10 times per hour and 20 times per day;
  above that the action still works, without a mail. The address is only counted as an HMAC hash and deleted
  after 24 hours at the latest. Rebookings are also limited per shortened IP address, and the operator gets
  an alert when parents trigger more than 100 mails in an hour (`mail_alert_hourly`).

**Tokens**
- Change links, invitation and password links are 256-bit random tokens; only their SHA-256 hash is stored.
- The appointment page hands out the change link only as a copy, a calendar file answered to a POST button
  (no extra GET address with the token) and, where the device offers it, a share dialog with a warning. A
  confirmation mail to an address entered there is limited to two per booking within 24 hours.
- Round codes: 8 characters from 31 symbols (31⁸ combinations), wrong attempts limited to 30 per 15 minutes
  and shortened IP address.

**Admin accounts**
- Session cookie only for `/admin`, `HttpOnly`, `Secure`, `SameSite=Strict`, strict mode, own session
  directory, 30 minutes idle and 8 hours absolute timeout, new session ID on login. A request without the
  session cookie gets no session at all (no file, no cookie): pages of the admin area send it to the login
  page, which leads back to the page asked for after signing in (only paths of the admin area, never
  another site). The browser keeps back the strict cookie on links from other sites, such as the link in
  a summary opened in a webmailer; such a request (`Sec-Fetch-Site: cross-site`) gets a small interim page
  that opens the same address again from TalkSlot (meta refresh), so a signed-in browser lands signed in
  and its cookie is never replaced. It does so once: the address it opens carries `reopened=1`, and a
  browser that still holds the cookie back then gets the login page instead of a loop.
- Admins see the parents' names and addresses in another teacher's round only after *Vertretung übernehmen*
  (take over as substitute; with their password, logged, the teacher is told by e-mail, for one sign-in), which limits what a taken-over
  admin session can read at once.
- Changing or resetting a password ends all other sessions of the account. *Alle Sitzungen beenden* (end
  all sessions, under *Benutzer*) ends the sessions of all accounts (a global counter in the settings).
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
- Every admin route names the role it needs (`teacher` for every account, or `admin`) in the route table of
  `AdminController`. A route without a known role is admin-only, so a forgotten role fails closed.
- Inviting an admin, giving or taking away admin rights, deactivating, reactivating or deleting an admin
  account, changing an e-mail address (own or somebody else's) and ending all sessions ask for the
  password of the acting account again. Wrong entries there and when changing the own password count
  together, at most 5 in 15 minutes per sign-in (account and session). A new sign-in needs the password,
  so a taken-over session alone is not enough for these changes, and it gets no unlimited guessing via
  the password change either. It cannot lock the account holder out of them: their own sign-in has its
  own counter.
- Security mails: to the account holder when its password was changed or set, to the previous address when
  the e-mail address or username was changed, to all active admins and the person concerned when admin
  rights change and when all sessions were ended.
- At least one active admin always remains: the check and the change run in one transaction that locks the
  rows of the active admins (`SELECT … FOR UPDATE`), so two admins demoting each other at the same moment
  cannot both succeed.
- Password links are only sent by mail and never shown, not even when sending fails. "Forgot password"
  answers identically for every input and does not replace a link younger than 15 minutes.
- Password and token parameters are marked `#[\SensitiveParameter]`: they never appear in stack traces,
  even with `debug` on (a unit test checks every such parameter).

**Operations**
- No error details in production; `public/.user.ini` also hides errors that occur before the app starts.
  The app writes its own log with values, quotes and e-mail addresses removed.
- `app/` and `config/` live outside the web root and are additionally denied by `.htaccess`. The
  deployment restricts `config.php` to its owner (`chmod 600`) in every version on the server, and the
  dashboard warns admins while every user of the server can read one of these copies (it cannot tell
  whether a group in `640` is shared with other customers). After changing secrets, `prune` deletes the
  old versions with their old `config.php`.
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

## If you suspect misuse

For example a lost device that was signed in, an admin account nobody knows, a security mail about a
change nobody made, or entries in *Protokoll* (log) nobody can explain:

1. **End all sessions:** *Benutzer → Alle Sitzungen beenden* (with your password). Every account has to sign
   in again; changing the app secret alone would not sign anybody out.
2. **Check the accounts:** under *Benutzer*, unknown admins, changed e-mail addresses and recent changes in
   *Protokoll*. Deactivate unknown accounts, take away admin rights that are not needed.
3. **New passwords:** change your own password; for affected accounts use *Link zum Zurücksetzen senden*
   (their previous password stops working immediately).
4. **Access to the server:** if hosting, database, FTP or GitHub may be affected, change those passwords
   and `APP_SECRET`. The secret only signs short-lived things (open forms, round-code access, rate-limit
   keys); the parents' personal links are stored as plain SHA-256 hashes on purpose and keep working.
5. **Keep the evidence:** save the entries of *Protokoll* (deleted after `audit_log_days`, default 90
   days), the security mails and `app/var/php-error.log`, and ask your web host for its access logs.
6. **Report:** if personal data may have been seen by unauthorised people, the operator (usually the
   school) must assess it and report it to the data protection supervisory authority within 72 hours
   (Art. 33 GDPR), and inform the people concerned if the risk is high (Art. 34 GDPR). Involve your data
   protection officer.

## Threat model {#threat-model}

Who can do what, where the trust boundaries are, and which risks are accepted on purpose. Operators can use it
for their documentation (see [documents for the operator]({{ '/guide/operator-documents/' | relative_url }})).

| Role | Trust | Can |
|---|---|---|
| Parents | none, anonymous | book through the round link or code, see and change their own booking with its personal link |
| Teachers | signed in, trusted with their own rounds | see and change the bookings of their own rounds, enter bookings |
| Admins | trusted with all data of the installation | accounts and roles, all rounds, legal texts, the log |
| Operator with server access (FTP, database, GitHub secrets) | fully trusted | everything, including the secrets in `config.php` |
| Web host, mail provider | processors, bound by contract | the stored data, the files, the mails in transit |
| Attackers | – | everything on the internet can reach the forms, the sign-in and guess links and codes; others on the same network or the same shared server |

**Trust boundaries**
1. **Browser ↔ web server (HTTPS):** every input is untrusted: CSRF tokens, rate limits, validation,
   escaped output, security headers.
2. **Web server ↔ database:** prepared statements only; the credentials are in `config.php` (mode 600 with the
   automatic deployment; set it yourself on a manual installation).
3. **Web server ↔ mail server:** SMTP with TLS if `mail.transport` is `smtp`, otherwise the host's `mail()`; once sent, mails are beyond TalkSlot's control (mailboxes of
   parents and teachers).
4. **Deployment:** GitHub Actions builds `config.php` from secrets and uploads it via FTPS; whoever controls
   the repository and its secrets controls the installation.
5. **Shared hosting:** other customers of the host are separated by the host (file permissions, PHP user);
   the dashboard warns when `config.php` is readable by others.

**Accepted residual risks**
- **The personal link in the URL path** (`/b/<token>`): it can end up in the web server's access logs, the
  browser history and forwarded mails. Mitigated by `Referrer-Policy: no-referrer`, storing only its hash and
  a short log retention at the host; moving it out of the path would break booking without JavaScript.
- **The round code in plain text:** teachers have to pass it on; it only keeps strangers away from the list
  of slots.
- **No second factor** for accounts (see below).
- **Rate limits per shortened IP address** can hit shared networks such as school Wi-Fi (see below).
- **Mails are encrypted in transit only**, not end to end.
- **Admins see all bookings:** they are trusted and bound to confidentiality; the log shows what they change.
- **Summary mails and printed lists** are outside TalkSlot once they reach the teacher.

## Known limitations

- Rate limits work per shortened IP address. Somebody in the same network (e.g. school Wi-Fi) who keeps
  sending wrong passwords blocks logins from that network for 15 minutes. In the same way, somebody who
  books many slots of a round through the school Wi-Fi uses up its limit for the other parents there for an
  hour; they see a hint to use another connection (e.g. mobile data) or to contact the teacher.
- With many shortened IP addresses (e.g. IPv6 or a botnet) somebody can still take the slots of an open
  round. The warning and the bulk deletion help to notice and repair it; *Nur mit Rundencode* prevents it.
- Somebody who knows a username (for new accounts it is the e-mail address, which parents see as the
  reply address unless the account gives its own reply address for parents) can use up its per-account
  limit. For up to an hour, the account can then only be used
  on devices that signed in to it before, or after setting a new password through *Passwort vergessen?*
  in that browser. An e-mail to the account's address says so.
- There is no second factor. Accounts are protected by the password rules and the limits above.
- The round code is stored in plain text, because teachers need to pass it on. It protects the list of
  slots from strangers, not accounts.
