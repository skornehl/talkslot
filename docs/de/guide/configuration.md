---
title: Konfiguration
description: Alle Optionen von config/config.php und die passenden Variablen für Deployments mit GitHub Actions.
---

TalkSlot liest `config/config.php`, eine PHP-Datei, die ein Array zurückgibt. Ausgangspunkt ist
[`config/config.sample.php`](https://github.com/skornehl/talkslot/blob/main/config/config.sample.php).
Beim Deployment mit GitHub Actions wird die Datei aus Repository-Variablen und -Secrets erzeugt
(rechte Spalte); siehe [Deployment]({{ '/de/guide/deployment/' | relative_url }}).

## Prüfung der Konfiguration {#checking}

TalkSlot kennt jede Option (`app/src/ConfigSchema.php`) und prüft `config.php` bei jedem Aufruf:

- **Fehler, mit denen es nicht laufen kann,** halten die Seite an; sie zeigt dann, was nicht stimmt, auf
  Deutsch und Englisch (auf der Kommandozeile auf Englisch): `secret` fehlt oder ist kürzer als 32 Zeichen,
  eine `timezone`, die PHP nicht kennt (etwa `Europe/Berllin`; früher galt dann ohne Hinweis UTC, und alle
  Fristen, die Erinnerungen und die Bereinigung verschoben sich um ein bis zwei Stunden), eine Sprache unter
  `languages` ohne ihre Datei in `app/lang/` und ein `db.prefix` mit anderen Zeichen als Buchstaben,
  Ziffern und `_`.
- **Optionen, die so nicht wirken,** listet die Übersicht für Admins auf, ohne ihre Werte: unbekannte
  Schlüssel (etwa ein Tippfehler wie `sumary_evening_hour`, mit der vermutlich gemeinten Option), Werte vom
  falschen Typ oder außerhalb des Bereichs (`'false'` in Anführungszeichen ist nicht `false`,
  `summary_evening_hour` 24, ein Rate-Limit `[0, 900]`), eine `default_language`, die nicht angeboten wird,
  eine fehlende `mail.from_address` und den Versandweg `mail` bei einem Hoster, der PHPs `mail()`
  abgeschaltet hat.

Dazu gehören die [Voraussetzungen]({{ '/de/guide/installation/' | relative_url }}#requirements) des
Servers: PHP-Version und Erweiterungen bei jedem Aufruf, der Datenbankserver im Installer, in der Übersicht und
vor einer Migration.

## Allgemein

| Option | Standard | Bedeutung | Deployment |
|---|---|---|---|
| `app_name` | `TalkSlot` | Name in der Kopfzeile, in Seitentiteln, E-Mails und im Betreff von Warnmeldungen | `APP_NAME` |
| `base_url` | – | öffentliche Adresse ohne abschließenden Schrägstrich; ein Pfad ist erlaubt (`https://example.org/termine`) | `BASE_URL` |
| `force_https` | `true` | unverschlüsseltes HTTP umleiten | – |
| `debug` | `false` | Fehlerdetails anzeigen; nie im Produktivbetrieb | – |
| `timezone` | `Europe/Berlin` | | – |
| `secret` | – | mindestens 32 zufällige Zeichen; signiert Tokens und hasht IP-Adressen | `APP_SECRET` |
| `install_key` | – | mindestens 16 Zeichen, nur für `install.php` | `INSTALL_KEY` (mit `ALLOW_INSTALL=1`) |

Eine Änderung von `secret` betrifft nur Kurzlebiges: Formulare, die in diesem Moment geöffnet sind,
Zugänge, die in den letzten Stunden per Rundencode freigeschaltet wurden, das Web-Cron-Token und den
Schlüssel für den [Health-Check]({{ '/de/guide/deployment/' | relative_url }}#health-check) des Deployments
(die mitgelieferten Workflows leiten beide neu ab).
Buchungen, Änderungslinks, Einladungs- und Passwortlinks sind Zufallstokens und funktionieren weiter.
Abgemeldet wird dadurch niemand; dafür gibt es *Benutzer → Alle Sitzungen beenden*.

## Datenbank

| Option | Standard | Deployment |
|---|---|---|
| `db.host` / `db.port` | `localhost` / `3306` | `DB_HOST` / `DB_PORT` |
| `db.name` | – | `DB_NAME` (standardmäßig der Benutzername) |
| `db.user` / `db.pass` | – | `DB_USER` / `DB_PASS` |
| `db.prefix` | `tp_` | `DB_PREFIX`; Buchstaben, Ziffern und `_`, leer für kein Präfix |

## Sprachen {#languages}

| Option | Standard | Bedeutung | Deployment |
|---|---|---|---|
| `languages` | `de, en, pl, ru, uk, tr` | Sprachen für Eltern, in der Reihenfolge der Sprachwahl oben auf der Seite | `LANGUAGES`, z. B. `de,en,tr` |
| `default_language` | `de` | wird angezeigt, bis Besucher eine Sprache wählen; muss in `languages` enthalten sein | `DEFAULT_LANGUAGE` |

Der Build bricht ab, wenn eine Sprache keine Datei in `app/lang/` hat oder die Standardsprache nicht
angeboten wird.

Der Adminbereich ist deutsch- oder englischsprachig, gewählt pro Konto unter *Mein Profil*.
`admin_language` (`de` oder `en`, beim Deployment die Variable `ADMIN_LANGUAGE`) ist der Standard für Konten
ohne eigene Wahl und für die Anmeldeseite, wenn der Browser keine der beiden Sprachen nennt. Einträge im
Protokoll werden in dieser Sprache geschrieben.

**Sprache hinzufügen:** Kopieren Sie `app/lang/en.php` z. B. nach `app/lang/it.php` und übersetzen Sie
die Datei (`lang.name` ist der Name der Sprache in ihr selbst, so steht er in der Sprachwahl), legen Sie
eine Flagge `public/assets/flags/it.svg` an (für das Übersetzungsformular im Adminbereich) und tragen Sie
den Code in `languages`
ein. Optional ergänzen Sie die Vorlagen der Rechtstexte
`app/templates/pages/{imprint,imprint-private,privacy,privacy-private}.it.md`, und zwar alle vier (je Seite
und Betriebsart, damit sie bei Betrieb durch die Schule und bei privatem Betrieb vorhanden sind), mit ihren
Hashes in `Migrations::CURRENT_PAGE_HASHES` (der Unit-Test nennt fehlende); ohne diese Dateien zeigen die
Rechtsseiten die englische Fassung. Bitte lassen Sie Übersetzungen von Muttersprachlern
prüfen.

## E-Mail

| Option | Standard | Bedeutung | Deployment |
|---|---|---|---|
| `mail.transport` | `mail` | `mail` (PHP `mail()`), `smtp` (mitgeliefertes PHPMailer) oder `none` | `MAIL_TRANSPORT` |
| `mail.from_address` | – | Absenderadresse (Domain mit SPF/DKIM) | `MAIL_FROM` |
| `mail.from_name` | `TalkSlot` | Absendername; folgt beim Deployment `APP_NAME` | `MAIL_FROM_NAME` |
| `mail.reply_to` | – | optional; E-Mails an Eltern nutzen die Lehrkraft als Antwortadresse | `MAIL_REPLY_TO` |
| `mail.smtp.host` / `port` | – / `587` | | `SMTP_HOST` / `SMTP_PORT` |
| `mail.smtp.encryption` | `tls` | `tls` (STARTTLS), `ssl` oder `none` | `SMTP_ENCRYPTION` |
| `mail.smtp.user` / `pass` | – | | `SMTP_USER` / `SMTP_PASS` (Secrets) |
| `alert_email` | – | Empfänger von Fehlermeldungen; leer = Adresse des Admins (nicht verfügbar, solange die Datenbank ausgefallen ist) | `ALERT_EMAIL` |

## Rechtsseiten

| Option | Bedeutung | Deployment |
|---|---|---|
| `hosting.de` / `hosting.en` | Ihr Hoster, ersetzt `[[HOSTER]]` in der Datenschutzerklärung | `HOSTING_DE` / `HOSTING_EN` |

Beispiel für `hosting.de`: *Beispiel Hosting GmbH, Musterweg 1, 12345 Musterstadt, als
Auftragsverarbeiter nach Art. 28 DSGVO. Die Server stehen in Deutschland.*

## Sessions, Links und Aufräumen

| Option | Standard | Bedeutung |
|---|---|---|
| `session_idle_minutes` | `30` | Adminbereich: Abmeldung nach Inaktivität |
| `session_absolute_hours` | `8` | Adminbereich: maximale Sitzungsdauer |
| `access_link_hours` | `48` | Gültigkeit von Einladungs- und Passwortlinks |
| `cleanup_on_request` | `true` | Aufräumen auch bei Seitenaufrufen ausführen … |
| `cleanup_interval_minutes` | `15` | … höchstens so oft (nur Datenbankschritte: Zusammenfassungen und Erinnerungen werden dort in die Warteschlange gestellt, nicht verschickt) |
| `summary_evening_hour` | `18` | ab dieser Stunde erhalten Lehrkräfte die Zusammenfassung für den nächsten Tag und Eltern die Erinnerung |
| `audit_log_days` | `90` | Einträge im *Protokoll* der Admin-Änderungen werden nach so vielen Tagen gelöscht |
| `cron_alert_hours` | `12` | ohne vollständigen Cron-Lauf seit so vielen Stunden sehen Admins eine Warnung in der Übersicht, und der Betreiber erhält eine Warnmeldung per E-Mail ([Überwachung des Cronjobs]({{ '/de/guide/operations/' | relative_url }}#cron-monitoring); `0` = aus); Deploy-Variable `CRON_ALERT_HOURS` |

## Mail-Warteschlange

Erinnerungen, Zusammenfassungen, E-Mails an Eltern nach einer Änderung durch eine Lehrkraft und
Bestätigungen, die nicht sofort verschickt werden konnten, warten in einer Mail-Warteschlange (Outbox), die
der Cronjob abarbeitet; siehe
[Betrieb]({{ '/de/guide/operations/' | relative_url }}#mail-warteschlange).

| Option | Standard | Bedeutung |
|---|---|---|
| `mail_queue.batch` | `100` | höchstens so viele E-Mails pro Cron-Lauf |
| `mail_queue.seconds` | `20` | Zeitbudget pro Cron-Lauf (eine begonnene E-Mail wird noch fertig verschickt) |
| `mail_queue.hourly_limit` | `0` | höchstens so viele Erinnerungen, Zusammenfassungen und E-Mails an viele Eltern nach einer Änderung durch eine Lehrkraft pro Stunde, z. B. das Mail-Kontingent Ihres Hosters (`0` = keine Grenze); einzelne Bestätigungen, Änderungsmitteilungen und Absagen gehen vor und werden nie zurückgehalten |
| `mail_queue.after_response` | `true` | mit PHP-FPM oder LiteSpeed verschickt auch ein Seitenaufruf bis zu 10 fällige E-Mails, nachdem seine Antwort beim Browser ist |

## Rate-Limits {#rate-limits}

`rate_limits` enthält `[Maximum, Zeitfenster in Sekunden]` pro gekürzter IP-Adresse (IPv4 /24,
IPv6 /48), soweit nicht anders angegeben. Die Werte sind bewusst großzügig: Viele Eltern teilen sich
womöglich eine Adresse (Mobilfunknetze, Schul-WLAN).

| Schlüssel | Standard | Zählt |
|---|---|---|
| `code` | 30 pro 15 Min. | falsche Rundencodes auf der Startseite |
| `attempt` | 150 pro Stunde | alle Buchungsversuche, auch mit Eingabefehlern |
| `booking` | 30 pro Stunde | erfolgreiche Buchungen |
| `manage` | 300 pro Stunde | Aufrufe von Änderungslinks |
| `move` | 10 pro Stunde | Umbuchungen durch Eltern (jede verschickt eine E-Mail), pro Buchung statt pro IP-Adresse |
| `confirm` | 2 pro 24 Stunden | Bestätigungs-E-Mails, die Eltern sich auf der Seite ihres Termins schicken, pro Buchung statt pro IP-Adresse (das Zeitfenster kann nicht länger als 24 Stunden sein) |
| `login` | 5 pro 15 Min. | fehlgeschlagene Admin-Anmeldungen |
| `login_user` | 20 pro Stunde | fehlgeschlagene Anmeldungen **pro Konto** von Geräten, die sich dort noch nie angemeldet haben |
| `login_device` | 20 pro Stunde | fehlgeschlagene Anmeldungen **pro bekanntem Gerät** (statt `login_user`) |
| `login_notice` | 1 pro Tag | E-Mails an ein Konto, dessen Limit `login_user` erreicht wurde |
| `reauth` | 5 pro 15 Min. | falsche Passwörter beim Bestätigen einer Änderung von Admin-Rechten oder E-Mail-Adressen, beim Beenden aller Sitzungen und beim Ändern des eigenen Passworts (ein Zähler pro Anmeldung, nicht pro IP-Adresse) |
| `invite` | 20 pro Stunde | ungültige Einladungs- und Passwortlinks |
| `forgot` | 5 pro Stunde | Anfragen unter *Passwort vergessen* |
| `teacher_mail` | 30 pro Stunde | E-Mails an Eltern, die eine Lehrkraft aus einem Eintrag schickt (Bestätigung, Änderungsmitteilung), pro Konto statt pro IP-Adresse |
| `teacher_bulk` | 200 pro Stunde | Änderungsmitteilungen an Eltern, nachdem eine Lehrkraft einen Tag oder eine Runde geändert hat, pro Konto; Eltern über der Grenze nennt die Meldung stattdessen der Lehrkraft. Zusammen mit `teacher_mail` verhindert das, dass ein Konto E-Mails in großer Zahl an selbst gewählte Adressen schickt |

`csrf_min_age_seconds` (Standard 2) ist die Mindestzeit zwischen dem Laden und dem Absenden eines
öffentlichen Formulars, eine einfache Bremse für Bots. `csrf_max_age_seconds` (Standard 14400, 4 Stunden)
ist die Zeit, die ein öffentliches Formular und der per Rundencode freigeschaltete Zugang gültig bleiben.
