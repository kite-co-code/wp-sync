# Testing wp-sync

## Unit tests

```bash
composer install
composer test
```

- Tests live in `tests/*Test.php` and run without WordPress or a database.
- [bootstrap.php](bootstrap.php) loads the autoloader, then defines a stub `WP_CLI` class:
  - `WP_CLI::$runcommand_output`: what `WP_CLI::runcommand()` returns. Use it to fake output from the remote or local `wp`.
  - `WP_CLI::$logs`: everything passed to `log()` and `warning()`.
  - `WP_CLI::error()` throws `WP_CLI_Error` instead of exiting, so tests can expect it.
- Reset static state in `setUp()`, because the stub is shared across tests.
- Add or update tests in the same change as the code. If logic is buried in `Pull`/`Push`, extract it into a `Helpers` method so it can be tested.

### Helpers that shell out

Some helpers stream files through `grep`/`sed` (for example `normalizeDumpCollations()`), and their tests run those real tools. The commands have to work with both BSD (macOS) and GNU (Linux) versions:
- Use `sed -E` and `grep -E`.
- Avoid GNU-only features like `\b`, `sed -i` without a suffix, and the `I` flag.
- Use `[^a-zA-Z0-9_]` instead of word boundaries.

### Check that a test actually tests the fix

A test that passes is not proof. For each guard in a fix:
1. Remove or weaken that guard.
2. Run `composer test` and confirm at least one test fails.
3. Restore the guard.

Also run the new tests against the old code (`git stash` the fix) and confirm they fail. When `normalizeDumpCollations()` was fixed for #6, this showed that two guards were untested and that the first version of the fix didn't handle MariaDB's dump format.

## Database compatibility matrix

```bash
tests/compat/db-matrix.sh                      # all versions, about 1 minute once images are pulled
tests/compat/db-matrix.sh mysql80 maria118     # just these
```

This needs Docker, and pulls MySQL 5.7, 8.0, 8.4 and 9 and MariaDB 10.6, 10.11, 11.4 and 11.8.

**What it does:**
- Loads [compat/fixture.sql](compat/fixture.sql) into each server and dumps it with that server's own dump tool, using the same flags as `wp db export` in `Pull`/`Push`.
- Imports every dump into every other version twice: once raw, and once after [compat/normalize.php](compat/normalize.php) runs `normalizeDumpCollations()` against the target's real `SHOW COLLATION` output.
- Compares a checksum of the row data, and exits non-zero on any failure.
- Removes its containers on exit.

Run it whenever you change dump or import handling. Extend `fixture.sql` when you find new content that breaks a sync.

**Hidden problems it filters out** (they're tracked in `.plans/issues.md`):
- MySQL dumps are made with `--set-gtid-purged=OFF`.
- The fixture has no triggers.

Without those two changes, imports fail for reasons unrelated to collations.

## What dumps look like across versions

Verified with `db-matrix.sh` in October 2026:

| | MySQL 5.7–9.x | MariaDB 10.6 | MariaDB 10.11+ |
|---|---|---|---|
| Row layout | `INSERT INTO t VALUES (…),(…);` on one line | same as MySQL | `INSERT INTO t VALUES` on its own line, then one `(…)` row per line |
| Default utf8mb4 collation | 5.7: `utf8mb4_general_ci`; 8.0+: `utf8mb4_0900_ai_ci` | `utf8mb4_general_ci` | 11.x: `utf8mb4_uca1400_ai_ci` |
| `SHOW COLLATION` names | `<charset>_<name>` | `<charset>_<name>` | UCA-1400 ones are listed without the charset (`uca1400_ai_ci`) |

Other things to know:
- Newlines inside values are escaped as `\n` by every dump tool, so one physical line never holds part of a row and part of the schema.
- Imports that fail on the raw dump:
  - MySQL 8+ → MySQL 5.7 or MariaDB 10.x fails on `utf8mb4_0900_ai_ci`. MariaDB 11.4+ accepts it.
  - MariaDB 11 → any MySQL, or → MariaDB 10.6, fails on `utf8mb4_uca1400_ai_ci`.
- MySQL with GTID enabled adds `SET @@GLOBAL.GTID_PURGED=…` to dumps. Some hosts turn GTID on, and the official `mysql:9` image has it on.
- MariaDB dumps that contain triggers or routines set `sql_mode` to include `NO_AUTO_CREATE_USER`, which MySQL 8+ rejects.
- `mysql:5.7` has no arm64 image. On Apple Silicon it runs under emulation, and its entrypoint only works with `MYSQL_ALLOW_EMPTY_PASSWORD=yes`.

## Manual testing against WordPress

The sync commands themselves still need a real run.

To try the package from your working copy:

```bash
wp package install /path/to/wp-sync     # links the working copy, so edits apply immediately
```

To test an install without touching your real WP-CLI packages:

```bash
WP_CLI_PACKAGES_DIR=$(mktemp -d) wp package install https://github.com/kite-co-code/wp-sync.git
```
