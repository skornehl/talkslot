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
  vendor/         PHPMailer (mitgeliefert, nur für SMTP genutzt)
  var/            Laufzeitdaten: Sessions, Lock-Datei, Fehler-Log
  schema.sql      Datenbankschema für Neuinstallationen
bin/              cron.php, install.php, build.sh, deploy.sh, build-config.php, …
config/           config.sample.php → config.php (nicht in Git)
public/           Document Root: index.php (Front Controller), install.php, .htaccess, assets/
dev/              Docker-Umgebung, reset.sh, demo.sh
docs/             diese Website (GitHub Pages, Jekyll)
tests/            unit.php, smoke.sh, browser.mjs, deploy.sh
```

## Tests

```bash
php tests/unit.php      # Unit-Tests, keine Datenbank nötig; die CI führt sie unter PHP 8.3, 8.4 und 8.5 aus
tests/smoke.sh          # ~300 End-to-End-Prüfungen gegen das Docker-Setup (vorher dev/reset.sh)
node tests/browser.mjs  # das JavaScript in Headless-Chrome (vorher dev/reset.sh && dev/demo.sh)
tests/deploy.sh         # das atomare Deployment über lftp file:// unter Last, Zurückschalten bei Fehlern (braucht lftp, openssl und die Datenbank)
```

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

## Konventionen

- **Sprache:** Code, Kommentare, Commits und Dokumentation auf Englisch. Der Admin-Bereich ist deutsch;
  die Elternseiten nutzen `app/lang/*.php`, und jeder Schlüssel muss in jeder Sprache vorhanden sein (ein
  Unit-Test prüft das).
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, …);
  Releases werden daraus abgeleitet.
- **Datenbank:** ausschließlich Prepared Statements; das SQL-Audit im Unit-Test schlägt bei
  eingesetzten (verketteten) Variablen fehl.
- **Admin-Routen:** Tragen Sie eine Route in `AdminController::ROUTES` mit der Rolle, die sie braucht, als
  viertem Element ein (`'teacher'` für jedes angemeldete Konto, `'admin'` nur für Admin-Konten). Eine Route
  ohne bekannte Rolle ist nur für Admins erreichbar. GET-Routen dürfen keine Daten verändern; der Smoke-Test
  ruft jede GET-Route der Tabelle auf und schlägt bei einem Platzhalter fehl, den er noch nicht füllen kann
  (dann die IDs in `tests/smoke.d/security.sh` ergänzen).
- **Migrationen** (`app/src/Migrations.php`) fügen nur Spalten mit Standardwerten und Tabellen hinzu, damit
  ein Rollback auf eine ältere Version weiter funktioniert. Erhöhen Sie `LATEST`, fügen Sie den Schritt
  hinzu und aktualisieren Sie `schema.sql`.
- **Rechtstext-Vorlagen:** Aktualisieren Sie nach einer Änderung an `app/templates/pages/*.md` die
  `CURRENT_PAGE_HASHES` und verschieben Sie die alten Hashes nach `OLD_PAGE_HASHES`, damit unbearbeitete
  Texte automatisch aktualisiert werden. Der Unit-Test nennt Ihnen die neuen Hashes.
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
