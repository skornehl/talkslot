---
title: Deployment with GitHub Actions
description: Zero-downtime deployments to shared hosting via FTPS, with automatic checks and one-click rollback.
---

TalkSlot ships with workflows that test, build and upload it via FTPS on every push to `main`. They are
made for shared hosting where you only have FTP access. Everything is optional: without the variable
`FTP_HOST` the deploy, cron and rollback workflows do nothing.

## How a deployment works

1. **Test and build.** Lint and unit tests run, then `bin/build.sh` creates the package and
   `bin/build-config.php` generates `config.php` from your variables and secrets. Missing or invalid
   values stop the run before anything is uploaded.
2. **Upload next to the running version.** The new version goes into its own directory
   (`<APP_DIR>/releases/<name>/`, assets into `<WEB_DIR>/v/<name>/`). The live site is untouched.
   Right after the code, its `config.php` is made readable for its owner only
   ([permissions](#permissions-of-configphp)). Afterwards every file is checked on the server.
3. **Switch in one step.** The pointer file `<APP_DIR>/current` is replaced (uploaded as `current.new`,
   then renamed). From that moment every request is served by the new version. Old and new code never
   run side by side, so they cannot write inconsistent data.
4. **Check.** The login page, start page and the new version's CSS are fetched, and the new version's
   [health check](#health-check) has to confirm that it works with the database: reachable, MariaDB 10.6
   or newer, every migration applied. The pages alone work without the database, so only the health check
   catches a wrong database password or a failed migration. If anything fails, the workflow switches
   back to the previous version at once, deletes the broken one and fails.
5. **Clean up.** The five newest versions stay on the server for rollbacks; their `config.php` gets the
   same permissions as the new one.

```
<APP_DIR>/current            name of the active version, e.g. 20261102-153012-v1.1.0
<APP_DIR>/releases/<name>/   app/, bin/, config/config.php of one version
<APP_DIR>/app/var/           sessions, logs (shared by all versions)
<WEB_DIR>/                   index.php, .htaccess, app_root.php (document root)
<WEB_DIR>/v/<name>/          CSS, JS and images of one version (cached for a year)
```

Sessions live in the shared `app/var/`, so nobody is logged out by a deployment.

## Setup

1. Fork the repository, or create your own [configuration-only repository](#deploying-from-your-own-repository).
2. Run the setup script with the [GitHub CLI](https://cli.github.com/):
   ```bash
   bin/setup-secrets.sh
   ```
   It asks for the site address, FTP host and directories, database, languages and web host, and
   generates `APP_SECRET` and `INSTALL_KEY`.
3. Recommended: create an environment `production` (*Settings → Environments*), allow only the `main`
   branch and store the secrets there.
4. Push to `main` or start *Actions → Deploy → Run workflow*. Then open `/install.php`, create the admin
   account and run `gh variable delete ALLOW_INSTALL` so the installer is no longer shipped.

### Variables and secrets

| Name | Type | Required | Meaning |
|---|---|---|---|
| `BASE_URL` | variable | yes | public address of the site |
| `FTP_HOST` | variable | yes | FTP server; enables the workflows |
| `WEB_DIR` | variable | yes | document root, relative to the FTP root |
| `APP_DIR` | variable | | app directory outside the web root (default `talkslot-app`) |
| `FTP_USERNAME`, `FTP_PASSWORD` | secrets | yes | FTP access (TLS is enforced) |
| `DB_USER`, `DB_PASS` | secrets | yes | database |
| `DB_NAME` | secret | | defaults to `DB_USER` |
| `DB_HOST`, `DB_PORT`, `DB_PREFIX` | variables | | defaults `localhost`, `3306`, `tp_` |
| `APP_SECRET` | secret | yes | at least 32 characters; also the key of the [health check](#health-check) |
| `INSTALL_KEY` | secret | for installing | together with variable `ALLOW_INSTALL=1` |
| `APP_NAME` | variable | | name shown to parents and in mails |
| `LANGUAGES`, `DEFAULT_LANGUAGE`, `ADMIN_LANGUAGE` | variables | | see [Configuration]({{ '/guide/configuration/' | relative_url }}) |
| `HOSTING_DE`, `HOSTING_EN` | variables | | web host for the privacy policy |
| `MAIL_FROM`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO`, `ALERT_EMAIL` | variables | | mail settings |
| `MAIL_TRANSPORT`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` | variables | | for SMTP |
| `SMTP_USER`, `SMTP_PASS` | secrets | | for SMTP |
| `CONFIG_MODE` | variable | | permissions of `config.php` on the server: `600` (default), `640` or `keep`; modes readable for others are rejected; see below |

### Permissions of config.php {#permissions-of-configphp}

`config.php` contains the database and SMTP passwords and `APP_SECRET`. An FTP upload gives every file
the server's default permissions, often `644`: on a shared server every other user could read it. The
deployment therefore runs `chmod 600` on the new version's `config.php` right after uploading its code.
Once the new version has passed the check (PHP can read a file with these permissions), the older
versions on the server get them too: they contain the same secrets.

- If your FTP server does not support `chmod`, the run shows a warning and goes on. Every deployment then
  uploads a new copy with the default permissions, so after each one set the permissions of
  `<APP_DIR>/releases/<name>/config/config.php` in your web host's file manager, for every version on the
  server. The TalkSlot dashboard warns admins and lists each copy that every user of the server can read.
- If PHP runs as a different user than your FTP account (rare on shared hosting), PHP cannot read a file
  with `600`. The check after switching then fails with HTTP 500 and the previous version stays active.
  Set the variable `CONFIG_MODE` to `640` (PHP reads the file through its group) or, if that is not enough
  either, to `keep` (leave the permissions to the server): `gh variable set CONFIG_MODE --body 640`.
  `640` only protects the file if no other customer is in its group (some hosts put all customers in one
  group, or run PHP for everyone as the same user); the dashboard cannot detect that, so ask your web host.

**Upgrading from a version without `CONFIG_MODE`:** until then `config.php` kept the default permissions
in every version on the server. The first deployment after the update restricts all of them. If other
users of the server could read the file until then (ask your web host how customers are separated),
[change the secrets]({{ '/guide/operations/' | relative_url }}#changing-secrets).

### Health check {#health-check}

After switching, the deployment sends `POST /health` with the header `X-Health-Token`. The token is the
hex-encoded HMAC-SHA256 of the word `health` with `APP_SECRET`, like the
[web cron's]({{ '/guide/installation/' | relative_url }}), so there is no further secret to set up. With a
wrong token, or with `GET`, the address answers 404 like any unknown page; a request without the header is
treated as an ordinary page address. The request runs pending migrations; one that fails is reported by
alert mail and fails the check instead of being ignored. The answer contains nothing but a status:

| Status | HTTP | Meaning |
|---|---|---|
| `ok` | 200 | database reachable, every migration applied |
| `not_installed` | 200 | empty database: the installer has not run yet (first deployment) |
| `db_unreachable` | 503 | no connection: check `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| `db_unsupported` | 503 | not MariaDB 10.6 or newer (MySQL is not supported) |
| `migration_failed` | 503 | a migration failed; the alert mail and `app/var/php-error.log` have the details |
| `schema_outdated` | 503 | the migrations are not through yet, e.g. another request is still running them (`Retry-After`) |

The check tries six times, five seconds apart, before it switches back: about half a minute, up to a
minute and a half if the database does not answer or a migration is still running. The database is not
rolled back; since migrations only add columns and tables, the previous version keeps working (see
[Rollback](#rollback)). That also holds for a migration that takes longer than the check: it finishes
after the switch-back, and the previous version runs on the newer schema. The run log names the status
and what to check. To ask the health check yourself:

```bash
TOKEN=$(printf health | openssl dgst -sha256 -hmac "$APP_SECRET" | sed 's/.*= //')
curl -sS -d '' -H "X-Health-Token: $TOKEN" https://your-site/health
```

**Upgrading from a version without the health check:** nothing to set up. The workflows hand the existing
secret `APP_SECRET` to the check; a [configuration-only repository](#deploying-from-your-own-repository)
with `secrets: inherit` passes it on by itself. If it does not arrive (for example because your workflow
lists its secrets one by one without `APP_SECRET`), the run warns and checks the pages only. Versions
uploaded before the update have no health check: switching back to one of them checks its pages only,
with a warning. Also note:

- **Database version:** MySQL and MariaDB 10.5 or older (for example the MariaDB of Debian 11) have never
  been supported. Deployments now stop with `db_unsupported` instead of breaking at a later migration,
  until the database server is updated (MariaDB 10.5 has had no updates since mid-2025; ask your web
  host). MariaDB Enterprise Server is recognised. A database proxy that answers `VERSION()` with its own
  version (ProxySQL does by default) also leads to `db_unsupported`; let it pass on the server's version.
- **A round at `/health` or `/cron`:** new rounds no longer get these addresses. A round that already has
  one keeps it, because only requests with the key header reach the health check or the web cron.

## Rollback

*Actions → Switch version → Run workflow* with one of:

| Input | Effect |
|---|---|
| `previous` | one version back (default) |
| `list` | only show the versions on the server |
| a version name | exactly this version, e.g. `20261102-153012-v1.1.0` |
| `prune` | delete all versions except the active one, e.g. after [changing secrets]({{ '/guide/operations/' | relative_url }}#changing-secrets); no rollback afterwards |

```bash
gh workflow run "Switch version"                      # one version back
gh workflow run "Switch version" -f target=list       # show available versions
gh workflow run "Switch version" -f target=prune      # keep only the active version
```

The site is checked after switching as well, including the [health check](#health-check) of the
target version (versions from before it: pages only). The next push to `main` deploys the newest code
again.

The database is **not** rolled back. That is why migrations only ever add columns (with defaults) and
tables: an older version keeps working with a newer schema.

## Deploying from your own repository

You do not need a fork. A small private repository with nothing but configuration can call the
reusable workflows with a pinned TalkSlot version. Updating means changing the version number.

```yaml
# .github/workflows/deploy.yml in your repository
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
# .github/workflows/switch.yml in your repository
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


The workflows fetch the TalkSlot code of exactly the version in the `uses:` line. To update, change that
version in both files. Dependabot can open these pull requests for you with `.github/dependabot.yml`:

```yaml
version: 2
updates:
  - package-ecosystem: github-actions
    directory: /
    schedule:
      interval: weekly
```

Variables and secrets live in your repository (or its `production` environment). For the hourly cron
job copy [`cron.yml`](https://github.com/skornehl/talkslot/blob/main/.github/workflows/cron.yml). If the
repository is public, GitHub disables the schedule after 60 days without activity in it; TalkSlot then
warns you (see [cron monitoring]({{ '/guide/operations/' | relative_url }}#github-60-days)).

## Releases

*Actions → Release → Run workflow* on `main` tags the next version from the
[Conventional Commits](https://www.conventionalcommits.org/) since the last tag (`feat` → minor,
`!` → major, everything else → patch) and creates a GitHub release with notes. The running version is
shown in the footer of every page.

## Without GitHub

`bin/build.sh` and `bin/deploy.sh` are plain shell scripts (bash, lftp, curl) and work from any CI or your
own computer. Run `bin/deploy.sh` without arguments for its usage. Set `APP_SECRET` in the environment of
`bin/deploy.sh activate` and `switch` so that the check includes the [health check](#health-check) (needs
`openssl`); without it only the pages are checked. `openssl` gets `APP_SECRET` as an argument for a
moment, so other users of the same computer could see it in the process list: deploy from a computer
that only you use, or from a CI runner.
