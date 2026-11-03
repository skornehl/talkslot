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
tests/            unit.php, smoke.sh, deploy.sh
```

## Tests

```bash
php tests/unit.php      # Unit-Tests, keine Datenbank nötig; die CI führt sie unter PHP 8.3, 8.4 und 8.5 aus
tests/smoke.sh          # ~300 End-to-End-Prüfungen gegen das Docker-Setup (vorher dev/reset.sh)
tests/deploy.sh         # das atomare Deployment über lftp file:// unter Last (braucht lftp und die Datenbank)
```

Der Smoke-Test deckt ab: Login und Sperre, Kontoverwaltung, Datentrennung zwischen Lehrkräften, CSRF,
Honeypot, XSS-Escaping, SQL-Injection, 20 parallele Buchungen auf einen Termin (genau eine gelingt),
Verschieben und Stornieren, Buchungslimits, Konflikterkennung, Drucken, Bereinigung und Fehlermeldungen.
Nach einem vollständigen Durchlauf darf keine Fehler-E-Mail verschickt worden sein.

## Konventionen

- **Sprache:** Code, Kommentare, Commits und Dokumentation auf Englisch. Der Admin-Bereich ist deutsch;
  die Elternseiten nutzen `app/lang/*.php`, und jeder Schlüssel muss in jeder Sprache vorhanden sein (ein
  Unit-Test prüft das).
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, …);
  Releases werden daraus abgeleitet.
- **Datenbank:** ausschließlich Prepared Statements; das SQL-Audit im Unit-Test schlägt bei
  eingesetzten (verketteten) Variablen fehl.
- **Migrationen** (`app/src/Migrations.php`) fügen nur Spalten mit Standardwerten und Tabellen hinzu, damit
  ein Rollback auf eine ältere Version weiter funktioniert. Erhöhen Sie `LATEST`, fügen Sie den Schritt
  hinzu und aktualisieren Sie `schema.sql`.
- **Rechtstext-Vorlagen:** Aktualisieren Sie nach einer Änderung an `app/templates/pages/*.md` die
  `CURRENT_PAGE_HASHES` und verschieben Sie die alten Hashes nach `OLD_PAGE_HASHES`, damit unbearbeitete
  Texte automatisch aktualisiert werden. Der Unit-Test nennt Ihnen die neuen Hashes.
- **Deployment-Variablen:** Jede Variable, die `bin/build-config.php` liest, muss im Build-Schritt von
  `.github/workflows/deploy.yml` übergeben werden; auch das prüft ein Unit-Test.
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
