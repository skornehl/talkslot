---
title: Erste Schritte
description: TalkSlot mit Docker auf dem eigenen Rechner starten, mit Demodaten füllen und durchklicken.
---

## Voraussetzungen

- [Docker](https://docs.docker.com/get-docker/) mit Compose
- Git

Das Docker-Setup enthält PHP 8.5 mit Apache, MariaDB 11.8 und [Mailpit](https://mailpit.axllent.org/),
das jede E-Mail abfängt, die TalkSlot verschickt.

## Lokale Instanz starten

```bash
git clone https://github.com/skornehl/talkslot.git
cd talkslot
cp dev/config.dev.php config/config.php
docker compose -f dev/compose.yml up -d --build
dev/reset.sh       # frische Datenbank und Admin-Konto
dev/demo.sh        # optional: drei Demo-Runden mit Buchungen
```

| | URL | |
|---|---|---|
| Elternseiten | <http://localhost:8080> | |
| Adminbereich | <http://localhost:8080/admin> | Benutzer `sabrina`, Passwort `Sehr-Sicheres-Dev-Pw1` |
| E-Mails | <http://localhost:8025> | Mailpit |

`dev/reset.sh` löscht alle Tabellen und installiert neu; führen Sie es aus, wann immer Sie einen sauberen
Stand möchten.

## Ein erster Rundgang

1. **Melden Sie sich** als `sabrina` an. Sie ist Admin. Öffnen Sie *Texte* und sehen Sie sich
   *Angaben für Impressum und Datenschutz* an: Ohne diese Angaben ist keine Terminrunde öffentlich
   (`dev/demo.sh` hat sie bereits ausgefüllt).
2. **Öffnen Sie eine Terminrunde** auf dem Dashboard (*Alle Runden*). Sie sehen den Link für Eltern, den
   Rundencode, den Einladungstext und die Buchungen jedes Tages.
3. **Buchen Sie als Elternteil:** Klicken Sie neben dem Link auf *Öffnen*, wählen Sie einen Termin, geben
   Sie einen Namen ein und setzen Sie den Haken bei der Datenschutz-Checkbox. Die Bestätigungsseite zeigt
   den persönlichen Änderungslink. Mit E-Mail-Adresse kommt die Bestätigung in Mailpit an, inklusive
   Kalenderdatei.
4. **Wechseln Sie die Sprache** über die Flaggen in der Fußzeile und probieren Sie den hellen und dunklen
   Modus aus.
5. **Legen Sie eine eigene Terminrunde an** mit *+ Neue Runde*: Wählen Sie ein oder mehrere Daten, den
   ersten und letzten Termin, die Dauer und Pausen. Die Vorschau zeigt die entstehenden Zeitfenster,
   bevor Sie speichern.

![Formular für eine neue Terminrunde mit Live-Vorschau der Zeitfenster]({{ '/assets/img/admin-round-form.webp' | relative_url }})

## Wie es weitergeht

- TalkSlot auf einem Webserver installieren: [Installation]({{ '/de/guide/installation/' | relative_url }}).
- Automatisch aus GitHub deployen: [Deployment mit GitHub Actions]({{ '/de/guide/deployment/' | relative_url }}).
- Code ändern und die Tests ausführen: [Entwicklung]({{ '/de/guide/development/' | relative_url }}).
