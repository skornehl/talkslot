---
title: Getting started
description: Run TalkSlot on your own computer with Docker, fill it with demo data and click through it.
---

## Requirements

- [Docker](https://docs.docker.com/get-docker/) with Compose
- Git

The Docker setup contains PHP 8.5 with Apache, MariaDB 11.8 and [Mailpit](https://mailpit.axllent.org/),
which catches every mail TalkSlot sends.

## Start the local instance

```bash
git clone https://github.com/skornehl/talkslot.git
cd talkslot
cp dev/config.dev.php config/config.php
docker compose -f dev/compose.yml up -d --build
dev/reset.sh       # fresh database and admin account
dev/demo.sh        # optional: three demo rounds with bookings
```

| | URL | |
|---|---|---|
| Parent pages | <http://localhost:8080> | |
| Admin area | <http://localhost:8080/admin> | user `sabrina`, password `Sehr-Sicheres-Dev-Pw1` |
| Mails | <http://localhost:8025> | Mailpit |

`dev/reset.sh` drops all tables and installs again; run it whenever you want a clean state.

## A first tour

1. **Log in** as `sabrina`. She is an admin. Open *Texte* and look at *Angaben für Impressum und
   Datenschutz*: without these details no round is public, and no round can be activated while the privacy
   policy still contains `[PLACEHOLDERS]` (`dev/demo.sh` filled in both with example values; after
   `dev/reset.sh` alone, do that under *Texte* first).
2. **Open a round** on the dashboard (*Alle Runden*). You see the link for parents, the round code, the
   invitation text and the bookings of each day.
3. **Book as a parent:** click *Öffnen* next to the link, choose a slot, enter a name and tick the
   privacy checkbox. The confirmation page shows the personal change link. With an e-mail address the
   confirmation arrives in Mailpit, including a calendar file.
4. **Switch the language** at the top of the page, and try light and dark mode in the footer.
5. **Create your own round** with *+ Neue Runde*: pick one or more dates, the first and last slot, the
   duration and breaks. The preview shows the resulting slots before you save.

![New round form with live preview of the slots]({{ '/assets/img/admin-round-form.webp' | relative_url }})

## Next steps

- Install TalkSlot on a web server: [Installation]({{ '/guide/installation/' | relative_url }}).
- Deploy automatically from GitHub: [Deployment with GitHub Actions]({{ '/guide/deployment/' | relative_url }}).
- Change code and run the tests: [Development]({{ '/guide/development/' | relative_url }}).
