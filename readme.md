# WP CLI Sync: Simple WordPress migrations from the command line

WP CLI Sync is a WP-CLI package designed for single-command CLI migrations between WordPress environments.
It provides a straightforward way to synchronize the database, themes, plugins etc. between different environments.

[Quick start](#quick-start) | [Installation](#installation) | [Configuration](#configuration) | [Commands](#commands) | [Options](#options)

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

4. **Choose what to sync** in the `pull:` block (`db`, `themes`, `plugins`, `uploads`), check your settings are correct, then run:
   ```bash
   wp sync pull staging
   ```

That's it. `pull` copies the chosen parts of the remote environment down to your local site, backing up your local database first by default. Use [`wp sync push`](#push) to go the other way.

## Installation
Requires [WP-CLI](https://make.wordpress.org/cli/handbook/guides/installing/) and SSH access on every environment you sync. Install the package with:

```bash
wp package install https://github.com/kite-co-code/wp-sync.git
```

The package is also on [Packagist](https://packagist.org/packages/kite-co/wp-sync) as `kite-co/wp-sync`, but installing by name (`wp package install kite-co/wp-sync`) doesn't work in WP-CLI 2.12 or earlier. Use the git URL above until a newer WP-CLI release fixes this.

### Upgrading from jerometoole/wp-sync
The package has been renamed to `kite-co/wp-sync`. `wp package update` won't switch an existing install over, so uninstall the old package and install the new one:

```bash
wp package uninstall jerometoole/wp-sync
wp package install https://github.com/kite-co-code/wp-sync.git
```

Your `wp-sync.yml` files and commands are unchanged.

## Configuration
Your `wp-sync.yml` holds the settings for each command and the connection details for each environment. Run `wp sync` commands from the directory that contains it — your WordPress root or a theme.

Here's a complete example showing the defaults:
```yaml
# wp-sync.yml
pull:
  db: true
  themes: false
  plugins: true
  uploads: false
  db_backup: true
  db_backup_count: 3
  load_media_from_remote: true
  search_replace_urls: true
  # Extra replacements, run straight after the domain replacement:
  # additional_search_replace:
  #   - search: "shop.example.com"
  #     replace: "shop.example.test"

push:
  db: false
  themes: false
  plugins: true
  uploads: false
  db_backup: true
  db_backup_count: 3
  load_media_from_remote: false
  search_replace_urls: true

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

## Commands
### Pull
`wp sync pull` synchronizes your WordPress environment from a specified environment.

`wp sync pull <env> <options>`

- `<env>`: The environment to sync from (e.g., staging, production).
- `<options>`: The options to use for the sync. See [Options](#options) for more details.

Here's an example using option flags:
```bash
wp sync pull staging --db=true --load_media_from_remote=true
```

### Push
`wp sync push` synchronizes your local WordPress environment to a specified environment.

This command is used in the same way as the pull command, but synchronizes **from** your local environment **to** another environment.
`wp sync push <env> <options>`

### Options
- `db`: Synchronize the database. Default: false.
- `themes`: Synchronize themes. Default: false.
- `plugins`: Synchronize plugins. Default: false.
- `uploads`: Synchronize uploads. Default: false.
- `db_backup`: Back up the database that is about to be overwritten before synchronizing. See [Database Backups](#database-backups). Default: true.
- `db_backup_count`: Number of backups to keep per environment. Older backups are pruned automatically. Default: 3.
- `load_media_from_remote`: Load media from the remote environment when synchronizing the database (using [be-media-from-production](https://github.com/billerickson/BE-Media-from-Production)). Default: true.
- `search_replace_urls`: Detect the source and destination domains and search-replace them after a database sync. Set to false to handle domains yourself in an `after_pull` / `after_push` hook. Default: true.
- `additional_search_replace`: A list of extra `search` / `replace` pairs to run straight after the domain replacement. Requires `search_replace_urls` to be true. See [WordPress Multisite](#wordpress-multisite). Default: none.
- `yes`: Skip the confirmation prompt and run non-interactively. Useful for scripting, e.g. `wp sync pull production --yes && composer install && npm run build`. Default: false.

### Database Backups
When `db_backup` is enabled, WP CLI Sync backs up the database that is **about to be overwritten**, so you can restore if a sync goes wrong:

- **Pull** backs up your **local** database.
- **Push** backs up the **remote** database.

Backups are always stored **locally** in `wp-content/wp-sync-backups`, named `wp_sync_backup_<env>_<timestamp>.sql`. On push, the remote database is streamed over SSH and written locally — nothing is ever left on the remote server.

For security, the backup directory is created with `0700` permissions and includes `.htaccess` / `index.php` files to block web access to the dumps.

Only the newest `db_backup_count` backups are kept **per environment** (e.g. 3 `local` and 3 `staging`); older ones are pruned after each backup. Restore a backup with `wp db import`:
```bash
wp db import wp-content/wp-sync-backups/wp_sync_backup_local_20260721_143000.sql
```

## Custom Command Hooks

WP CLI Sync supports running arbitrary commands at key points during sync operations. This allows you to perform custom tasks like additional search-replace operations, cache clearing, or maintenance mode activation.

### Hook Types
- `before_pull` / `before_push`: Execute before sync operations begin
- `after_pull` / `after_push`: Execute after sync operations complete

### Defining Hooks
Commands can be defined at global (pull/push) or environment-specific levels:

```yaml
pull:
  db: true
  plugins: true
  after_pull:
    - "wp search-replace 'old-string' 'new-string'"
    - "wp cache flush"

environments:
  staging:
    before_pull:
      - "wp maintenance-mode activate"
    after_pull:
      - "wp maintenance-mode deactivate"
```

### Command Types
- **WP-CLI commands**: Start with `wp ` and are executed with appropriate skip flags
- **Shell commands**: Any other commands are executed as shell commands
- **Remote execution**: Commands automatically run on remote server when using SSH

## WordPress Multisite

Multisite networks are detected automatically. Both subdomain and subdirectory networks are supported.

### Setup

Your local wp-config.php needs the usual multisite constants (`MULTISITE`, `SUBDOMAIN_INSTALL`, `DOMAIN_CURRENT_SITE` and friends) before wp-sync will treat the site as a network — it reads them to decide how to rewrite domains.

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
Contributions are welcome. Please feel free to submit pull requests or raise issues on the GitHub repository.
It might take me a while to respond, but I'll do my best to get back to you.

## License
WP CLI Sync is open-sourced software licensed under the MIT license.

## Author
Jerome Toole

## Credits
Thanks to [maneuver-agency/wp-cli-sync](https://github.com/maneuver-agency/wp-cli-sync) which I referenced while building this package.

Thanks to Bill Erickson's [be-media-from-production](https://github.com/billerickson/BE-Media-from-Production) for powering the load_media_from_remote option.

Thanks to [WP Migrate](https://deliciousbrains.com/wp-migrate-db-pro/) for providing the motivation.
