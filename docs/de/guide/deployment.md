---
title: Deployment mit GitHub Actions
description: Deployments ohne Ausfallzeit auf Shared Hosting per FTPS, mit automatischen Prüfungen und Rollback per Klick.
---

TalkSlot bringt Workflows mit, die jeden Commit auf `main` testen, bauen und per FTPS hochladen, sobald alle
seine Tests bestanden sind. Sie sind für Shared Hosting (Webspace) gedacht, bei dem Sie nur FTP-Zugang haben.
Alles ist optional: Ohne die Variable `FTP_HOST` tun die Workflows für Deployment, Cronjob und Rollback nichts.

## So läuft ein Deployment ab

1. **Nur getesteter Code.** Ein Push auf `main` startet zuerst den Workflow *Tests* (Unit-, Smoke-, Browser-
   und Deployment-Tests, auf der aktuellen und auf der Mindestplattform). *Deploy* beginnt erst, wenn dieser
   Lauf fertig ist, und nur, wenn er genau für diesen Commit bestanden ist; sonst geht nichts live. Siehe
   [Deploy-Gate](#deploy-gate).
2. **Testen und bauen.** Lint (auf PHP 8.3, der Mindestversion, und 8.5) und die Unit-Tests laufen noch einmal
   in einem Job ohne Secrets. Erst danach, und nur auf `main`, erstellt `bin/build.sh` das Paket und
   `bin/build-config.php` erzeugt `config.php` aus Ihren Variablen und Secrets. Fehlende oder ungültige
   Werte brechen den Lauf ab, bevor etwas hochgeladen wird.
3. **Hochladen neben die laufende Version.** Die neue Version kommt in ein eigenes Verzeichnis
   (`<APP_DIR>/releases/<name>/`, Assets nach `<WEB_DIR>/v/<name>/`). Die Live-Seite bleibt unberührt.
   Direkt nach dem Code wird ihre `config.php` nur für den Eigentümer lesbar gemacht
   ([Dateirechte](#permissions-of-configphp)). Anschließend wird jede Datei auf dem Server geprüft.
4. **Umschalten in einem Schritt.** Die Zeigerdatei `<APP_DIR>/current` wird ersetzt (als `current.new`
   hochgeladen, dann umbenannt). Ab diesem Moment beantwortet die neue Version jede Anfrage. Alter und neuer
   Code laufen nie gleichzeitig und können daher keine inkonsistenten Daten schreiben.
5. **Prüfen.** Login-Seite, Startseite und das CSS der neuen Version werden abgerufen, und der
   [Health-Check](#health-check) der neuen Version muss bestätigen, dass sie mit der Datenbank arbeitet:
   erreichbar, MariaDB 10.6 oder neuer, alle Migrationen ausgeführt. Die Seiten allein funktionieren auch
   ohne Datenbank; ein falsches Datenbank-Passwort oder eine fehlgeschlagene Migration fällt daher nur dem
   Health-Check auf. Schlägt etwas fehl, schaltet der Workflow sofort auf die vorherige Version zurück,
   löscht die defekte und schlägt fehl.
6. **Aufräumen.** Die fünf neuesten Versionen bleiben für Rollbacks auf dem Server; ihre `config.php`
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
4. Pushen Sie auf `main` (das Deployment folgt, sobald die Tests bestanden sind) oder starten Sie
   *Actions → Deploy → Run workflow*. Öffnen Sie dann `/install.php`, legen Sie das Admin-Konto an und führen
   Sie `gh variable delete ALLOW_INSTALL` aus, damit der Installer nicht mehr ausgeliefert wird.

### Deploy-Gate {#deploy-gate}

- **Ein Push auf `main`** wird erst ausgespielt, wenn der Workflow *Tests* für diesen Commit bestanden ist
  (`deploy.yml` startet per `workflow_run`). Ist `main` inzwischen weiter, wird der neuere Commit ausgespielt,
  sobald seine eigenen Tests bestanden sind; scheitern sie, bleibt die Seite auf der Version, die gerade läuft.
- **Run workflow** (*Actions → Deploy*) spielt das aktuelle `main` sofort aus, ohne auf die Tests zu warten,
  etwa nach dem Ändern eines Secrets. Lint und Unit-Tests laufen trotzdem vorher.
- **Releases** (*Actions → Release*) entstehen nur für einen Commit, für den *Tests* bestanden ist. Ein
  [Repository nur mit Konfiguration](#deploying-from-your-own-repository), das ein Release ausspielt, bekommt
  also nur getesteten Code.
- **Branch-Schutz** stellen Sie im Repository ein (*Settings → Branches*: Pull Request und die Statuschecks von
  *Tests* für `main` verlangen). Er entscheidet, ob eine ungetestete Änderung überhaupt auf `main` kommt; das
  Gate oben entscheidet, ob sie live geht.
- **Festgelegte Werkzeuge.** Die Workflows nutzen keine Actions von Dritten (der Code wird per `git` geholt).
  Die Docker-Images der Tests und der Prüfung vor dem Deployment sind in `dev/Dockerfile` und
  `dev/php-cli.Dockerfile` per Digest festgelegt, die mitgelieferten PHPMailer-Dateien über ihre
  SHA-256-Prüfsummen (`app/vendor/phpmailer/SHA256SUMS`); Dependabot schlägt für beides Updates vor.

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
| `DB_SSL_CA` | Variable | | CA-Zertifikat für eine verschlüsselte Datenbankverbindung, ein absoluter Pfad auf dem Webserver ([`db.ssl_ca`]({{ '/de/guide/configuration/' | relative_url }}#database-tls)) |
| `APP_SECRET` | Secret | ja | mindestens 32 Zeichen; auch der Schlüssel für den [Health-Check](#health-check) |
| `INSTALL_KEY` | Secret | zur Installation | zusammen mit der Variable `ALLOW_INSTALL=1` |
| `APP_NAME` | Variable | | Name, der Eltern und in E-Mails angezeigt wird |
| `LANGUAGES`, `DEFAULT_LANGUAGE`, `ADMIN_LANGUAGE` | Variablen | | siehe [Konfiguration]({{ '/de/guide/configuration/' | relative_url }}) |
| `HOSTING_DE`, `HOSTING_EN` | Variablen | | Hoster für die Datenschutzerklärung |
| `MAIL_FROM`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO`, `ALERT_EMAIL` | Variablen | | E-Mail-Einstellungen |
| `SUMMARY_NAMES`, `ALLOWED_ACCOUNT_DOMAINS` | Variablen | | Namen in den Zusammenfassungen (`1`), Domains für die Adressen der Konten (durch Kommas getrennt); siehe [Konfiguration]({{ '/de/guide/configuration/' | relative_url }}#zusammenfassungen-und-konten) |
| `CRON_ALERT_HOURS` | Variable | | Stunden ohne vollständigen Cron-Lauf, bis Admins gewarnt werden (Standard 12, `0` = aus; siehe [Überwachung des Cronjobs]({{ '/de/guide/operations/' | relative_url }}#cron-monitoring)) |
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

### Health-Check {#health-check}

Nach dem Umschalten sendet das Deployment `POST /health` mit dem Header `X-Health-Token`. Das Token ist der
hexadezimale HMAC-SHA256 des Worts `health` mit `APP_SECRET`, wie beim
[Web-Cron]({{ '/de/guide/installation/' | relative_url }}); ein weiteres Secret ist also nicht nötig. Mit
einem falschen Token oder per `GET` antwortet die Adresse wie jede unbekannte Seite mit 404; eine Anfrage
ohne den Header gilt als gewöhnliche Seitenadresse. Die Anfrage führt ausstehende Migrationen aus; schlägt
eine fehl, wird das per Fehlermeldung gemeldet und lässt die Prüfung scheitern, statt übergangen zu
werden. Die Antwort enthält nur einen Status:

| Status | HTTP | Bedeutung |
|---|---|---|
| `ok` | 200 | Datenbank erreichbar, alle Migrationen ausgeführt |
| `not_installed` | 200 | leere Datenbank: Der Installer ist noch nicht gelaufen (erstes Deployment) |
| `db_unreachable` | 503 | keine Verbindung: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` (und `DB_SSL_CA`, falls gesetzt) prüfen |
| `db_unsupported` | 503 | nicht MariaDB 10.6 oder neuer (MySQL wird nicht unterstützt) |
| `migration_failed` | 503 | eine Migration ist fehlgeschlagen; Details in der Fehlermeldung per E-Mail und in `app/var/php-error.log` |
| `schema_outdated` | 503 | die Migrationen sind noch nicht durch, z. B. weil eine andere Anfrage sie gerade ausführt (`Retry-After`) |

Die Prüfung versucht es sechsmal im Abstand von fünf Sekunden, bevor sie zurückschaltet: etwa eine halbe
Minute, bis zu anderthalb Minuten, wenn die Datenbank nicht antwortet oder eine Migration noch läuft. Die
Datenbank wird nicht zurückgesetzt; weil Migrationen nur Spalten und Tabellen hinzufügen, funktioniert die
vorherige Version weiter (siehe [Rollback](#rollback)). Das gilt auch für eine Migration, die länger
dauert als die Prüfung: Sie läuft nach dem Zurückschalten zu Ende, und die vorherige Version arbeitet mit
dem neueren Schema. Das Protokoll des Laufs nennt den Status und was zu prüfen ist. So fragen Sie den
Health-Check selbst ab:

```bash
TOKEN=$(printf health | openssl dgst -sha256 -hmac "$APP_SECRET" | sed 's/.*= //')
curl -sS -d '' -H "X-Health-Token: $TOKEN" https://your-site/health
```

**Update von einer Version ohne Health-Check:** Es ist nichts einzurichten. Die Workflows geben das
vorhandene Secret `APP_SECRET` an die Prüfung weiter; ein
[Repository nur mit Konfiguration](#deploying-from-your-own-repository) mit `secrets: inherit` reicht es
von selbst durch. Kommt es nicht an (z. B. weil Ihr Workflow seine Secrets einzeln ohne `APP_SECRET`
aufzählt), warnt der Lauf und prüft nur die Seiten. Versionen, die vor dem Update hochgeladen wurden,
haben keinen Health-Check: Beim Zurückschalten auf eine davon werden nur ihre Seiten geprüft, mit einer
Warnung. Außerdem:

- **Datenbankversion:** MySQL und MariaDB 10.5 oder älter (z. B. das MariaDB von Debian 11) wurden nie
  unterstützt. Das Deployment bricht jetzt mit `db_unsupported` ab, statt bei einer späteren Migration
  kaputtzugehen, bis der Datenbankserver aktualisiert ist (MariaDB 10.5 bekommt seit Mitte 2025 keine
  Updates mehr; fragen Sie Ihren Hoster). MariaDB Enterprise Server wird erkannt. Ein Datenbank-Proxy, der
  `VERSION()` mit seiner eigenen Version beantwortet (ProxySQL in der Grundeinstellung), führt ebenfalls zu
  `db_unsupported`; lassen Sie ihn die Version des Servers weitergeben.
- **Eine Runde unter `/health` oder `/cron`:** Neue Runden bekommen diese Adressen nicht mehr. Eine Runde,
  die eine davon schon hat, behält sie, weil nur Anfragen mit dem Schlüssel-Header den Health-Check oder
  den Web-Cron erreichen.

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

Auch nach dem Umschalten wird die Seite geprüft, einschließlich des [Health-Checks](#health-check) der
Zielversion (bei Versionen von davor nur die Seiten). Der nächste Commit auf `main`, dessen Tests bestanden
sind, spielt wieder den neuesten Code aus.

Die Datenbank wird **nicht** zurückgesetzt. Deshalb fügen Migrationen immer nur Spalten (mit
Standardwerten) und Tabellen hinzu: Eine ältere Version funktioniert auch mit einem neueren Schema.

## Deployment aus einem eigenen Repository {#deploying-from-your-own-repository}

Sie brauchen keinen Fork. Ein kleines privates Repository, das nur die Konfiguration enthält, kann die
wiederverwendbaren Workflows mit einer festen TalkSlot-Version aufrufen. Aktualisieren heißt dann, die
Version zu ändern.

Legen Sie die Version über den **Commit-SHA des Releases** fest, mit der Version als Kommentar: Ein Tag lässt
sich verschieben, ein Commit nicht. Sie finden ihn mit
`git ls-remote https://github.com/skornehl/talkslot refs/tags/v1.9.0`. Übergeben Sie die Secrets, die der
Workflow braucht, **einzeln** statt mit `secrets: inherit`, damit er keine anderen bekommt. Secrets im
Environment `production` liest er dort direkt. TalkSlot-Versionen, die nach dem 5. November 2026
veröffentlicht wurden, nehmen das an; bei einer älteren Version bleiben Sie bei `secrets: inherit`.

{% raw %}
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
    uses: skornehl/talkslot/.github/workflows/deploy.yml@<Commit-SHA des Releases> # v1.9.0
    secrets:
      APP_SECRET: ${{ secrets.APP_SECRET }}
      INSTALL_KEY: ${{ secrets.INSTALL_KEY }}
      DB_NAME: ${{ secrets.DB_NAME }}
      DB_USER: ${{ secrets.DB_USER }}
      DB_PASS: ${{ secrets.DB_PASS }}
      SMTP_USER: ${{ secrets.SMTP_USER }}
      SMTP_PASS: ${{ secrets.SMTP_PASS }}
      FTP_USERNAME: ${{ secrets.FTP_USERNAME }}
      FTP_PASSWORD: ${{ secrets.FTP_PASSWORD }}
```
{% endraw %}

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
    uses: skornehl/talkslot/.github/workflows/switch.yml@<Commit-SHA des Releases> # v1.9.0
    with:
      target: ${{ inputs.target }}
    secrets:
      APP_SECRET: ${{ secrets.APP_SECRET }}
      FTP_USERNAME: ${{ secrets.FTP_USERNAME }}
      FTP_PASSWORD: ${{ secrets.FTP_PASSWORD }}
```
{% endraw %}


Die Workflows holen den TalkSlot-Code genau der Version aus der `uses:`-Zeile. Für ein Update ändern Sie
diesen Commit und seinen Kommentar in beiden Dateien. Releases gibt es nur für Commits, deren Tests bestanden
sind. Diese Pull Requests kann Dependabot für Sie öffnen (es ändert Commit und Versionskommentar), mit
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
[`cron.yml`](https://github.com/skornehl/talkslot/blob/main/.github/workflows/cron.yml). Ist das
Repository öffentlich, schaltet GitHub den Zeitplan nach 60 Tagen ohne Aktivität darin ab; TalkSlot warnt
Sie dann (siehe [Überwachung des Cronjobs]({{ '/de/guide/operations/' | relative_url }}#github-60-days)).

## Releases

*Actions → Release → Run workflow* auf `main` setzt den Tag für die nächste Version anhand der
[Conventional Commits](https://www.conventionalcommits.org/) seit dem letzten Tag (`feat` → Minor,
`!` → Major, alles andere → Patch) und erstellt ein GitHub-Release mit Release Notes. Die laufende Version
steht in der Fußzeile jeder Seite.

## Ohne GitHub

`bin/build.sh` und `bin/deploy.sh` sind einfache Shell-Skripte (bash, lftp, curl) und funktionieren aus jeder
CI oder von Ihrem eigenen Rechner. Rufen Sie `bin/deploy.sh` ohne Argumente auf, um die Verwendung zu sehen.
Setzen Sie `APP_SECRET` in der Umgebung von `bin/deploy.sh activate` und `switch`, damit die Prüfung den
[Health-Check](#health-check) einschließt (braucht `openssl`); ohne wird nur die Seite geprüft. `openssl`
bekommt `APP_SECRET` kurz als Argument, andere Benutzer desselben Rechners könnten es also in der
Prozessliste sehen: Deployen Sie von einem Rechner, den nur Sie nutzen, oder von einem CI-Runner aus.
