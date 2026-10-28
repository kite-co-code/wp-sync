# `wp sync`

[![Packagist Version](https://img.shields.io/packagist/v/kite-co/wp-sync)](https://packagist.org/packages/kite-co/wp-sync)
[![License](https://img.shields.io/packagist/l/kite-co/wp-sync)](LICENSE)

**Pull a live WordPress site to your local machine in one command — database, plugins, themes and uploads. Push back when you need to.**

```bash
wp sync pull production
```

`wp sync` is a [WP-CLI](https://wp-cli.org/) package. It works over SSH and rsync, so there's no plugin to install on your sites — if you've used [WP Migrate](https://deliciousbrains.com/wp-migrate-db-pro/), it's the same idea from the command line. It also handles full migrations: pull a site from its old server, then push it to a fresh WordPress install on the new one.

[Quick start](#quick-start) | [Installation](#installation) | [Configuration](#configuration) | [Commands](#commands) | [Options](#options) | [Hooks](#custom-command-hooks) | [Multisite](#wordpress-multisite)

## What it does

- **Copies the database** and rewrites URLs for the new domain automatically, multisite networks included.
- **Backs up first.** The database about to be overwritten is saved locally before every sync.
- **Copies files** — themes, plugins, mu-plugins and uploads — with rsync, honouring a `.deployignore` file.
- **Skips the uploads download** if you like: local media can load straight from the remote site instead.
- **Runs your own commands** before and after each sync, e.g. clear caches or toggle maintenance mode.
- **Shows you the plan and asks before it touches anything.**

## What it looks like

```
$ wp sync pull production

━━━ Sync Configuration ━━━

environment: production

sync:
  db: true ✓
  themes: false ✗
  plugins: true ✓
  uploads: false ✗

connection:
  host: example-prod
  path: /var/www/example.com

━━━ Domain Configuration ━━━
local_domain:  https://example.test
remote_domain: https://example.com

⚠️  This will overwrite your LOCAL environment
   • Local database will be replaced with remote database
   • Local plugins will be replaced with remote files
   • This action cannot be undone without a backup

Continue with pull from 'production' to 'local'? [y/n] y
Backing up local database…
Success: Database backup saved to wp-content/wp-sync-backups/wp_sync_backup_local_20261028_143000.sql

━━━ Transferring Files ━━━
• Syncing plugins folder...
• Syncing mu-plugins folder...

━━━ Syncing Database ━━━
• Exporting database from production...
• Importing database...
• Search-replacing URLs...

━━━ Configuring Remote Media Loading ━━━
• Installing BE Media from Production plugin...
Success: ✓ Configured media from remote: https://example.test -> https://example.com

━━━ Finalizing Sync ━━━
• Flushing rewrite rules...

Success: 🎉 Pull from 'production' to 'local' completed successfully!
```

## Quick start

1. **Install the package** (once per machine):
   ```bash
   wp package install https://github.com/kite-co-code/wp-sync.git
   ```

2. **Create a config file** in your project folder — this can be your WordPress root or your theme, as long as you run the command from there:
   ```bash
   wp sync init
   ```
   This creates a `wp-sync.yml` file in the current directory.

3. **Add your server details.** Open `wp-sync.yml` and fill in an environment. You only need two things: a way to reach the server (an SSH alias or a hostname/IP) and the path to the WordPress installation on it:
   ```yaml
   environments:
     staging:
       host: my-staging-alias    # ~/.ssh/config alias, or a hostname/IP
       path: /path/to/wordpress  # remote WordPress root
       url: https://staging.example.com
   ```
   Using an `~/.ssh/config` alias is recommended — see [Connecting to your environments](#connecting-to-your-environments).

4. **Choose what to sync** in the `pull:` block (`db`, `themes`, `plugins`, `uploads` — all off unless you turn them on), then run:
   ```bash
   wp sync pull staging
   ```
   You'll see a summary of what's about to happen and be asked to confirm.

That's it. `pull` copies the chosen parts of the remote environment down to your local site, backing up your local database first. Use [`wp sync push`](#push) to go the other way.

## Installation

### Requirements

- [WP-CLI](https://make.wordpress.org/cli/handbook/guides/installing/) on your machine and on every server you sync with
- PHP 7.4 or later
- SSH access to each server
- `rsync` on your machine and the servers, for syncing files

### Install

```bash
wp package install https://github.com/kite-co-code/wp-sync.git
```

## Configuration
Your `wp-sync.yml` holds the settings for each command and the connection details for each environment. Run `wp sync` commands from the directory that contains it — your WordPress root or a theme.

Here's a typical config. Anything you leave out falls back to the defaults listed under [Options](#options).
```yaml
# wp-sync.yml
pull:
  db: true
  plugins: true
  uploads: false            # load_media_from_remote serves media from the remote site instead
  # Extra replacements, run straight after the domain replacement:
  # additional_search_replace:
  #   - search: "shop.example.com"
  #     replace: "shop.example.test"

push:
  db: false
  plugins: true

environments:
  local:
    # url: http://local.example.com (optional - wp cli will normally detect automatically)

  staging:
    # Reference an ~/.ssh/config alias; user/port/key come from there.
    host: my-staging-alias
    path: /path/to/wordpress
    url: http://staging.example.com

  production:
    # Or set an explicit host in user@host form, with port as a separate key.
    host: user@production-server-ip
    port: 22
    path: /path/to/wordpress
    url: http://production.example.com
```

### Connecting to your environments
`host` is the SSH destination passed to `ssh`, `rsync` and WP-CLI's `--ssh` flag — use an `~/.ssh/config` alias, a hostname, or an IP. There are two ways to configure a connection:

**1. SSH config alias (recommended).** Define the connection once in `~/.ssh/config`:

```
Host my-staging-alias
    HostName 203.0.113.10
    User deploy
    Port 2222
    IdentityFile ~/.ssh/deploy_key
```

then reference it by name:

```yaml
staging:
  host: my-staging-alias
  path: /path/to/wordpress
```

`user`, `port` and the identity key all come from `~/.ssh/config`, so leave `port` out of `wp-sync.yml`. Setting it here overrides the alias, which is usually not what you want.

It also keeps server details out of `wp-sync.yml`, so the file is safe to commit to git — each collaborator defines the same alias in their own `~/.ssh/config`.

**2. Explicit host.** Give a hostname or IP in `user@host` form, with `port` as a **separate** key:

```yaml
staging:
  host: user@staging-server-ip
  port: 22   # optional
  path: /path/to/wordpress
```

Don't fold `port` into `host` (e.g. `user@host:2222`) — an embedded `:port` breaks file transfers. Set it as a separate `port` key. `path` (the remote WordPress root) is always required.

### Excluding files
If a `.deployignore` file exists in the directory you run `wp sync` from, it's passed to rsync as an exclude list (rsync `--exclude-from` patterns, one per line). Use it to skip things like caches or build artefacts:

```
node_modules
.git
cache/
```

## Commands
### Pull
`wp sync pull <env> [options]` copies the chosen parts of `<env>` (e.g. `staging`, `production`) down to your local site.

Options set on the command line override `wp-sync.yml`:
```bash
wp sync pull staging --db --uploads
```

### Push
`wp sync push <env> [options]` works the same way in the other direction: it copies your **local** site **up** to `<env>`.

> [!WARNING]
> Push overwrites the remote site. `wp sync push production --db` replaces the live database with your local one, and any orders, comments or form entries made since your last pull are lost. A backup of the remote database is saved locally first (see [Database Backups](#database-backups)), but check the confirmation summary carefully before you say yes.

For a full migration to a new server, install WordPress and WP-CLI there, add it as an environment, then `wp sync pull old-server` followed by `wp sync push new-server`.

### Options
Set these in the `pull:` / `push:` blocks of `wp-sync.yml`, inside an environment, or as flags on the command line. Turn a flag on with `--db` and off with `--no-db`.

| Option | Default | Description |
| --- | --- | --- |
| `db` | `false` | Sync the database. |
| `themes` | `false` | Sync `wp-content/themes`. |
| `plugins` | `false` | Sync `wp-content/plugins` and `wp-content/mu-plugins`. |
| `uploads` | `false` | Sync `wp-content/uploads`. |
| `db_backup` | `true` | Back up the database that's about to be overwritten. See [Database Backups](#database-backups). |
| `db_backup_count` | `3` | Backups to keep per environment. Older ones are pruned automatically. |
| `load_media_from_remote` | `true` | After a database pull, load media from the remote site using [BE Media from Production](https://github.com/billerickson/BE-Media-from-Production), so you don't need to download uploads. |
| `search_replace_urls` | `true` | Detect the source and destination domains and search-replace them after a database sync. Set to `false` to handle domains yourself in a [hook](#custom-command-hooks). |
| `additional_search_replace` | none | Extra `search` / `replace` pairs to run straight after the domain replacement. Requires `search_replace_urls`. See [WordPress Multisite](#wordpress-multisite). |
| `yes` | `false` | Skip the confirmation prompt, for scripting: `wp sync pull production --yes && composer install && npm run build`. |

### Database Backups
When `db_backup` is enabled, `wp sync` backs up the database that is **about to be overwritten**, so you can restore if a sync goes wrong:

- **Pull** backs up your **local** database.
- **Push** backs up the **remote** database.

Backups are always stored **locally** in `wp-content/wp-sync-backups`, named `wp_sync_backup_<env>_<timestamp>.sql`. On push, the remote database is streamed over SSH and written locally — nothing is ever left on the remote server.

For security, the backup directory is created with `0700` permissions and includes `.htaccess` / `index.php` files to block web access to the dumps.

Only the newest `db_backup_count` backups are kept **per environment** (e.g. 3 `local` and 3 `staging`); older ones are pruned after each backup. Restore a backup with `wp db import`:
```bash
wp db import wp-content/wp-sync-backups/wp_sync_backup_local_20260721_143000.sql
```

## Custom Command Hooks

Run your own commands at key points in a sync — clear caches, deactivate plugins you don't want locally, or put a site into maintenance mode while it's being overwritten.

### Where hooks run

Commands starting with `wp ` are WP-CLI commands, run with `--skip-plugins --skip-themes`. Anything else runs as a shell command on your machine.

| Hook | `wp` commands run on | Shell commands run on |
| --- | --- | --- |
| `before_pull` | remote | local |
| `after_pull` | local | local |
| `before_push` | remote | local |
| `after_push` | remote | local |

In short: `after_pull` works on the site you just pulled into; every other hook's `wp` commands work on the remote site.

### Defining hooks
Hooks can be set for every environment (in the `pull:` / `push:` blocks) or for one environment:

```yaml
pull:
  after_pull:
    - "wp plugin deactivate wordfence"
    - "wp cache flush"

environments:
  staging:
    before_push:
      - "wp maintenance-mode activate"
    after_push:
      - "wp maintenance-mode deactivate"
```

## WordPress Multisite

Multisite networks are detected automatically. Both subdomain and subdirectory networks are supported.

### Setup

Your local wp-config.php needs the usual multisite constants (`MULTISITE`, `SUBDOMAIN_INSTALL`, `DOMAIN_CURRENT_SITE` and friends) before `wp sync` will treat the site as a network — it reads them to decide how to rewrite domains.

Subsites follow the main site. On a **subdomain network** `shop.example.com` becomes `shop.example.test`; on a **subdirectory network** every site shares the main domain, so there's nothing extra to do.

**Mapped domains aren't replaced.** If subsites run on their own domains rather than subdomains of the main site, only the main domain is handled. Add the others with `additional_search_replace`, which runs network-wide straight after the main replacement:

```yaml
pull:
  additional_search_replace:
    - search: "shop.example.com"
      replace: "shop.example.test"
```

### Replacing domains yourself

Set `search_replace_urls: false` to skip domain replacement entirely — including `additional_search_replace` — and handle it in a hook instead:

```yaml
pull:
  search_replace_urls: false
  after_pull:
    - "wp search-replace 'www.example.com' 'example.test' --network --all-tables"
    - "wp site list --field=url --format=csv | xargs -I {} wp --url={} cache flush"
```

## Contributing
Contributions are welcome — please open an issue or pull request on [GitHub](https://github.com/kite-co-code/wp-sync). See the [changelog](CHANGELOG.md) for release history.

## License
`wp sync` is open-source software licensed under the [MIT license](LICENSE).

## Author
Jerome Toole ([Kite Co.](https://github.com/kite-co-code))

## Credits
Thanks to [maneuver-agency/wp-cli-sync](https://github.com/maneuver-agency/wp-cli-sync), which I referenced while building this package.

Thanks to Bill Erickson's [BE Media from Production](https://github.com/billerickson/BE-Media-from-Production) for powering the `load_media_from_remote` option.

Thanks to [WP Migrate](https://deliciousbrains.com/wp-migrate-db-pro/) for providing the motivation.
