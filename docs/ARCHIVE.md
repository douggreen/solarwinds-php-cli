# Archiving (Tiered Storage)

The `archive` command moves old logs out of the main SQLite database into
**shard files** on longer-term storage, keeping the hot database small while
preserving older data in a still-queryable form.

## Configuration

In `~/.solarwinds.yml`:

```yaml
archive:
  enabled: true                       # off by default
  local_keep_days: 60                 # rolling window kept in the main DB
  longterm_storage: /path/to/archive  # where shard files live (e.g. a NAS mount)
  shard_period: weekly                # weekly | monthly
  include_in_queries: false           # (reserved; querying is automatic — see below)
  fail_on_unavailable: true           # error if storage is missing at archive time
```

When `enabled` is `false` (the default), the command refuses to run and
nothing about the database changes.

## What it does

Run `solarwinds archive` (typically from cron). For each **whole period**
(week or month) that is **entirely** older than `local_keep_days`, it:

1. Builds a shard on **local disk** (full SQLite speed).
2. Copies the finished shard file to `longterm_storage` and verifies the copy.
3. Registers the shard in the `archive_files` table.
4. Deletes those rows from the main database (only after the copy is verified).
5. After all periods, runs `VACUUM` on the main database to reclaim space.

Override the window for a one-off run with `--keep-days=N`; preview with
`--dry-run`.

## Whole-period semantics (and the local-window sawtooth)

A period is only archived once it is **entirely** past the keep window — the
current partial period is never touched. This makes every shard **write-once**:
a period is built and shipped exactly once and never appended to again.

The consequence: the local database holds `local_keep_days` **plus up to one
partial period**. So the on-disk footprint sawtooths — it grows as the current
period accumulates, then drops when that period ages out and is archived.

- **`shard_period: monthly`** — overhead up to ~30 days. Fewer, larger shards.
  Real archiving happens roughly **once a month** (the night a month finally
  ages out); every other nightly run is a cheap no-op.
- **`shard_period: weekly`** — overhead up to ~7 days. More, smaller shards,
  and a **tighter local footprint**. Real archiving happens roughly weekly.

Pick weekly when you want to cap local size tightly; monthly when you prefer
fewer files and don't mind a larger local window.

Shards are named `logs-2026-06.db` (monthly) or `logs-2026-W26.db` (weekly).

## Why build locally and copy (not write directly to storage)

Shards are built on local disk and then **copied** to `longterm_storage`,
rather than written directly there. Writing a live SQLite database over a
network mount (SMB/NFS) is extremely slow and lock-prone — random page writes
plus journal syncs over the network crawl. A **sequential file copy** of the
finished shard is not subject to that, so the slow part never holds the main
database lock.

> :warning: **This is a stopgap for the network-storage case.** It works, but
> it's tuned around the quirk that SQLite-over-SMB is impractical for writes.
> Building the whole shard locally also needs transient local space equal to
> the shard size. A cleaner long-term approach (e.g. a different on-disk format
> for cold data, or storage that isn't a network SQLite file) is worth
> revisiting if archiving becomes central to the workflow.

## Querying archived data

Queries span the archive **automatically and seamlessly** — there is no flag
to remember. `LogQueryService` checks the requested time range against the
`archive_files` registry and, only when the range actually reaches into
archived time, ATTACHes the overlapping shards and unions them with the hot
table. A recent-only query (e.g. `--1d`) overlaps no shard and never touches
the archive, so normal queries stay fast and never spin up the NAS.

If a registered shard's file is missing (e.g. the NAS is unmounted), it is
skipped so the query still runs against whatever is available.

`sync:status` is archive-aware too: it reports the full span including archived
data and breaks the record count into local vs archived.

## Concurrency guards

The archive's `VACUUM` takes an exclusive SQLite lock, which would collide with
a sync. A small advisory lock (`LockService`, `flock` on a local sentinel file)
coordinates them:

- **Syncs** take a **shared** lock — many run at once (they already coordinate
  row-level via per-chunk claims).
- **Archive** takes an **exclusive** lock — it waits for in-flight syncs and
  blocks new ones for the brief window it needs.

If a sync can't get the shared lock within its timeout (an archive is running
long), it skips that run; the gap is filled on a later sync. If the archive
can't get the exclusive lock, it exits cleanly and runs again next time.

## Cron

Run nightly, after the sync jobs and at a minute that won't overlap them (the
exclusive-lock window during VACUUM is the thing to keep clear):

```cron
5 4 * * *  solarwinds archive -n -q
```

Most nights this is a no-op (no whole period has newly aged out); the actual
move happens about once per `shard_period`. Keeping it nightly gives retry
resilience — if a run fails, the next night tries again.
