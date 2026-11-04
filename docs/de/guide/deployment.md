---
title: Deployment mit GitHub Actions
description: Deployments ohne Ausfallzeit auf Shared Hosting per FTPS, mit automatischen Prüfungen und Rollback per Klick.
---

TalkSlot bringt Workflows mit, die bei jedem Push auf `main` testen, bauen und per FTPS hochladen. Sie sind
für Shared Hosting (Webspace) gedacht, bei dem Sie nur FTP-Zugang haben. Alles ist optional: Ohne die
Variable `FTP_HOST` tun die Workflows für Deployment, Cronjob und Rollback nichts.

## So läuft ein Deployment ab

1. **Testen und bauen.** Lint und Unit-Tests laufen, dann erstellt `bin/build.sh` das Paket und
   `bin/build-config.php` erzeugt `config.php` aus Ihren Variablen und Secrets. Fehlende oder ungültige
   Werte brechen den Lauf ab, bevor etwas hochgeladen wird.
2. **Hochladen neben die laufende Version.** Die neue Version kommt in ein eigenes Verzeichnis
   (`<APP_DIR>/releases/<name>/`, Assets nach `<WEB_DIR>/v/<name>/`). Die Live-Seite bleibt unberührt.
   Direkt nach dem Code wird ihre `config.php` nur für den Eigentümer lesbar gemacht
   ([Dateirechte](#permissions-of-configphp)). Anschließend wird jede Datei auf dem Server geprüft.
3. **Umschalten in einem Schritt.** Die Zeigerdatei `<APP_DIR>/current` wird ersetzt (als `current.new`
   hochgeladen, dann umbenannt). Ab diesem Moment beantwortet die neue Version jede Anfrage. Alter und neuer
   Code laufen nie gleichzeitig und können daher keine inkonsistenten Daten schreiben.
4. **Prüfen.** Login-Seite, Startseite und das CSS der neuen Version werden abgerufen. Schlägt das fehl,
   schaltet der Workflow sofort auf die vorherige Version zurück, löscht die defekte und schlägt fehl.
5. **Aufräumen.** Die fünf neuesten Versionen bleiben für Rollbacks auf dem Server; ihre `config.php`
   bekommt dieselben Rechte wie die neue.

```
<APP_DIR>/current            Name der aktiven Version, z. B. 20261102-153012-v1.1.0
<APP_DIR>/releases/<name>/   app/, bin/, config/config.php einer Version
<APP_DIR>/app/var/           Sessions, Logs (von allen Versionen gemeinsam genutzt)
<WEB_DIR>/                   index.php, .htaccess, app_root.php (Document Root)
<WEB_DIR>/v/<name>/          CSS, JS und Bilder einer Version (ein Jahr lang gecacht)
```

Die Sessions liegen im gemeinsamen `app/var/`, daher wird durch ein Deployment niemand abgemeldet.

## Einrichtung

1. Forken Sie das Repository oder legen Sie ein eigenes [Repository nur mit Konfiguration](#deploying-from-your-own-repository) an.
2. Führen Sie das Setup-Skript mit der [GitHub CLI](https://cli.github.com/) aus:
   ```bash
   bin/setup-secrets.sh
   ```
   Es fragt Adresse der Seite, FTP-Host und Verzeichnisse, Datenbank, Sprachen und Hoster ab und erzeugt
   `APP_SECRET` und `INSTALL_KEY`.
3. Empfohlen: Legen Sie ein Environment `production` an (*Settings → Environments*), erlauben Sie nur den
   Branch `main` und hinterlegen Sie die Secrets dort.
4. Pushen Sie auf `main` oder starten Sie *Actions → Deploy → Run workflow*. Öffnen Sie dann `/install.php`,
   legen Sie das Admin-Konto an und führen Sie `gh variable delete ALLOW_INSTALL` aus, damit der Installer
   nicht mehr ausgeliefert wird.

### Variablen und Secrets

| Name | Typ | Pflicht | Bedeutung |
|---|---|---|---|
| `BASE_URL` | Variable | ja | öffentliche Adresse der Seite |
| `FTP_HOST` | Variable | ja | FTP-Server; aktiviert die Workflows |
| `WEB_DIR` | Variable | ja | Document Root, relativ zum FTP-Wurzelverzeichnis |
| `APP_DIR` | Variable | | App-Verzeichnis außerhalb des Web-Roots (Standard `talkslot-app`) |
| `FTP_USERNAME`, `FTP_PASSWORD` | Secrets | ja | FTP-Zugang (TLS wird erzwungen) |
| `DB_USER`, `DB_PASS` | Secrets | ja | Datenbank |
| `DB_NAME` | Secret | | Standard ist `DB_USER` |
| `DB_HOST`, `DB_PORT`, `DB_PREFIX` | Variablen | | Standard `localhost`, `3306`, `tp_` |
| `APP_SECRET` | Secret | ja | mindestens 32 Zeichen |
| `INSTALL_KEY` | Secret | zur Installation | zusammen mit der Variable `ALLOW_INSTALL=1` |
| `APP_NAME` | Variable | | Name, der Eltern und in E-Mails angezeigt wird |
| `LANGUAGES`, `DEFAULT_LANGUAGE`, `ADMIN_LANGUAGE` | Variablen | | siehe [Konfiguration]({{ '/de/guide/configuration/' | relative_url }}) |
| `HOSTING_DE`, `HOSTING_EN` | Variablen | | Hoster für die Datenschutzerklärung |
| `MAIL_FROM`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO`, `ALERT_EMAIL` | Variablen | | E-Mail-Einstellungen |
| `MAIL_TRANSPORT`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` | Variablen | | für SMTP |
| `SMTP_USER`, `SMTP_PASS` | Secrets | | für SMTP |
| `CONFIG_MODE` | Variable | | Dateirechte von `config.php` auf dem Server: `600` (Standard), `640` oder `keep`; Rechte, die andere lesen lassen, werden abgelehnt; siehe unten |

### Dateirechte von config.php {#permissions-of-configphp}

`config.php` enthält das Datenbank- und das SMTP-Passwort sowie `APP_SECRET`. Beim Hochladen per FTP
bekommt jede Datei die Standardrechte des Servers, oft `644`: Auf einem geteilten Server könnte dann jeder
andere Benutzer sie lesen. Das Deployment führt deshalb direkt nach dem Hochladen des Codes `chmod 600` auf
die `config.php` der neuen Version aus. Hat die neue Version die Prüfung bestanden (PHP kann eine Datei mit
diesen Rechten lesen), bekommen auch die älteren Versionen auf dem Server diese Rechte: Sie enthalten
dieselben Secrets.

- Unterstützt Ihr FTP-Server kein `chmod`, zeigt der Lauf eine Warnung und macht weiter. Jedes Deployment
  lädt dann eine neue Kopie mit den Standardrechten hoch. Setzen Sie deshalb nach jedem Deployment die
  Rechte von `<APP_DIR>/releases/<name>/config/config.php` im Dateimanager Ihres Hosters, für jede Version
  auf dem Server. Die Übersicht von TalkSlot warnt die Admins und nennt jede Kopie, die alle Benutzer des
  Servers lesen können.
- Läuft PHP unter einem anderen Benutzer als Ihr FTP-Zugang (bei Shared Hosting selten), kann PHP eine
  Datei mit `600` nicht lesen. Die Prüfung nach dem Umschalten schlägt dann mit HTTP 500 fehl, und die
  vorherige Version bleibt aktiv. Setzen Sie die Variable `CONFIG_MODE` auf `640` (PHP liest die Datei über
  seine Gruppe) oder, falls auch das nicht reicht, auf `keep` (Rechte bestimmt der Server):
  `gh variable set CONFIG_MODE --body 640`. `640` schützt die Datei nur, wenn kein anderer Kunde in ihrer
  Gruppe ist (manche Hoster stecken alle Kunden in eine Gruppe oder lassen PHP für alle unter demselben
  Benutzer laufen). Die Übersicht kann das nicht erkennen; fragen Sie Ihren Hoster.

**Update von einer Version ohne `CONFIG_MODE`:** Bis dahin hatte `config.php` in jeder Version auf dem
Server die Standardrechte. Das erste Deployment nach dem Update schränkt sie in allen Versionen ein. Konnten
andere Benutzer des Servers die Datei bis dahin lesen (fragen Sie Ihren Hoster, wie die Kunden voneinander
getrennt sind), [tauschen Sie die Secrets aus]({{ '/de/guide/operations/' | relative_url }}#changing-secrets).

## Rollback

*Actions → Switch version → Run workflow* mit einer dieser Eingaben:

| Eingabe | Wirkung |
|---|---|
| `previous` | eine Version zurück (Standard) |
| `list` | nur die Versionen auf dem Server anzeigen |
| ein Versionsname | genau diese Version, z. B. `20261102-153012-v1.1.0` |
| `prune` | alle Versionen außer der aktiven löschen, z. B. nach dem [Austausch von Secrets]({{ '/de/guide/operations/' | relative_url }}#changing-secrets); danach ist kein Rollback mehr möglich |

```bash
gh workflow run "Switch version"                      # eine Version zurück
gh workflow run "Switch version" -f target=list       # verfügbare Versionen anzeigen
gh workflow run "Switch version" -f target=prune      # nur die aktive Version behalten
```

Auch nach dem Umschalten wird die Seite geprüft. Der nächste Push auf `main` spielt wieder den neuesten
Code aus.

Die Datenbank wird **nicht** zurückgesetzt. Deshalb fügen Migrationen immer nur Spalten (mit
Standardwerten) und Tabellen hinzu: Eine ältere Version funktioniert auch mit einem neueren Schema.

## Deployment aus einem eigenen Repository {#deploying-from-your-own-repository}

Sie brauchen keinen Fork. Ein kleines privates Repository, das nur die Konfiguration enthält, kann die
wiederverwendbaren Workflows mit einer festen TalkSlot-Version aufrufen. Aktualisieren heißt dann, die
Versionsnummer zu ändern.

```yaml
# .github/workflows/deploy.yml in Ihrem Repository
name: Deploy
on:
  push:
    branches: [main]
  workflow_dispatch:
permissions:
  contents: read
jobs:
  deploy:
    uses: skornehl/talkslot/.github/workflows/deploy.yml@v1.2.0
    secrets: inherit
```

{% raw %}
```yaml
# .github/workflows/switch.yml in Ihrem Repository
name: Switch version
on:
  workflow_dispatch:
    inputs:
      target:
        description: 'previous, list, prune or version name'
        required: true
        default: previous
permissions:
  contents: read
jobs:
  switch:
    uses: skornehl/talkslot/.github/workflows/switch.yml@v1.2.0
    with:
      target: ${{ inputs.target }}
    secrets: inherit
```
{% endraw %}


Die Workflows holen den TalkSlot-Code genau der Version aus der `uses:`-Zeile. Für ein Update ändern Sie
diese Version in beiden Dateien. Diese Pull Requests kann Dependabot für Sie öffnen, mit
`.github/dependabot.yml`:

```yaml
version: 2
updates:
  - package-ecosystem: github-actions
    directory: /
    schedule:
      interval: weekly
```

Variablen und Secrets liegen in Ihrem Repository (oder in dessen Environment `production`). Für den
stündlichen Cronjob kopieren Sie
[`cron.yml`](https://github.com/skornehl/talkslot/blob/main/.github/workflows/cron.yml).

## Releases

*Actions → Release → Run workflow* auf `main` setzt den Tag für die nächste Version anhand der
[Conventional Commits](https://www.conventionalcommits.org/) seit dem letzten Tag (`feat` → Minor,
`!` → Major, alles andere → Patch) und erstellt ein GitHub-Release mit Release Notes. Die laufende Version
steht in der Fußzeile jeder Seite.

## Ohne GitHub

`bin/build.sh` und `bin/deploy.sh` sind einfache Shell-Skripte (bash, lftp, curl) und funktionieren aus jeder
CI oder von Ihrem eigenen Rechner. Rufen Sie `bin/deploy.sh` ohne Argumente auf, um die Verwendung zu sehen.
