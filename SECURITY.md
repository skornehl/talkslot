# Security policy

## Supported versions

Security fixes are made for the latest release only. Operators should update to it; the
[deployment](https://skornehl.github.io/talkslot/guide/deployment/) can pin a version and roll back. To hear
about fixes, watch the repository's releases on GitHub (*Watch → Custom → Releases*): the release notes of a
security fix link its advisory.

## Reporting a vulnerability

Please report vulnerabilities **privately** through GitHub's private vulnerability reporting:
[Security → Report a vulnerability](https://github.com/skornehl/talkslot/security/advisories/new).
Do not open a public issue or pull request for it. This needs a GitHub account; without one, open an issue
that only asks for a way to get in touch, without any details.

Helpful details:

- the version ("Powered by TalkSlot …" in the footer of every page, or the release you deployed),
- what an attacker can do and under which conditions (signed in as teacher or admin, as a parent, without
  an account),
- steps to reproduce, ideally against the local Docker environment (`dev/compose.yml`).

Please do not test against installations you do not run yourself, and do not access or keep data of
others: TalkSlot stores parents' names and e-mail addresses. Problems of a particular hosting setup (web
server, PHP or database configuration of an installation) are a matter for its operator, not for this
project, unless TalkSlot's documentation recommends that setup.

## What happens next

TalkSlot is maintained in spare time, so there are no fixed response times. A report is confirmed and
assessed in a draft security advisory; the fix is prepared in the advisory's temporary private fork, released
as soon as it is ready and then published as an advisory naming the fixed version, with credit to the
reporter if wanted. The [development guide](https://skornehl.github.io/talkslot/guide/development/#security-fixes)
describes this process for maintainers.

The protections that are built in are listed under
[Security](https://skornehl.github.io/talkslot/guide/security/).
