---
title: Betrieb
description: Updates, Migrationen, Fehlermeldungen, Logs, Backups und Fehlersuche.
---

## Updates

**Mit GitHub Actions** spielt jeder Push auf `main` – oder eine neue Versionsnummer in Ihrem Repository nur
mit Konfiguration – das Update atomar aus; siehe [Deployment]({{ '/de/guide/deployment/' | relative_url }}).

**Von Hand:** Laden Sie die neuen Verzeichnisse `app/`, `bin/` und `public/` hoch (`config/config.php` und
`app/var/` bleiben erhalten). Laden Sie zuerst `app/` und `bin/` hoch und `public/` zuletzt. Während des
Uploads sind alte und neue Dateien kurz gemischt; wählen Sie eine ruhige Zeit oder steigen Sie auf das
atomare Deployment um.

Lesen Sie vor dem Update die [Release Notes](https://github.com/skornehl/talkslot/releases).

## Datenbank-Migrationen

Von Hand ist nichts auszuführen. Bei der ersten Anfrage nach einem Update hebt TalkSlot das Schema Schritt
für Schritt an (`app/src/Migrations.php`). Eine Datenbanksperre sorgt dafür, dass nur eine Anfrage migriert;
die anderen warten kurz. Neuinstallationen erhalten das neueste Schema direkt aus `app/schema.sql`.

Migrationen fügen nur Spalten (mit Standardwerten) und Tabellen hinzu, sodass ein Rollback auf eine ältere
Version weiter funktioniert. Texte für Impressum und Datenschutzerklärung, die nie bearbeitet wurden, werden
automatisch durch neuere Vorlagen ersetzt; bearbeitete Texte bleiben unverändert, und Admins sehen unter
*Texte* einen Hinweis, wenn etwas zu tun ist.

## Fehlermeldungen

Nicht behandelte Exceptions, fatale PHP-Fehler, PHP-Warnungen, fehlgeschlagene Migrationen oder
Aufräumläufe, eine nicht erreichbare Datenbank und fehlgeschlagener E-Mail-Versand werden abgefangen.
Besucher sehen nur eine neutrale Fehlerseite (503 mit `Retry-After`, wenn die Datenbank ausgefallen ist).
Der Betreiber erhält eine E-Mail:

- **Empfänger:** `alert_email`, sonst die Adresse des ältesten aktiven Admins. Setzen Sie `alert_email`:
  Solange die Datenbank ausgefallen ist, lässt sich keine Adresse aus der Datenbank lesen.
- **Drosselung:** Derselbe Fehler (Art und Stelle) höchstens einmal pro Stunde, höchstens 30 E-Mails pro
  Tag. Wichtige Meldungen (Datenbank ausgefallen, fatale Fehler) werden auch nach Erreichen des Tageslimits
  verschickt. Die nächste E-Mail nennt, wie oft der Fehler zwischendurch aufgetreten ist.
- **Inhalt:** Zeit, Version, Methode und Pfad (Tokens und Rundenlinks durch „…“ ersetzt, kein Query-String),
  die bereinigte Fehlermeldung, Stelle und Stacktrace ohne Argumente. **Keine personenbezogenen Daten.**

Grenzen: Ist der E-Mail-Versand selbst defekt, kommt keine Meldung an. Ist die ganze Seite ausgefallen
(Hoster, PHP), kann sie sich nicht selbst melden. Dann schlägt der stündliche Cron-Workflow fehl, und
GitHub benachrichtigt Sie (*GitHub → Settings → Notifications → Actions*).

## Logs

TalkSlot schreibt ein eigenes Log nach `app/var/php-error.log`; das Logging von PHP selbst ist abgeschaltet,
weil dessen Meldungen Eingaben enthalten können. Werte in Anführungszeichen und Klammern sowie
E-Mail-Adressen werden vor dem Schreiben entfernt. Das Log wird bei 5 MB rotiert (eine Vorgängerdatei
`php-error.log.1`).

## Backups

Sichern Sie die **Datenbank** (z. B. `mysqldump --single-transaction`) und `config/config.php`. `app/var/`
enthält nur Sessions, den Zähler für Fehlermeldungen und Logs und braucht kein Backup.

Bedenken Sie, dass Buchungen personenbezogene Daten mit kurzer Lebensdauer sind: Spielen Sie ein Backup nur
so weit wie nötig zurück und lassen Sie anschließend die Bereinigung laufen.

## Löschfristen

| Was | Wann |
|---|---|
| Buchungen (Name, E-Mail) eines Tages | ab 0:00 Uhr des Folgetages |
| Terminrunden | nach ihrem letzten Tag deaktiviert, standardmäßig 24 h später gelöscht (pro Runde einstellbar, leer = nie) |
| Rate-Limit-Daten | spätestens nach 24 Stunden |
| Inaktive Konten von Lehrkräften ohne Runden | 15 Monate nach dem letzten Login |
| *Protokoll* der Admin-Änderungen | nach 90 Tagen (`audit_log_days`) |

Die Bereinigung läuft im Cronjob und ersatzweise bei Seitenaufrufen, höchstens alle 15 Minuten. Dieselben
Läufe verschicken die Zusammenfassungen an Lehrkräfte und die Erinnerungen an Eltern.

## Fehlersuche

**503 auf jeder Seite.** Die Datenbank ist nicht erreichbar, oder (beim atomaren Deployment, Klartext
„Temporarily unavailable.“) es liegt keine vollständige Version auf dem Server. Prüfen Sie die
Fehler-E-Mail und `app/var/php-error.log`.

**Runden sind für Eltern nicht erreichbar.** Die *Angaben für Impressum und Datenschutz* unter *Texte*
fehlen, die Runde oder das Konto der Lehrkraft ist inaktiv, oder die Runde ist
beendet.

**Eltern erhalten „Zu viele Fehlversuche“ oder „Zu viele Anfragen“.** Viele Eltern teilen sich womöglich eine IP-Adresse (Mobilfunk,
Schul-WLAN). Erhöhen Sie die [Rate-Limits]({{ '/de/guide/configuration/' | relative_url }}#rate-limits),
wenn das in einer stark ausgelasteten Buchungsphase passiert.

**E-Mails kommen nicht an.** Prüfen Sie die SPF- und DKIM-Einträge der Absenderdomain, versuchen Sie `smtp`
statt `mail` und achten Sie auf die Meldung „Mailversand fehlgeschlagen“.

**Die Datenschutzerklärung zeigt `[HOSTER MIT ANSCHRIFT]`.** Setzen Sie `hosting` (bzw.
`HOSTING_DE`/`HOSTING_EN`); siehe [Datenschutz und Recht]({{ '/de/guide/privacy/' | relative_url }}).
