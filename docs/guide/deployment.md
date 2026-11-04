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
   Afterwards every file is checked on the server.
3. **Switch in one step.** The pointer file `<APP_DIR>/current` is replaced (uploaded as `current.new`,
   then renamed). From that moment every request is served by the new version. Old and new code never
   run side by side, so they cannot write inconsistent data.
4. **Check.** The login page, start page and the new version's CSS are fetched. If that fails, the
   workflow switches back to the previous version at once, deletes the broken one and fails.
5. **Clean up.** The five newest versions stay on the server for rollbacks.

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
| `APP_SECRET` | secret | yes | at least 32 characters |
| `INSTALL_KEY` | secret | for installing | together with variable `ALLOW_INSTALL=1` |
| `APP_NAME` | variable | | name shown to parents and in mails |
| `LANGUAGES`, `DEFAULT_LANGUAGE`, `ADMIN_LANGUAGE` | variables | | see [Configuration]({{ '/guide/configuration/' | relative_url }}) |
| `HOSTING_DE`, `HOSTING_EN` | variables | | web host for the privacy policy |
| `MAIL_FROM`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO`, `ALERT_EMAIL` | variables | | mail settings |
| `MAIL_TRANSPORT`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` | variables | | for SMTP |
| `SMTP_USER`, `SMTP_PASS` | secrets | | for SMTP |

## Rollback

*Actions → Switch version → Run workflow* with one of:

| Input | Effect |
|---|---|
| `previous` | one version back (default) |
| `list` | only show the versions on the server |
| a version name | exactly this version, e.g. `20261102-153012-v1.1.0` |

```bash
gh workflow run "Switch version"                      # one version back
gh workflow run "Switch version" -f target=list       # show available versions
```

The site is checked after switching as well. The next push to `main` deploys the newest code again.

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
        description: 'previous, list or version name'
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
job copy [`cron.yml`](https://github.com/skornehl/talkslot/blob/main/.github/workflows/cron.yml).

## Releases

*Actions → Release → Run workflow* on `main` tags the next version from the
[Conventional Commits](https://www.conventionalcommits.org/) since the last tag (`feat` → minor,
`!` → major, everything else → patch) and creates a GitHub release with notes. The running version is
shown in the footer of every page.

## Without GitHub

`bin/build.sh` and `bin/deploy.sh` are plain shell scripts (bash, lftp, curl) and work from any CI or your
own computer. Run `bin/deploy.sh` without arguments for its usage.
