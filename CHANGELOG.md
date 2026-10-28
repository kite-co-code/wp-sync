## [v1.3.0] - 2026-10-28

### Changed
- `wp sync init` no longer turns on `themes` in the generated `wp-sync.yml`.
- Readme rewritten around what the package does, with sample output, a hooks table showing where each hook runs, and a push warning.
- Added an MIT `LICENSE` file.

### Fixed
- `--db=false` and other `=false` CLI flags were read as true, so `wp sync push production --db=false` still overwrote the remote database. Boolean options now accept true/false, yes/no, 1/0 or on/off, and any other value stops the sync with an error. `--search_replace_urls=true` and `--verbose=false` now work too.
- A failed database import on push was ignored: the push carried on to search-replace and reported success. It now stops with the remote's error output.
- Database syncs could corrupt content when the collation fix ran ([#6](https://github.com/kite-co-code/wp-sync/issues/6)): text like "collate the" was read as a collation, and every "the" in the dump was rewritten, breaking serialized data such as user roles. Collations are now only read and rewritten in table definitions, never in row data, for both MySQL and MariaDB dump formats.
- `after_pull` WP-CLI commands were passed the skip flags in place of the SSH flag. They still ran locally, but now run without the duplicated flags.

## [v1.2.0] - 2026-10-07

### Changed
- Repository moved to [kite-co-code/wp-sync](https://github.com/kite-co-code/wp-sync) and the package renamed from `jerometoole/wp-sync` to `kite-co/wp-sync`, now published on [Packagist](https://packagist.org/packages/kite-co/wp-sync). Existing installs must reinstall, as `wp package update` won't pick up the new name:
  ```bash
  wp package uninstall jerometoole/wp-sync
  wp package install https://github.com/kite-co-code/wp-sync.git
  ```

## [v1.0.0] - 2026-07-29

### Added
- `--yes` flag on `pull`/`push` to skip the confirmation prompt and run non-interactively.
- `search_replace_urls` option (default `true`) to make automatic domain/URL search-replace optional.
- `db_backup_count` option (default 3) to control how many backups are kept per environment; older ones are pruned.
- Support for SSH config aliases as `host` in push/pull config.
- Collation remapping on sync: unsupported collations in a dump (e.g. MariaDB UCA-1400) are rewritten to a fallback the destination supports.
- `--allow-root` added automatically when the SSH user is `root`.
- Full option documentation and examples in `wp sync pull|push --help`.

### Changed
- Backups are always written locally to `wp-content/wp-sync-backups` (blocked from web access), including for remote sources — nothing is left on the remote server.
- `update_domains_urls` renamed to `search_replace_urls`.
- `backup_count` renamed to `db_backup_count`, default raised from 2 to 3.
- Multisite `DOMAIN_CURRENT_SITE` mismatches now warn instead of rewriting `wp-config.php` during a sync.
- Readme and `wp-sync.example.yml` rewritten with clearer connection and multisite guidance.

### Fixed
- Multisite search-replace no longer mangles subsite hosts when `www.` differs between environments: replacement now runs on the bare host (preserving `www.`), with a second base-domain pass for subdomain networks, and warns when subsite domains need manual correction.
- Dropped `--all-tablespaces` from db export, which caused PROCESS privilege errors.
- Push db sync routes through a local temp file so dumps can be sanitised before import.
- mu-plugins are now included in push file sync.

## [v0.9.0] - 2025-09-19

### Fixed
- Corrected `after_pull` command to run locally instead of on the remote server.
