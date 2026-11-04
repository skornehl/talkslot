---
title: Überblick
description: Was TalkSlot ist, welche Begriffe es verwendet und wo Sie was in dieser Dokumentation finden.
---

TalkSlot ist eine Webanwendung zur Terminvergabe für den Elternsprechtag. Lehrkräfte veröffentlichen
Zeitfenster, Eltern buchen eines davon, ohne ein Konto anzulegen. TalkSlot ist in schlichtem PHP mit
einer MariaDB-Datenbank geschrieben und läuft auf gewöhnlichem Shared Hosting (Webspace).

![Buchungsseite einer Terminrunde]({{ '/assets/img/parent-round.webp' | relative_url }})

## Wer TalkSlot nutzt

| Rolle | Wo | Was sie tun |
|---|---|---|
| **Eltern** | öffentliche Seiten | Terminrunde öffnen, Termin buchen, ihn über den persönlichen Link verschieben oder stornieren |
| **Lehrkräfte** | `/admin` | Terminrunden anlegen, Buchungen ihrer eigenen Runden verwalten |
| **Admins** | `/admin` | ein oder mehrere Konten: laden Lehrkräfte ein, sehen und bearbeiten alle Runden, legen Runden für Lehrkräfte an, pflegen Vorlagen, Impressum und Datenschutzerklärung für die Schule, die Verantwortliche im Sinne der DSGVO ist |
| **Betreiber** | Server, GitHub | installiert, konfiguriert und aktualisiert TalkSlot (oft eine der Personen mit Admin-Konto) |

Der Adminbereich ist deutschsprachig. Elternseiten und E-Mails gibt es auf Deutsch, Englisch, Polnisch,
Russisch, Ukrainisch und Türkisch; weitere Sprachen lassen sich ergänzen.

## Begriffe

Terminrunde (Runde)
: Eine Reihe von Terminen, die eine Lehrkraft anbietet, z. B. „Elternsprechtag Klasse 7b“. Sie hat einen
  lesbaren Link, einen Rundencode, eine Beschreibung, optional einen Ort, Fristen und ein Buchungslimit.

Vorlage
: Zeiten, Pausen und Einstellungen, die Admins einmal vorbereiten (z. B. „Elternsprechtag, 08:00–16:00,
  15 Minuten“); daraus kann jede und jeder eine neue Runde anlegen.

Tag und Zeitfenster
: Eine Terminrunde hat einen oder mehrere Tage. Jeder Tag hat einen Zeitraum, eine Termindauer und
  optionale Pausen; daraus werden die Zeitfenster erzeugt. Einzelne Zeitfenster lassen sich sperren.

Buchung
: Der Termin eines Elternteils: Name, optionale E-Mail-Adresse, Zeitfenster. Wird ab Mitternacht nach dem
  Termin automatisch gelöscht.

Rundencode
: Acht Zeichen wie `K7MP-3QXA`. Eltern können ihn statt des Links auf der Startseite eingeben.
  Mit *Nur mit Rundencode* zeigt der Link allein nichts an.

Persönlicher Änderungslink
: Link, der nach der Buchung angezeigt (und bei angegebener Adresse per E-Mail verschickt)
  wird. Damit können Eltern ihren Termin verschieben oder stornieren. Gespeichert wird nur ein Hash davon.

## Aufbau dieser Dokumentation

- **[Erste Schritte]({{ '/de/guide/getting-started/' | relative_url }})**: TalkSlot lokal mit Demodaten ausführen.
- **TalkSlot betreiben**: [Installation]({{ '/de/guide/installation/' | relative_url }}),
  [Konfiguration]({{ '/de/guide/configuration/' | relative_url }}),
  [Deployment mit GitHub Actions]({{ '/de/guide/deployment/' | relative_url }}) und
  [Betrieb]({{ '/de/guide/operations/' | relative_url }}) (Updates, Cron, Warnmeldungen, Backups).
- **TalkSlot nutzen**: Anleitungen für den [Admin]({{ '/de/guide/admin/' | relative_url }}),
  für [Lehrkräfte]({{ '/de/guide/teachers/' | relative_url }}) und eine Seite, die Sie für
  [Eltern]({{ '/de/guide/parents/' | relative_url }}) verlinken können.
- **Hintergrund**: [Datenschutz]({{ '/de/guide/privacy/' | relative_url }}),
  [Sicherheit]({{ '/de/guide/security/' | relative_url }}),
  [Entwicklung]({{ '/de/guide/development/' | relative_url }}) und die [FAQ]({{ '/de/guide/faq/' | relative_url }}).
