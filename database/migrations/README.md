# Migration Baseline: 2026-10-10

The existing production database is the baseline. This is not a fresh-install
schema and does not assert that every historical migration was executed.

All 176 pre-baseline SQL migrations are preserved in
`database/archive/2026_10_10_baseline/`, with SHA-256 checksums in `manifest.json`.
The archive was committed before the active SQL files were removed.
Never copy those migrations back into this directory or replay the archive.

`api/tools/run-migrations.php` scans only `database/migrations/*.sql` and uses
`schema_migrations.filename` as its applied-migration ledger. Historical ledger
rows are retained. The metadata-only marker `__baseline_2026_10_10__` records
the reset; it is not an executable migration and does not mark pending old
scripts as applied.

Future changes go here as new, uniquely named SQL files using
`YYYY_MM_DD_NNN_description.sql`. Write each migration against the actual
baseline schema, not assumptions from the archived scripts. Inspect production
before relying on a historical column, table, or backfill.

Deploy database files using `./deploydb.sh` (its `--delete` removes retired SQL
from the remote active directory). Run `./ssh-migrate.sh` afterward. An empty
active directory prints `No migration files found.` and executes no migration.

The baseline operation changes only migration files and migration-ledger
metadata. Application tables, relationships, and data are not changed.
