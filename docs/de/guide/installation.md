---
title: Installation
description: TalkSlot von Hand auf einen Webserver bringen. Automatische Deployments beschreiben die folgenden Kapitel.
---

## Voraussetzungen {#requirements}

- **PHP 8.3 oder neuer** (getestet mit 8.3, 8.4 und 8.5) mit `pdo_mysql`, `mbstring` und `iconv`;
  `openssl` für SMTP mit TLS
- **MariaDB 10.6 oder neuer.** Die Migrationen verwenden MariaDB-Syntax; MySQL wird nicht unterstützt. Welche
  [Rechte]({{ '/de/guide/operator-documents/' | relative_url }}#database-privileges) der Datenbankbenutzer braucht.
- **Apache** mit `mod_rewrite`, idealerweise `mod_headers`. Die Regeln stehen in `public/.htaccess`;
  andere Webserver brauchen gleichwertige Regeln (Front Controller, versteckte und interne Dateien
  sperren, Sicherheits-Header).
- **HTTPS** mit gültigem Zertifikat. TalkSlot leitet unverschlüsseltes HTTP um.
- Eine **Absenderadresse**, deren Domain SPF und DKIM hat, sonst landen E-Mails im Spam.

TalkSlot prüft das selbst. Ist die PHP-Version zu alt oder fehlt eine Erweiterung, sagt das jede Seite auf
Deutsch und Englisch, statt eine leere Fehlerseite zu zeigen (ab PHP 7.1; auf der Kommandozeile, etwa im
Cronjob, auf Englisch). Hoster nutzen auf der Kommandozeile oft ein älteres `php` als auf dem Webserver:
Dann im Cronjob den vollen Pfad des neueren angeben (etwa `/usr/bin/php8.3`). Der Installer lehnt MySQL
und MariaDB vor 10.6 ab, ebenso ein `app/var/`, in das PHP nicht schreiben darf; bei einer bereits
installierten Seite weist die Übersicht Admins darauf hin. Siehe auch
[Prüfung der Konfiguration]({{ '/de/guide/configuration/' | relative_url }}#checking).

## 1. Dateien hochladen

Nur `public/` darf aus dem Web erreichbar sein. `app/`, `bin/` und `config/` müssen außerhalb des
Document Root liegen.

**Wenn Sie das Document Root wählen können** (empfohlen): Laden Sie das ganze Projekt hoch, z. B. nach
`/var/www/talkslot`, und lassen Sie das Document Root Ihrer (Sub-)Domain auf `/var/www/talkslot/public`
zeigen.

**Wenn nicht** (typisch bei Shared Hosting mit festem Webverzeichnis): Laden Sie den *Inhalt* von
`public/` in das Webverzeichnis und alles andere in ein Nachbarverzeichnis, z. B. `talkslot-app/`.
Legen Sie dann `app_root.php` im Webverzeichnis an, damit `index.php` den Code findet:

```php
<?php
return dirname(__DIR__) . '/talkslot-app';
```

`app/var/` muss für PHP beschreibbar sein: Dort liegen Sessions, die Sperrdatei der Installation, das
Fehlerlog und der Zähler für Warnmeldungen.

## 2. Konfigurieren

Kopieren Sie `config/config.sample.php` nach `config/config.php` und füllen Sie mindestens Folgendes aus:

| Schlüssel | Wert |
|---|---|
| `base_url` | Adresse der Seite ohne abschließenden Schrägstrich, z. B. `https://termine.example.org` |
| `secret` | zufällig, mindestens 32 Zeichen: `php -r 'echo bin2hex(random_bytes(32));'` |
| `install_key` | zufällig, mindestens 16 Zeichen, nur für den Web-Installer nötig |
| `db` | Host, Name, Benutzer, Passwort; `prefix`, wenn die Datenbank geteilt wird |
| `mail` | `from_address` und der Transport (`mail` oder `smtp`) |
| `hosting` | Ihr Hoster für die Datenschutzerklärung, siehe [Datenschutz]({{ '/de/guide/privacy/' | relative_url }}) |

Alle Optionen erklärt [Konfiguration]({{ '/de/guide/configuration/' | relative_url }}). Machen Sie
`config.php` nur für den PHP-Benutzer lesbar (`chmod 600` oder `640`): Die Datei enthält die Passwörter für
Datenbank und Mailversand und den geheimen Schlüssel. Solange alle Benutzer des Servers sie lesen können,
warnt die Übersicht die Admins.

## 3. Installieren

Öffnen Sie `https://your-site/install.php`, geben Sie den Installationsschlüssel ein und legen Sie das
Admin-Konto an (Benutzername, Passwort mit 12 Zeichen bis 72 Byte, das nicht leicht zu erraten ist,
E-Mail-Adresse; es gelten dieselben [Passwortregeln]({{ '/de/guide/security/' | relative_url }}) wie für
alle Konten). Wählen Sie die **Betriebsart**: *von der Schule betrieben* (öffentliche Stelle) oder *privat
betrieben* (Privatperson, Verein oder freier Träger, dessen Angebot Lehrkräfte nutzen). Danach richten sich
die Vorlagen für Impressum und Datenschutzerklärung, siehe
[Datenschutz und Recht]({{ '/de/guide/privacy/' | relative_url }}#von-der-schule-oder-privat-betrieben); ändern
können Sie sie später unter *Texte*. Der Installer legt die Tabellen an, kopiert die Vorlagen dieser
Betriebsart für Impressum und Datenschutzerklärung in die Datenbank, sperrt sich selbst und versucht, sich
zu löschen. Löschen Sie `public/install.php` selbst, falls die Datei noch
vorhanden ist.

Mit Shell-Zugang können Sie stattdessen über die Kommandozeile installieren:

```bash
php bin/install.php <username> [email]   # fragt nach dem Passwort und der Betriebsart (school oder private)
```

In einem Skript übergeben Sie die drei Antworten über die Standardeingabe, je eine Zeile: Passwort,
Passwort-Wiederholung, `school` oder `private`.

## 4. Cronjob einrichten

TalkSlot löscht vergangene Buchungen, deaktiviert und löscht abgeschlossene Terminrunden und verschickt
Zusammenfassungen an Lehrkräfte, Erinnerungen an Eltern und E-Mails, die nicht sofort hinausgehen konnten,
in einem Cronjob. Lassen Sie ihn stündlich laufen:

```cron
5 * * * * php /path/to/talkslot/bin/cron.php
```

Ohne Shell-Zugang nutzen Sie den **Web-Cron**: `POST /cron` mit dem Header `X-Cron-Token`. Das Token ist
der hex-kodierte HMAC-SHA256 des Wortes `cron` mit Ihrem `secret`:

```bash
TOKEN=$(printf cron | openssl dgst -sha256 -hmac "$SECRET" | sed 's/.*= //')
curl -fsS -X POST -H "X-Cron-Token: $TOKEN" https://your-site/cron
```

Der mitgelieferte GitHub-Workflow `cron.yml` macht genau das jede Stunde. Als Rückfallebene läuft das
Aufräumen außerdem bei Seitenaufrufen, höchstens alle 15 Minuten; E-Mails gehen dort nur mit PHP-FPM oder
LiteSpeed hinaus, nachdem die Seite ausgeliefert ist. Ohne Cronjob werden Erinnerungen und Zusammenfassungen
deshalb womöglich gar nicht verschickt (siehe
[Mail-Warteschlange]({{ '/de/guide/operations/' | relative_url }}#mail-warteschlange)).

## 5. Rechtliche Angaben ausfüllen

Melden Sie sich als Admin an und öffnen Sie *Texte*. Füllen Sie *Angaben für Impressum und
Datenschutz* aus (Name der Schule oder Person, Anschrift, E-Mail) und prüfen Sie dort die Betriebsart. Bis
dahin ist **keine Terminrunde öffentlich**. Prüfen Sie danach *Texte*: Impressum und Datenschutzerklärung
sind aus den Vorlagen der Betriebsart installiert (die Vorlagen der anderen Betriebsart lassen sich im
Formular des Textes laden). Sie enthalten Platzhalter in eckigen Klammern wie
`[ZUSTÄNDIGE LANDESDATENSCHUTZBEHÖRDE MIT ADRESSE]` (Datenschutzbeauftragte, bei einer Schule die
Vorschriften Ihres Landes, Speicherdauer der Server-Logs bei Ihrem Hoster, Aufsichtsbehörde), die Sie
ersetzen müssen; bis dahin
**lässt sich keine Runde aktivieren**. Siehe [Datenschutz und Recht]({{ '/de/guide/privacy/' | relative_url }}).

## Checkliste für den Livegang

- [ ] HTTPS aktiv, `http://` leitet auf `https://` um
- [ ] `install.php` liefert 404; `config/config.php` ist für andere nicht lesbar
- [ ] `debug` ist `false`, `alert_email` ist gesetzt
- [ ] Cronjob oder Web-Cron läuft (`bin/cron.php -v` gibt die Anzahlen aus); bleibt er stehen, sehen Admins
      eine Warnung in der Übersicht, und Sie erhalten eine Warnmeldung
      ([Überwachung des Cronjobs]({{ '/de/guide/operations/' | relative_url }}#cron-monitoring))
- [ ] Testbuchung mit E-Mail-Adresse: Die E-Mail kommt an und wird nicht als Spam markiert
- [ ] Admin-Angaben, Impressum und Datenschutzerklärung vollständig in allen angebotenen Sprachen (keine
      Platzhalter in eckigen Klammern mehr; das Dashboard zeigt keinen Hinweis)
- [ ] Betriebsart unter *Texte* stimmt damit überein, wer TalkSlot tatsächlich betreibt (Schule oder privat)
- [ ] Auftragsverarbeitungsvertrag (AVV) mit Ihrem Hoster; ist die Schule Betreiberin, betreibt aber
      jemand anderes den Server, eine Vereinbarung nach Art. 28 DSGVO zwischen der Schule und dieser Person
- [ ] Einladung einer Lehrkraft getestet; eine Testrunde auf dem Handy gebucht, verschoben und
      storniert, danach gelöscht
