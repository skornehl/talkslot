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

Schlägt eine Migration fehl, kommt eine Fehlermeldung per E-Mail; die Seiten werden weiter ausgeliefert.
Beim atomaren Deployment führt der [Health-Check]({{ '/de/guide/deployment/' | relative_url }}#health-check)
nach dem Umschalten zuerst die Migrationen aus und schaltet auf die vorherige Version zurück, wenn eine
fehlschlägt, die Datenbank nicht erreichbar ist oder sie nicht MariaDB 10.6 oder neuer ist.

Unter MySQL oder einer MariaDB vor 10.6 beginnt eine ausstehende Migration gar nicht erst (ihre
MariaDB-Syntax würde mittendrin scheitern): Die Fehler-E-Mail und die Übersicht der Admins nennen den
Datenbankserver. Auch der Cronjob meldet ihn: `db_server` ist `ok` oder `unsupported` in der Ausgabe von
`php bin/cron.php -v` und des Web-Crons.

Migrationen fügen nur Spalten (mit Standardwerten) und Tabellen hinzu, sodass ein Rollback auf eine ältere
Version weiter funktioniert. Texte für Impressum und Datenschutzerklärung, die nie bearbeitet wurden, werden
automatisch durch neuere Vorlagen der Betriebsart (von der Schule oder privat betrieben) ersetzt; bearbeitete
Texte bleiben unverändert, und Admins sehen unter *Texte* einen Hinweis, wenn etwas zu tun ist. Bringt eine
neue Vorlage neue Platzhalter in eckigen Klammern mit, bleiben bereits aktive Runden erreichbar; das
Dashboard nennt die Platzhalter, und die nächste Runde lässt sich erst aktivieren, wenn sie ausgefüllt sind.

**Update auf die Version mit Betriebsart:** Eine Installation, deren Texte alle noch unverändert den
Vorlagen bis v1.6 entsprechen, wird *privat betrieben* (das beschrieben diese Vorlagen), und das Dashboard
weist die Admins darauf hin. Jede andere Installation hat danach keine Betriebsart: Admins sehen im
Dashboard „Bitte Betriebsart wählen“, und **bis ein Admin sie unter *Texte* gewählt hat, lässt sich keine
Runde aktivieren**. Bereits aktive Runden bleiben erreichbar. Planen Sie dafür direkt nach dem Update eine
Minute ein, siehe [Datenschutz und Recht]({{ '/de/guide/privacy/' | relative_url }}#von-der-schule-oder-privat-betrieben).

## Fehlermeldungen

Nicht behandelte Exceptions, fatale PHP-Fehler, PHP-Warnungen, fehlgeschlagene Migrationen oder
Aufräumläufe, eine nicht erreichbare Datenbank und fehlgeschlagener E-Mail-Versand werden abgefangen.
Besucher sehen nur eine neutrale Fehlerseite (503 mit `Retry-After`, wenn die Datenbank ausgefallen ist)
in ihrer Sprache, mit einem Link zurück zur Startseite und, außer bei ausgefallener Datenbank, zum
Impressum, das den Betreiber nennt; was die Seite bis dahin schon ausgegeben hatte, wird verworfen.
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
GitHub benachrichtigt Sie (*GitHub → Settings → Notifications → Actions*). Einen Cronjob, der stehen
bleibt, ohne fehlzuschlagen, meldet die Seite selbst, siehe [Überwachung des Cronjobs](#cron-monitoring).

## Überwachung des Cronjobs {#cron-monitoring}

Der Cronjob löscht vergangene Buchungen und verschickt die Zusammenfassungen, Erinnerungen und die E-Mails
aus der Warteschlange. Bleibt er stehen, schlägt nichts sichtbar fehl: Die Bereinigung läuft zwar weiter bei
Seitenaufrufen, aber später und nur, wenn jemand die Seite aufruft, und Erinnerungen und Zusammenfassungen
gehen womöglich gar nicht hinaus (siehe [Mail-Warteschlange](#mail-warteschlange)). TalkSlot speichert deshalb jeden vollständigen Lauf von `bin/cron.php`
oder dem Web-Cron (`last_cron` in der Tabelle `settings`, getrennt von `last_cleanup`, das auch
Seitenaufrufe setzen) und prüft ihn bei Seitenaufrufen:

- **Seit 3 Stunden kein vollständiger Lauf** (`cron_alert_hours`): Beim stündlichen Takt sind das zwei
  ausgefallene Läufe, ein einzelner Lauf, den GitHub verzögert oder auslässt, löst also noch nichts aus.
  Admins sehen in der Übersicht eine Warnung mit der Zeit des letzten Laufs und einem Link auf diesen
  Abschnitt, und der Betreiber erhält die Warnmeldung *Cronjob läuft nicht* (Empfänger wie bei den
  Fehlermeldungen oben). Sie kommt einmal pro Ausfall und alle 24 Stunden erneut, solange er anhält, mit der
  Zeit des letzten Laufs und den Punkten, die zu prüfen sind; ohne personenbezogene Daten. Der nächste
  vollständige Lauf beendet die Warnung.
- **Noch gar kein vollständiger Lauf verzeichnet** (neue Installation, Update, während der Cronjob schon
  ausgefallen war, oder ein Cronjob, der jedes Mal scheitert): 3 Stunden nach Beginn der Überwachung bittet
  die Übersicht die Admins, den Cronjob einzurichten oder mit `php bin/cron.php -v` nachzusehen, woran er
  scheitert.
  Eine E-Mail gibt es dafür nicht, damit Installationen, die bewusst auf Seitenaufrufe setzen (mit PHP-FPM
  oder LiteSpeed, siehe [Mail-Warteschlange](#mail-warteschlange)), nicht jeden Tag daran erinnert werden.
- **`cron_alert_hours = 0`** schaltet beides ab.

Geprüft wird bei Seitenaufrufen: Eine Seite, die niemand aufruft, kann nichts melden, aber jeder Aufruf,
auch durch Eltern, verschickt die Warnmeldung, wenn sie fällig ist. Lehrkräfte sehen die Warnung nicht;
beheben lassen können sie nur Admins.

Fällt ein reparierter Cronjob später erneut aus, ist das ein neuer Ausfall: Er wird gemeldet, sobald die
3 Stunden um sind, auch wenn die letzte Meldung noch keinen Tag zurückliegt.

**Fehlalarm nach einem Rollback oder einem eingespielten Backup:** Eine Version von vor der Überwachung
speichert ihre Läufe nicht, und ein Datenbank-Backup enthält das `last_cron` seiner Zeit. Nach einem
[Rollback]({{ '/de/guide/deployment/' | relative_url }}#rollback) auf eine solche Version und dem nächsten Deployment oder nach dem Einspielen
eines Backups meldet der erste Seitenaufruf deshalb womöglich einen Ausfall, obwohl der Cronjob die ganze
Zeit lief. Der nächste Lauf beendet ihn (spätestens nach einer Stunde); um die E-Mail zu vermeiden, starten
Sie den Cronjob direkt nach einem solchen Schritt einmal von Hand (`php bin/cron.php`, *Run workflow* oder
`gh workflow run cron.yml`).

### GitHub schaltet den Cron-Workflow nach 60 Tagen ab {#github-60-days}

Nutzen Sie das mitgelieferte `cron.yml` in einem **öffentlichen** Repository (Fork von TalkSlot oder eigenes
[Repository nur mit Konfiguration]({{ '/de/guide/deployment/' | relative_url }}#deploying-from-your-own-repository)),
schaltet GitHub geplante Workflows ab, wenn es im Repository 60 Tage lang keine Aktivität gab. In einem
Fork sind geplante Workflows von Anfang an aus. Der Workflow schlägt dann nicht fehl, er läuft einfach nicht
mehr, deshalb kommt von GitHub keine Fehlermeldung; die Überwachung bemerkt es nach 3 Stunden.

Er bleibt aus, bis Sie ihn wieder einschalten: *Actions → Cron → Enable workflow* oder
`gh workflow enable cron.yml`. Starten Sie ihn danach einmal von Hand, um ihn zu prüfen (*Run workflow* oder
`gh workflow run cron.yml`); die Warnung in der Übersicht verschwindet nach dem ersten vollständigen Lauf.

Außerdem verzögert GitHub geplante Läufe bei hoher Last, besonders zur vollen Stunde, und lässt einzelne
ganz aus. Deshalb startet `cron.yml` zur Minute 17, und die Überwachung wartet zwei ausgefallene Läufe ab.

## Logs

TalkSlot schreibt ein eigenes Log nach `app/var/php-error.log`; das Logging von PHP selbst ist abgeschaltet,
weil dessen Meldungen Eingaben enthalten können. Werte in Anführungszeichen und Klammern sowie
E-Mail-Adressen werden vor dem Schreiben entfernt. Das Log wird bei 5 MB rotiert (eine Vorgängerdatei
`php-error.log.1`).

## Backups

Sichern Sie die **Datenbank** (z. B. `mysqldump --single-transaction`) und `config/config.php`. `app/var/`
enthält nur Sessions, den Zähler für Fehlermeldungen und Logs und braucht kein Backup. Sichern Sie die
Datenbank vor einem Update, dessen Release Notes eine Datenbank-Migration nennen: Ein Rollback schaltet den
Code um, nicht die Datenbank.

Bedenken Sie, dass Buchungen personenbezogene Daten mit kurzer Lebensdauer sind: Spielen Sie ein Backup nur
so weit wie nötig zurück und lassen Sie anschließend die Bereinigung laufen.

## Secrets austauschen {#changing-secrets}

Tauschen Sie die Passwörter und den geheimen Schlüssel aus `config.php` aus, wenn sie in falsche Hände
gelangt sein könnten, etwa wenn die Übersicht gewarnt hat, dass alle Benutzer des Servers die Datei lesen
können, wenn jemand mit FTP-Zugang ausgeschieden ist oder wenn ein altes Backup verloren gegangen ist.

1. **Neue Werte an der Quelle setzen:** ein neues Datenbank-Passwort beim Hoster, ein neues SMTP-Passwort
   beim Mail-Anbieter, ein neues `APP_SECRET` (`openssl rand -hex 32`). Zwischen dem neuen
   Datenbank-Passwort und dem Deployment erreicht die Seite die Datenbank nicht; wählen Sie dafür eine
   ruhige Zeit.
2. **Secrets dort ändern, wo sie liegen, und neu ausspielen.** Im empfohlenen Environment `production`:
   `gh secret set DB_PASS --env production`, ebenso `SMTP_PASS` und `APP_SECRET`. Lassen Sie `--env` nur
   weg, wenn Ihre Secrets im Repository liegen; beim Deployment aus einem
   [eigenen Repository]({{ '/de/guide/deployment/' | relative_url }}#deploying-from-your-own-repository)
   setzen Sie sie dort. Ein Secret im Environment hat Vorrang vor einem gleichnamigen Repository-Secret;
   wer das falsche ändert, ändert also nichts. `gh secret list --env production` zeigt, wann jedes zuletzt
   geändert wurde. Danach neu ausspielen (*Actions → Deploy → Run workflow*). Ohne GitHub Actions
   bearbeiten Sie stattdessen `config/config.php`.
3. **Alte Versionen löschen.** Beim atomaren Deployment behält jede Version auf dem Server ihre eigene
   `config.php` mit den alten Werten. *Actions → Switch version* mit der Eingabe `prune` (oder
   `bin/deploy.sh prune`) löscht alle Versionen außer der aktiven. Ein Rollback auf eine ältere Version ist
   danach nicht mehr möglich (mit geändertem Datenbank-Passwort würde sie ohnehin nicht laufen).
4. **Backups prüfen:** Ersetzen oder löschen Sie alte Sicherungen von `config.php`.
5. **Falls das Datenbank-Passwort gelesen worden sein könnte:** Prüfen Sie unter *Benutzer*, ob dort
   unbekannte Konten stehen, und melden Sie alle ab, indem Sie die Dateien in `app/var/sessions/` löschen
   (beim Deployment: `<APP_DIR>/app/var/sessions/`). Die Datenbank enthält die Konten der Lehrkräfte und die
   Buchungen der Eltern. Die Schule muss deshalb bewerten, ob eine Verletzung des Schutzes
   personenbezogener Daten vorliegt: Sie dokumentiert jede Verletzung (Art. 33 Abs. 5 DSGVO) und meldet sie
   binnen 72 Stunden der Aufsichtsbehörde, es sei denn, sie führt voraussichtlich nicht zu einem Risiko
   für die Betroffenen; bei hohem Risiko sind auch diese zu benachrichtigen (Art. 33, 34 DSGVO).

Ein neues `APP_SECRET` ändert das Token für den Web-Cron und den Schlüssel für den
[Health-Check]({{ '/de/guide/deployment/' | relative_url }}#health-check). Die mitgelieferten Workflows
leiten beide aus demselben Secret ab; ein externer Cron-Dienst braucht das neue Token (siehe
[Web-Cron]({{ '/de/guide/installation/' | relative_url }})), sonst läuft die Bereinigung nur noch bei
Seitenaufrufen, und E-Mails in der Warteschlange bleiben liegen. Außerdem werden die Rate-Limits zurückgesetzt, und Eltern müssen ein offenes
Buchungsformular neu laden oder einen Rundencode erneut eingeben. Admin-Sitzungen, Änderungslinks und
Buchungen bleiben gültig.

## Löschfristen

| Was | Wann |
|---|---|
| Buchungen (Name, E-Mail) eines Tages | ab 0:00 Uhr des Folgetages |
| Terminrunden | nach ihrem letzten Tag deaktiviert, standardmäßig 24 h später gelöscht (pro Runde einstellbar, leer = nie); nur Runden, die in Gebrauch waren (irgendwann aktiv oder mit einem Eintrag der Lehrkraft); Entwürfe, die nie aktiv waren, bleiben |
| Rate-Limit-Daten | spätestens nach 24 Stunden |
| Inaktive Konten von Lehrkräften ohne Runden in Gebrauch | 15 Monate nach dem letzten Login, zusammen mit ihren Entwürfen |
| *Protokoll* der Admin-Änderungen | nach 90 Tagen (`audit_log_days`) |
| Einträge der Mail-Warteschlange (ohne Adresse und Inhalt) | sobald die E-Mail verschickt ist; nicht zustellbare nach 48 Stunden |

Die Bereinigung läuft im Cronjob und ersatzweise bei Seitenaufrufen, höchstens alle 15 Minuten. Dieselben
Läufe stellen die Zusammenfassungen an Lehrkräfte und die Erinnerungen an Eltern in die Warteschlange; der
Cronjob verschickt sie.

## Mail-Warteschlange

Außer bei den sofort verschickten Bestätigungen (unten) wartet niemand beim Seitenaufruf auf den
Mailserver: Seitenaufrufe führen nur die Datenbankschritte der Bereinigung aus, und Erinnerungen,
Zusammenfassungen und fehlgeschlagene E-Mails kommen in eine **Mail-Warteschlange** (Outbox), die der
**Cronjob** verschickt (`bin/cron.php` oder der Web-Cron). Pro Lauf
sind das höchstens 100 E-Mails in etwa 20 Sekunden über eine SMTP-Verbindung; einzelne Bestätigungen,
Änderungsmitteilungen und Absagen an Eltern gehen vor, dann die E-Mails an viele Eltern nach einer Änderung
durch eine Lehrkraft (ein Tag oder eine Runde gelöscht oder verlegt) und Zusammenfassungen, dann
Erinnerungen
(siehe [Konfiguration]({{ '/de/guide/configuration/' | relative_url }}#mail-warteschlange), auch für eine
Grenze pro Stunde). Es verschickt immer nur ein Lauf, und zwar je Datenbank: Installationen auf demselben
Datenbankserver halten sich nicht gegenseitig auf. Der Cronjob wartet bis zu 15 Sekunden auf einen
laufenden Lauf.

- **Bestätigungen bleiben sofort.** Buchen oder verschieben Eltern einen Termin oder speichert eine Lehrkraft
  einen Eintrag mit E-Mail, geht die E-Mail sofort hinaus. Lehnt der Mailserver sie ab, kommt sie in die
  Warteschlange, und die Seite sagt, dass die Bestätigung folgt. Die Warteschlange versucht es nach 5, 15,
  30, 60 und 120 Minuten erneut, danach alle 240 Minuten.
- **Nur Verweise.** Ein Eintrag der Warteschlange sagt nur, welche E-Mail für welche Buchung oder welches
  Konto aussteht. Adresse und Text entstehen erst beim Versand aus der Buchung, und eine später verschickte
  Bestätigung bekommt einen eigenen, neuen persönlichen Link (der erste bleibt gültig); die Warteschlange
  enthält also weder Adresse noch Link. Einträge verschwinden mit ihrer Buchung und werden nach dem Versand
  gelöscht, spätestens nach 48 Stunden.
- **Ausnahme: Absagen durch die Lehrkraft.** Löscht eine Lehrkraft eine Buchung, einen Tag, eine Runde oder
  ein Admin ein Konto mit angehaktem *Eltern per E-Mail informieren*, ist die Buchung schon gelöscht, bevor
  die Absage hinausgeht. Ein solcher Eintrag enthält deshalb, was genau diese E-Mail braucht (Adresse,
  Sprache, Name, Termin und Lehrkraft, als JSON in `mail_outbox.payload`, eingeführt mit Schemaversion 21),
  nie einen Link oder Token, und wird wie die anderen gelöscht: nach dem Versand, spätestens nach
  48 Stunden. Nachdem ein Admin ein Konto gelöscht hat, enthält er keine Adresse der Lehrkraft (die E-Mail
  geht ohne Reply-To hinaus). Eine Absage, deren Termin inzwischen begonnen hat, wird verworfen; eine für
  einen Termin in den nächsten 24 Stunden geht wie eine einzelne E-Mail hinaus, ohne auf das Stundenlimit
  zu warten. Versionen mit Schemaversion 20 oder älter verwerfen solche Einträge ungesendet; ein Rollback
  auf eine davon verliert also die Absagen, die gerade noch warten.
- **Nichts geht unbemerkt verloren.** Die Buchungsliste zeigt *E-Mail nicht zugestellt* bei einem Eintrag,
  dessen E-Mail nicht verschickt werden konnte (mit *neuer Versuch folgt*, solange sie wiederholt wird), und
  Admins sehen in der Übersicht einen Hinweis, wenn E-Mails länger als zwei Stunden warten, meist weil der
  Cronjob nicht läuft oder der Mailserver sie weiter ablehnt. Ausnahme sind Absagen: Ihre Buchung gibt es
  nicht mehr, eine nach 48 Stunden verworfene Absage hinterlässt also keine Markierung bei einem Eintrag,
  nur den Hinweis in der Übersicht, solange sie wartet.
- **Ohne Cronjob** können E-Mails nur bei Seitenaufrufen hinausgehen, wenn PHP als PHP-FPM oder LiteSpeed
  läuft: Die Seite wird zuerst ausgeliefert, danach gehen bis zu 10 fällige E-Mails hinaus
  (`mail_queue.after_response`). Mit Apaches mod_php geht das nicht; richten Sie deshalb den Cronjob ein,
  das mitgelieferte `cron.yml` startet ihn stündlich. Ohne ihn gehen dort keine Erinnerungen und
  Zusammenfassungen hinaus, und wartende E-Mails werden nach 48 Stunden verworfen.

## Fehlersuche

**„TalkSlot kann nicht starten“ auf jeder Seite.** Die Seite nennt, was nicht stimmt: eine PHP-Version vor
8.3, eine fehlende PHP-Erweiterung, eine fehlende `config/config.php` oder ein Fehler darin (siehe
[Prüfung der Konfiguration]({{ '/de/guide/configuration/' | relative_url }}#checking)). Der Cronjob gibt
dasselbe auf Englisch aus. Auch Besucher sehen diese Meldung, denn ohne Korrektur funktioniert TalkSlot nicht.

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
statt `mail` und achten Sie auf die Meldung „Mailversand fehlgeschlagen“. Erinnerungen und
Zusammenfassungen verschickt der Cronjob: Meldet die Übersicht wartende E-Mails, prüfen Sie, ob er läuft
(`php bin/cron.php -v` gibt `mails_sent` aus).

**Die Datenschutzerklärung zeigt `[HOSTER MIT ANSCHRIFT]`.** Setzen Sie `hosting` (bzw.
`HOSTING_DE`/`HOSTING_EN`); siehe [Datenschutz und Recht]({{ '/de/guide/privacy/' | relative_url }}).

**Eine Einladung oder ein Passwortlink konnte nicht gesendet werden.** Der Admin sieht nur einen Hinweis;
der Link wird nie angezeigt. Bringen Sie den Mailversand in Ordnung (siehe oben) und senden Sie ihn dann
unter *Benutzer → Bearbeiten* erneut.

**Ein Gerät ist verloren gegangen oder ein Konto könnte missbraucht sein.** Siehe
[Bei Verdacht auf Missbrauch]({{ '/de/guide/security/' | relative_url }}#bei-verdacht-auf-missbrauch):
alle Sitzungen beenden, Konten und Protokoll prüfen, Passwörter ändern und, wenn personenbezogene Daten
betroffen sein könnten, rechtzeitig melden.
