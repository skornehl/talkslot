---
title: Entwicklung
description: Projektstruktur, Tests und wie Sie mitwirken können.
---

TalkSlot ist bewusst einfach gehalten: reines PHP 8.3+, kein Framework, kein Composer, kein Node, kein
Build-Schritt. Es muss weiterhin auf Shared Hosting mit reinem FTP-Zugang laufen.

## Projektstruktur

```
app/              Anwendung (außerhalb des Document Roots)
  bootstrap.php   gemeinsamer Einstiegspunkt: Konfiguration, Autoloader, Fehlerbehandlung
  src/            Klassen (Db, Auth, BookingService, SlotGenerator, Cleanup, Mailer, …)
  src/Controller  PublicController (Eltern), AdminController (/admin)
  templates/      HTML-Templates; pages/*.md sind die Rechtstext-Vorlagen
  lang/           Übersetzungen für die Elternseiten, eine Datei pro Sprache
  lang/admin/en/  englische Texte des Admin-Bereichs, ein Wörterbuch pro Thema (der deutsche Text ist der Schlüssel)
  vendor/         PHPMailer (mitgeliefert, nur für SMTP genutzt)
  var/            Laufzeitdaten: Sessions, Lock-Datei, Fehler-Log
  schema.sql      Datenbankschema für Neuinstallationen
bin/              cron.php, install.php, build.sh, deploy.sh, build-config.php, …
config/           config.sample.php → config.php (nicht in Git)
public/           Document Root: index.php (Front Controller), install.php, .htaccess, assets/
dev/              Docker-Umgebung, reset.sh, demo.sh, cs.sh
docs/             diese Website (GitHub Pages, Jekyll)
tests/            unit.php, smoke.sh, integration.php, browser.mjs, deploy.sh, coverage/, phpstan.neon
```

## Tests

```bash
php tests/unit.php      # Unit-Tests, keine Datenbank nötig; die CI führt sie unter PHP 8.3, 8.4 und 8.5 aus
tests/smoke.sh          # mehrere hundert End-to-End-Prüfungen gegen das Docker-Setup (vorher dev/reset.sh)
php tests/integration.php  # Services gegen die Datenbank in einem Wegwerf-Schema (Teil des Smoke-Tests)
node tests/browser.mjs  # das JavaScript in Headless-Chrome (vorher dev/reset.sh && dev/demo.sh)
tests/deploy.sh         # das atomare Deployment über lftp file:// unter Last, Zurückschalten bei Fehlern (braucht lftp, openssl und die Datenbank)
dev/phpstan.sh          # statische Analyse mit PHPStan (lädt das festgelegte PHAR nach dev/data/)
```

`tests/unit.php` bindet jede Datei aus `tests/unit.d/` ein, `tests/smoke.sh` jede aus `tests/smoke.d/`
(eine Datei pro Thema, siehe die `README.md` dort), damit Arbeiten an verschiedenen Themen nicht dieselben
Zeilen ändern.

Der Smoke-Test deckt ab: Login und Sperre, Kontoverwaltung, Datentrennung zwischen Lehrkräften, CSRF,
Honeypot, XSS-Escaping, SQL-Injection, 20 parallele Buchungen auf einen Termin (genau eine gelingt),
Verschieben und Stornieren, Buchungslimits, Konflikterkennung, Drucken, Bereinigung und Fehlermeldungen.
Nach einem vollständigen Durchlauf darf keine Fehler-E-Mail verschickt worden sein.

Der Browser-Test steuert Headless-Chrome (Node 22+, ohne Abhängigkeiten) durch das JavaScript: Zeitfelder und
Terminvorschau (abgeglichen mit den Terminen, die der Server erzeugt), Datums- und Pausenfelder, Schutz vor
doppeltem Absenden und Löschbestätigungen, Link kopieren, die Übersetzung im Browser (mit nachgebildeter
Translator-API, einschließlich Sprachpaket-Downloads, Neuübersetzung und Fehlerfällen), „Termin auf diesem Gerät merken“ einschließlich abgewiesener fremder Links, die Sprachwahl in Handybreite
(Enter bucht weiterhin, ein Wechsel behält die Eingaben, Hinweis auf die Browsersprache), Fehlerfokus und
den Hell/Dunkel-Schalter.

Die Sicherheitsinvarianten (Rollen in der Routentabelle, keine Datenänderung durch GET, Sperre auf jedem
Buchungsweg, Sicherheits-Header auf Fehlerseiten, abgelehnte seitenübergreifende Formulare) stehen in
`tests/unit.d/security.php` und `tests/smoke.d/security.sh`; siehe [Sicherheit]({{ '/de/guide/security/' | relative_url }}).

Die CI führt bei jedem Push die Unit-Tests aus sowie Smoke-, Browser- und Deploy-Tests gegen die Docker-Umgebung.
Außerdem laufen die Smoke-Tests gegen MariaDB 10.6, die Mindestversion (`MARIADB_VERSION=10.6` für
`dev/compose.yml`), und sie prüft, dass PHP 7.4 und 8.2 die Meldung von `App\Platform` erhalten statt eines
Syntaxfehlers.

### Messen statt schätzen

- **Warnungen lassen die Tests scheitern.** Die App protokolliert PHP-Warnungen, Hinweise (Notices) und
  Deprecations nur (`app/var/php-error.log`) und läuft weiter. `tests/unit.php` macht daraus Fehler und
  scheitert am Ende auch an solchen, die App-Code abgefangen hat; nach dem Smoke- und dem Deploy-Test und in jedem
  CI-Job schlägt `tests/php-errors.sh` bei jeder solchen Zeile der App im Fehler-Log fehl (Zeilen aus Testcode, der
  sie absichtlich auslöst, zählen nicht).
- **Zeilenabdeckung.** Die CI baut den App-Container mit PCOV (`COVERAGE=1 docker compose -f dev/compose.yml up
  -d --build`): `tests/coverage/prepend.php` zeichnet die Zeilen jeder Anfrage und jedes CLI-Skripts der Unit-,
  Smoke-, Integrations-, Browser- und Deploy-Tests auf, `tests/coverage/report.php` führt sie zusammen. Die
  Zusammenfassung des CI-Laufs zeigt die Abdeckung je Datei; bei einem Pull Request sind geänderte Zeilen, die
  kein Test ausführt, im Diff markiert. **Ein Pull Request darf die Abdeckung nicht senken:** Der Lauf auf `main`
  hinterlegt seine Abdeckung als Commit-Status `coverage`, und der Lauf eines Pull Requests schlägt fehl, wenn er
  mehr als 0,05 Prozentpunkte darunter liegt (einige wenige Zeilen hängen vom Timing ab). Außerdem scheitert jeder
  Lauf unter `COVERAGE_MIN` in `.github/workflows/tests.yml` oder wenn kein Test eine PHP-Datei überhaupt lädt.
  Lokal nach den Tests in einem solchen Container:
  `docker compose -f dev/compose.yml exec -w /srv/app app php tests/coverage/report.php`. Die Rohdaten sammeln
  sich in `/tmp/talkslot-coverage` im Container; leeren Sie das Verzeichnis vor einem neuen Lauf, sonst mischen
  sich Zeilennummern von älterem Code hinein.
- **Statische Analyse.** `dev/phpstan.sh` führt [PHPStan](https://phpstan.org/) auf Stufe 6 aus
  (`tests/phpstan.neon`), die CI ebenso. Die Fehler, die es bei der Einführung schon gab, stehen in
  `tests/phpstan-baseline.neon`; jeder neue lässt den Lauf scheitern. Werttypen von Arrays sind noch nicht
  verlangt. PHPStan ist nur ein Entwicklungswerkzeug: Das Skript lädt eine festgelegte Version und prüft ihre
  Prüfsumme, die App und ihr Build enthalten es nie.
- **Integrationstests** (`tests/integration.php`) rufen die Services direkt gegen die Datenbank auf, ohne HTTP.
  Jeder Lauf legt die Tabellen aus `schema.sql` unter einem zufälligen Präfix (`it<hex>_`) in der konfigurierten
  Datenbank an und löscht sie am Ende wieder; die Daten einer Installation oder eines laufenden Smoke-Tests
  bleiben unberührt. `dev/reset.sh` löscht Tabellen, die ein abgebrochener Lauf zurückgelassen hat. Das Skript
  läuft nur mit `debug` in `config.php` (der Docker-Umgebung) oder `TALKSLOT_INTEGRATION=1`, nie versehentlich gegen
  eine echte Installation. `App\Time::setNow()` hält die Uhr an, sodass Fristen und Löschfristen ohne verschobene
  Zeitstempel in der Datenbank getestet werden (das geht auch in Unit-Tests; `time()` etwa für die Ratenlimits
  bleibt davon unberührt).

## Konventionen

- **Sprache:** Code, Kommentare, Commits und Dokumentation auf Englisch (die deutsche Anleitung in `docs/de/`
  auf Deutsch). Der Admin-Bereich ist je Konto deutsch oder englisch, die Elternseiten gibt es in sechs Sprachen.
- **Begriffe in der Oberfläche:** Ein *Termin* ist ein Zeitfenster (englisch: slot), eine *Buchung* ist ein
  Termin, den Eltern gebucht oder die Lehrkraft eingetragen hat; in deutschen Texten kein „Slot“. Eltern lesen keine
  Fachbegriffe wie „localStorage“; der Datenschutztext heißt für sie *Datenschutzhinweise* (*Datenschutz* als kurzer
  Link im Fußbereich).
- **Admin-Texte** stehen im Code auf Deutsch und laufen durch `__()`: `__('Runde gespeichert.')` gibt den
  deutschen Text unverändert zurück und für ein englisches Konto den Eintrag aus den Wörterbüchern in
  `app/lang/admin/en/*.php` (eine Datei pro Thema, alle zusammengeführt; ein Text braucht genau einen
  Eintrag, ein zweiter in einer anderen Datei wird ignoriert). Übergeben Sie den deutschen Text als
  String-Literal, damit der Unit-Test ihn findet; Platzhalter werden in beiden Sprachen gefüllt:
  `__('{count} Termine', ['count' => $n])`. `__h()` macht dasselbe für HTML und setzt die Werte unmaskiert
  ein (für Links, die der Aufrufer selbst gebaut und maskiert hat). JavaScript im Admin-Bereich nutzt
  `L('…')`; dessen Texte gehören nach `app/lang/admin/en/js.php`, das einzige Wörterbuch, das der Browser
  bekommt. Ein Unit-Test schlägt fehl bei einem Text ohne englischen Eintrag, bei einem Eintrag, den niemand
  nutzt, und bei abweichenden Platzhaltern. E-Mails an ein Konto folgen dessen Sprache (`AdminI18n::with()`).
- **Elterntexte** laufen durch `t('schlüssel')` mit `app/lang/{de,en,pl,ru,tr,uk}.php`; jeder Schlüssel muss
  in jeder Sprache vorhanden sein (ein Unit-Test prüft das). Lassen Sie neue polnische, russische, türkische
  und ukrainische Texte von Muttersprachlern gegenlesen und vermerken Sie das im Pull Request. Zahlen mit
  Einheit laufen über `I18n::plural()` (`fmt_hours()`): Jede Sprachdatei nennt ihre Pluralregel (`'plural'`:
  `one`, `pl`, `east-slavic` oder `none`) und so viele Formen von `'hours'`, wie die Regel braucht.
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, …);
  Releases werden daraus abgeleitet.
- **Coding-Standard:** PSR-12 für `app/src`, `bin`, `public` und `config` (die Ausnahmen stehen in
  `.php-cs-fixer.dist.php`). `dev/cs.sh` prüft ihn mit einem festgelegten PHP-CS-Fixer, `dev/cs.sh --fix` wendet ihn
  an; die CI prüft ihn im Workflow *Coding standard*.
- **Reguläre Ausdrücke:** Routen und Prüfungen eines ganzen Werts enden auf `\z`, nicht auf `$`, das auch vor einem
  abschließenden Zeilenumbruch passt (ein Unit-Test prüft die Routen).
- **Datenbank:** ausschließlich Prepared Statements; das SQL-Audit im Unit-Test schlägt bei
  eingesetzten (verketteten) Variablen fehl.
- **Tabellenpräfix:** SQL nennt die Tabellen ohne Präfix, `Db::prefix()` setzt es davor. Eine neue Tabelle kommt in
  `Db::TABLES`, ein Fremdschlüssel heißt `fk_…` (er bekommt das Präfix ebenfalls; zum Löschen den Namen mit
  `Db::foreignKey()` nachschlagen, da ältere Installationen ihn ohne Präfix haben), kein Alias heißt wie eine
  Tabelle, und eine benannte Sperre läuft über `Db::lock()`. Unit-Tests prüfen alle vier Regeln.
- **Reguläre Ausdrücke auf Text** bekommen den Modifikator `u`, wo Bytes und Zeichen sich unterscheiden: ein
  Nicht-ASCII-Zeichen im Muster, `\R`, `\h`, `\v`, `\X`, `\p{…}`, `\x{…}`. Ohne ihn zerteilen sie UTF-8-Zeichen
  (polnische, russische, ukrainische Namen und Mails). Ein Unit-Test prüft jedes Muster im Code.
- **Smoke-Prüfungen** testen HTTP-Status, die Datenbank oder einen stabilen Marker im Template (`data-…`,
  Hilfsfunktion `marker` in `tests/smoke.sh`) statt des Wortlauts einer Seite, der sich ändert. Das zählt vor
  allem bei Prüfungen, dass etwas *nicht* angezeigt wird: Nach einer Umformulierung bestünden sie, ohne noch
  etwas zu prüfen.
- **Admin-Routen:** Tragen Sie eine Route in `AdminController::ROUTES` mit der Rolle, die sie braucht, als
  viertem Element ein (`'teacher'` für jedes angemeldete Konto, `'admin'` nur für Admin-Konten). Eine Route
  ohne bekannte Rolle ist nur für Admins erreichbar. GET-Routen dürfen keine Daten verändern; der Smoke-Test
  ruft jede GET-Route der Tabelle auf und schlägt bei einem Platzhalter fehl, den er noch nicht füllen kann
  (dann die IDs in `tests/smoke.d/security.sh` ergänzen).
- **Migrationen** (`app/src/Migrations.php`) sind additiv, damit ein Rollback auf die vorige Version derselben
  Major-Version weiter funktioniert; die Regeln stehen unten unter [Migrationen](#migrationen).
- **Rechtstext-Vorlagen:** Aktualisieren Sie nach einer Änderung an `app/templates/pages/*.md` die
  `CURRENT_PAGE_HASHES` und verschieben Sie die alten Hashes nach `OLD_PAGE_HASHES`, damit unbearbeitete
  Texte automatisch aktualisiert werden. Die Datenschutzerklärung gibt es in allen sechs Elternsprachen für beide
  Betriebsarten (`privacy*.{de,en,pl,ru,uk,tr}.md`): Eine Änderung an der deutschen Vorlage gehört in alle
  zwölf Dateien, mit denselben Abschnitten und den deutschen Platzhaltern in den Übersetzungen (ein Unit-Test
  vergleicht sie); markieren Sie geänderte Übersetzungen für eine Prüfung durch Muttersprachler. Der Unit-Test nennt Ihnen die neuen Hashes. Für bearbeitete Texte
  braucht es keinen neuen Hinweis in `AdminController::legalHints()`: Ihr Vorlagenstand zeigt Admins die
  geänderten Abschnitte von selbst (`LegalTexts::outdated()`). Halten Sie die Abschnitte unter Überschriften
  der ersten Ebene (`# 5. …`) und jeden Absatz bzw. Listenpunkt in einer Zeile, damit der Vergleich je
  Abschnitt und Zeile funktioniert; ein Unit-Test prüft, dass die vier Datenschutz-Vorlagen dieselben
  nummerierten Abschnitte haben. Benennen Sie Überschriften möglichst nicht um: Ein Abschnitt ist an seiner
  Überschrift ohne Nummer erkennbar, eine umbenannte gilt als neuer Abschnitt, und die ausgefüllten Angaben
  des alten werden beim Übernehmen nicht mitgenommen. Ein Platzhalter steht innerhalb einer Zeile für Text bis
  zum Zeilenende, nur allein in seiner Zeile (wie `[[VERANTWORTLICHE]]`) auch für mehrere Zeilen. Ein
  Platzhalter darf auch durch nichts ausgefüllt werden; eine Zeile, die kaum mehr als ein Platzhalter ist
  (`- [WEITERE EMPFÄNGER]`), wird deshalb von fast jeder Zeile ausgefüllt: Geben Sie ihr eigenen festen Wortlaut
  (`- **Weitere Empfänger:** [NAME UND ANSCHRIFT]`). Ändert sich, was TalkSlot verarbeitet (Daten, Empfänger, Fristen,
  Speicherung im Browser, Arten von E-Mails), passen Sie in derselben Änderung die Datenschutz-Vorlagen
  beider Betriebsarten auf Deutsch und Englisch an.
- **Deployment-Variablen:** Jede Variable, die `bin/build-config.php` liest, muss im Build-Schritt von
  `.github/workflows/deploy.yml` übergeben werden; auch das prüft ein Unit-Test.
- **Konfigurationsoptionen:** Eine neue Option gehört in `App\ConfigSchema::KEYS` (Typ, Standardwert) und
  in `config/config.sample.php`; der Unit-Test schlägt fehl, wenn der Code einen Schlüssel liest, den das
  Schema nicht kennt, wenn ein Standardwert von dem des Codes abweicht oder der Schlüssel im Beispiel fehlt.
  Sonst würde Admins die Option als unbekannt gemeldet.
- **Plattformprüfung:** `app/bootstrap.php` bis `App\Platform::enforce()`, `app/src/Platform.php`,
  `public/index.php`, `public/install.php` und die Skripte in `bin/`, die die Bootstrap-Datei laden, müssen für
  ältere PHP-Versionen lesbar bleiben (dort keine benannten Argumente, kein `match`, kein
  `str_starts_with`), damit sie erklären können, dass PHP zu alt ist; die CI prüft das mit PHP 7.4.
- **Mitgelieferte Bibliotheken:** PHPMailer liegt fertig in `app/vendor/`. `composer.json` und
  `composer.lock` führen es nur auf, damit Dependabot neue Versionen und Sicherheitswarnungen meldet;
  Composer wird nie ausgeführt. Hebt Dependabot die Version an, führen Sie auf seinem Branch
  `bin/update-vendor.sh` aus und committen `app/vendor/`; bis beides übereinstimmt, schlägt der Unit-Test fehl.

## Architektur

### Ablauf einer Anfrage

Jede Anfrage, die keine Datei unter `public/` trifft, landet in `public/index.php`. Die Datei lädt
`app/bootstrap.php` (Konfiguration, Autoloader, Fehlerbehandlung; `App\Platform` weist ein zu altes PHP ab) und
ruft `App::run()` auf:

1. Weiterleitung auf HTTPS (`force_https`) und die Sicherheits-Header.
2. Auf einem installierten System, bei jeder Anfrage außer den beiden unten:
   - `Migrations::run()` bringt die Datenbank auf den neuesten Stand (immer nur eine Anfrage, unter
     `GET_LOCK`); unbearbeitete Rechtstexte werden danach durch neuere Vorlagen ersetzt (siehe
     *Rechtstext-Vorlagen* oben).
   - `Cleanup::maybeRun()` führt die Datenbankschritte der Bereinigung aus, höchstens alle
     `cleanup_interval_minutes` (reserviert per Compare-and-Set auf `settings.last_cleanup`;
     `cleanup_on_request` schaltet das ab).
   - `CronMonitor::check()` bemerkt einen Cronjob, der nicht mehr läuft.
   - Nach der Antwort gehen fällige E-Mails der Warteschlange hinaus (wo PHP die Antwort vorher abschließen
     kann, z. B. PHP-FPM; siehe `mail_queue.after_response`).

   Ein Fehler in diesem Schritt löst eine Fehler-E-Mail aus, blockiert die Seite aber nicht.
3. Ein Formular, das von einer anderen Website abgeschickt wurde, bekommt 403, bevor etwas verarbeitet wird.
4. `POST /cron` mit `X-Cron-Token` und `POST /health` mit `X-Health-Token` sind der Web-Cron und der
   Health-Check des Deployments; sie migrieren erst, nachdem sie ihren Schlüssel geprüft haben. Ohne den
   Header gelten diese Pfade als Adresse einer Runde (`/<slug>`).
5. `/admin…` geht an `AdminController::dispatch()` (Session, CSRF-Token, Routentabelle mit Rollen), alles
   andere an `PublicController::dispatch()` (Sprache aus `?lang=`, dem Formular oder dem Browser).

Die Controller geben Templates mit `View::render()` aus, das die Variablen an das Template übergibt. Jeder Wert
im HTML wird maskiert: mit `e()`, vom Markdown-Renderer oder von `__h()`, das den Text maskiert (die Werte, die
es einsetzt, müssen schon maskiert sein).

### Sperrreihenfolge

Schreibzugriffe, die Buchungen betreffen, sperren Zeilen in einer festen Reihenfolge: **Runde → ihre Tage →
Termine → Buchungen**. Das Löschen oder Ändern eines Tages, einer Runde oder eines Kontos
(`ScheduleService::LOCKS`) und der Neuaufbau der Termine eines Tages (`SlotGenerator::sync()`) halten sie ein;
das Löschen eines Kontos sperrt vorher die Zeilen der aktiven Admins, damit immer ein Admin bleibt
(`AccountSecurity`).

Eine Buchung läuft in `Db::transaction()`: Mit Buchungslimit sperrt sie die Runde und liest sie neu
(`BookingService::lockForLimit()`), dann ihren Tag, dann den Termin (`lockSlot()`), dann prüft sie, ob der Termin
schon gebucht ist. Jeder Weg sperrt in dieser Reihenfolge: Runde, Tag, Termine, Buchungen. Der Tag des Termins wird
vor der Transaktion gelesen, denn ein einfaches Lesen darin würde ihren Snapshot vor den Sperren beginnen. Eine
Transaktion, die eine Zeilensperre verliert (Deadlock, Sperr-Timeout, Zeile seit dem Snapshot geändert), wird einmal
von vorn wiederholt (`Db::retrying()`, nie innerhalb einer offenen Transaktion); Buchungen melden den Termin danach als vergeben, und `UNIQUE(slot_id)` ist die harte
Garantie gegen Doppelbuchungen. Die Änderungen von `ScheduleService`, `sync()` und `keepingAnAdmin()` laufen über
denselben Wrapper. Auf eine Zeilensperre wird höchstens 5 Sekunden gewartet. Für neuen Code: in dieser Reihenfolge
sperren und neue Transaktionen in `Db::retrying()` einbetten. Regeln, die das Formular prüft (ein Datum je Runde,
eindeutige Namen), sind auch Constraints in der Datenbank: Eine Anfrage, die an der Prüfung vorbeikommt, bekommt das
Formular mit einem Hinweis (`Db::isDuplicate()`), keine Fehlerseite. E-Mails gehen erst nach dem
Commit hinaus (direkt oder über die Warteschlange), nie innerhalb der Transaktion.

Hintergrundarbeiten nutzen stattdessen benannte Sperren: Migrationen und die Warteschlange `GET_LOCK`;
Erinnerungen und Zusammenfassungen reservieren ihre Zeilen mit `UPDATE … WHERE reminded_at IS NULL` und
`INSERT IGNORE`, damit zwei gleichzeitige Läufe nichts doppelt verschicken.

### Lebenszyklus der Daten

Der Cronjob (`bin/cron.php` oder `POST /cron`) führt `Cleanup::run()` aus, reiht Zusammenfassungen und
Erinnerungen ein und verschickt die Warteschlange; Seitenaufrufe führen dieselben Datenbankschritte als
Rückfallebene aus. Was wann gelöscht wird:

| Daten | Gelöscht |
| --- | --- |
| Buchungen (Name, E-Mail-Adresse) | um Mitternacht nach dem Tag des Termins; bei einer Absage sofort, außerdem mit ihrem Tag, ihrer Runde oder ihrem Konto |
| Runden | nach ihrem letzten Tag deaktiviert, wenn sie in Gebrauch waren, `delete_after_hours` später gelöscht (je Runde; nicht gesetzt = nie gelöscht) |
| E-Mail-Warteschlange (Verweise; bei einer Absage durch die Lehrkraft Adresse, Name und Termin) | nach dem Versand oder wenn nicht mehr nötig, mit ihrer Buchung oder ihrem Konto; spätestens nach 48 Stunden (`Outbox::MAX_AGE_HOURS`) |
| Rate-Limits (HMACs gekürzter IP-Adressen, von Kontonamen und IDs) | nach 24 Stunden |
| Versandprotokoll der Zusammenfassungen | nach 60 Tagen |
| Admin-Protokoll | nach `audit_log_days` (Standard 90) |
| Runden ohne Buchungen im Papierkorb (`round_trash`: Eingaben der Lehrkraft, keine Daten von Eltern) | nach 14 Tagen (`RoundTrash::KEEP_DAYS`) oder beim Wiederherstellen |
| Deaktivierte Lehrkraft-Konten ohne Runden in Gebrauch | nach 15 Monaten ohne Anmeldung |

Neue personenbezogene Daten brauchen ihren Löschschritt in `Cleanup::run()` (mit Test), eine Zeile in dieser
Tabelle und in den Datenschutz-Vorlagen beider Betriebsarten (von der Schule oder privat betrieben).

### Migrationen

`schema.sql` beschreibt immer den neuesten Stand: Eine neue Installation bekommt ihn und `Migrations::LATEST`
direkt. Eine bestehende führt beim nächsten Aufruf die fehlenden Schritte aus `Migrations::STEPS` aus.

- Fügen Sie einen Schritt unter der nächsten Nummer hinzu, erhöhen Sie `LATEST` und ändern Sie `schema.sql`
  im selben Commit. Ein veröffentlichter Schritt wird nie mehr geändert.
- Schritte sind **additiv und wiederholbar**: `ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`,
  `ADD KEY IF NOT EXISTS`, Datenänderungen, die bei erneutem Lauf dasselbe Ergebnis liefern. Eine neue
  `NOT NULL`-Spalte braucht einen Standardwert, weil die `INSERT`s der vorigen Version sie nicht nennen
  (Schritt 22 zeigt, wie eine Spalte dazukommt, die `NULL` erlaubt und deren Standardwert nur neue Zeilen
  bekommen). Ein
  abgebrochener Schritt kann dann einfach erneut laufen, und die vorige Version arbeitet mit dem neuen Schema,
  weil sie ignoriert, was sie nicht kennt. Darauf verlässt sich das Rollback mit einem Klick: Es schaltet den
  Code um, nicht die Datenbank.
- PHP-Arbeit eines Schritts (Daten nachtragen) gehört unter seiner Nummer in `Migrations::steps()`, wie bei den
  Schritten 7 und 10, und muss ebenfalls wiederholbar sein.
- Eine neue Tabelle gehört in `Db::TABLES`, sonst bekommt sie kein Tabellenpräfix; keine Spalte darf wie eine
  Tabelle heißen (Unit-Tests prüfen beides).
- **Nicht** in einer Minor-Version: Spalten oder Tabellen umbenennen oder löschen, Typ oder Kollation einer
  Spalte ändern, neue `UNIQUE`- oder Fremdschlüssel-Constraints auf bestehenden Spalten, `ON DELETE RESTRICT`.
  Sie können die vorige Version brechen oder an vorhandenen Daten scheitern. Sie warten auf eine Major-Version
  und kommen in zwei Schritten (erst erweitern, dann zurückbauen, „Expand/Contract“): die neue Spalte ergänzen
  und nutzen, die alte erst in einer späteren Major-Version entfernen. Schritt 3 (Umbenennen von
  `admin_users`, Löschen von Spalten) stammt aus der Zeit vor dieser Regel. Schritt 26 setzt `UNIQUE` und `CHECK` auf bestehenden
  Spalten als bewusste Ausnahme: nur wo die Zeilen es zulassen (sonst nennt er dem Betreiber die Runden, und ein späterer Cron-Lauf setzt die Regel, sobald sie in Ordnung sind), und die Vorversion lehnt solche
  Zeilen in ihren Formularen ohnehin ab.
- Während die Dateien eines Updates hochgeladen werden, kann neuer Code kurz auf das alte Schema treffen. Wo das
  zählt (eine Seite, die nicht scheitern darf), sollte der Code auf das alte Verhalten zurückfallen, bis der
  Schritt gelaufen ist, wie `RoundAccess::required()`. Ein solcher Rückfall nennt die Migration, die er
  überbrückt, und kann mit der nächsten Major-Version nur entfallen, wenn der
  [Upgrade-Pfad]({{ '/de/guide/operations/' | relative_url }}#updates) nicht mehr unterhalb dieser Migration beginnt: 2.0
  soll v1.3.0 voraussetzen (Migration 13).
- Die Schritte nutzen MariaDB-Syntax (10.6 oder neuer); MySQL wird nicht unterstützt.
- Parallele Branches: Zwei Branches, die einen Schritt unter derselben Nummer hinzufügen, geraten in Git in
  Konflikt, und wer beim Auflösen beide Blöcke behält, verliert einen davon unbemerkt (PHP behält von zwei
  gleichen Array-Schlüsseln nur den letzten). Nummerieren Sie stattdessen einen um, und prüfen Sie vor der Wahl
  einer Nummer `main` und die offenen Branches.

## Sicherheitskorrekturen (Maintainer) {#sicherheitskorrekturen}

Sicherheitslücken werden vertraulich gemeldet (siehe [Sicherheit]({{ '/de/guide/security/' | relative_url }})
und [`SECURITY.md`](https://github.com/skornehl/talkslot/blob/main/SECURITY.md), englisch), nie in einem
öffentlichen Issue. Der Maintainer bearbeitet eine Meldung als GitHub Security Advisory:

1. Die Meldung bestätigen und in einem Advisory-Entwurf bewerten (betroffene Versionen, Schweregrad).
2. Die Korrektur im **temporären privaten Fork** des Advisorys schreiben, nicht in einem öffentlichen Branch
   oder Pull Request, und Unit- und Smoke-Tests lokal ausführen (dort läuft keine CI). Vor dem Merge wird
   nichts über die Lücke öffentlich.
3. Die Korrektur aus dem Advisory mergen, gleich danach *Actions → Release* (`release.yml`) starten und das
   Advisory mit der korrigierten Version veröffentlichen (bei Bedarf eine CVE beantragen); in den Release Notes
   darauf verlinken. Danach die selbst betriebenen Installationen aktualisieren.

Sicherheitskorrekturen gibt es nur für das jeweils neueste Release.

## Mitwirken

Issues und Pull Requests sind auf [GitHub](https://github.com/skornehl/talkslot) willkommen. Bitte halten
Sie das Projekt frei von Abhängigkeiten und auf Shared Hosting lauffähig, ergänzen Sie Tests für geändertes
Verhalten und führen Sie Unit- und Smoke-Tests aus, bevor Sie einen Pull Request öffnen.

## An dieser Website arbeiten

Die Website liegt in `docs/` und wird von GitHub Pages mit Jekyll gebaut. Lokale Vorschau:

```bash
cd docs && jekyll serve --baseurl /talkslot
# http://localhost:4000/talkslot/
```

Die Screenshots stammen aus der lokalen Instanz mit Demodaten (`dev/reset.sh && dev/demo.sh`).

Die Website ist zweisprachig. Englische Seiten liegen in `docs/guide/`, deutsche in `docs/de/guide/` unter
denselben Dateinamen; die Seitenleiste steht in `docs/_data/nav.yml`, die Texte der Oberfläche in
`docs/_data/i18n.yml`. Wenn Sie eine Seite ändern, passen Sie bitte auch die andere Sprache an.
