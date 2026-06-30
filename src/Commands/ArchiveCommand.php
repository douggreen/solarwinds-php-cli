<?php

/**
 * @file ArchiveCommand.php
 * @brief Moves old logs to monthly SQLite shard files
 *
 * @class ArchiveCommand
 * @brief Tiered storage: hot logs in main DB, cold logs in monthly shards
 *
 * Operates per-month within the cutoff window:
 *   1. ATTACH the month's shard file (create if missing, schema-match logs)
 *   2. INSERT OR IGNORE main.logs rows for that month into the shard
 *   3. Verify shard count matches main count for the range
 *   4. Register in archive_files
 *   5. DELETE archived rows from main
 *   6. DETACH
 * Followed by a single VACUUM on the main DB to reclaim space.
 *
 * Idempotent: re-runs are safe. INSERT OR IGNORE handles already-archived
 * rows; the count verification catches partial-copy bugs before delete.
 */

namespace SolarWinds\Commands;

use PDO;
use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use SolarWinds\Services\LockService;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tiered-storage archiver. Moves logs older than archive.local_keep_days from
 * the main SQLite database into weekly or monthly shard files (per
 * archive.shard_period) at archive.longterm_storage. Only whole periods that
 * are entirely past the keep window are archived, so each shard is written
 * once. Shards keep the logs schema so they can be queried by ATTACHing them.
 */
class ArchiveCommand extends Command
{
  protected ConfigurationService $config;
  protected DatabaseService $database;
  protected LockService $lock;

  /**
   * Constructor.
   *
   * Initializes configuration and database services. The archive command
   * doesn't share BaseSolarWindsCommand's API-bound dependencies because
   * it never talks to SolarWinds.
   */
  public function __construct()
  {
    $this->config = new ConfigurationService();
    parent::__construct();
    $this->database = new DatabaseService($this->config);
    $this->lock = new LockService($this->config);
  }

  /**
   * Register command name, help text, and CLI options.
   */
  protected function configure(): void
  {
    $this
      ->setName('archive')
      ->setDescription('Move old logs to monthly SQLite shards at archive.longterm_storage')
      ->setHelp("
        Moves logs older than <info>archive.local_keep_days</info> (configured
        in ~/.solarwinds.yml) from the main database to monthly shard files
        at <info>archive.longterm_storage</info>. The main DB stays small;
        archived data stays queryable via <info>--include-archive</info> on
        the analysis commands.

        <comment>Examples:</comment>
        <info>solarwinds archive</info>                  # Use configured keep_days
        <info>solarwinds archive --keep-days=30</info>   # Override at runtime
        <info>solarwinds archive --dry-run</info>        # Show what would move
      ")
      ->addOption('keep-days', NULL, InputOption::VALUE_REQUIRED, 'Override archive.local_keep_days')
      ->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Report what would happen, do nothing')
      ->addOption('json', NULL, InputOption::VALUE_NONE, 'Machine-readable output');
  }

  /**
   * Execute the archive pipeline.
   *
   * Validates configuration and storage availability, computes the cutoff,
   * iterates per-month archive buckets, and runs VACUUM on the main DB at
   * the end. Exits early with a friendly message if there's nothing to do.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $json = (bool) $input->getOption('json');
    $dryRun = (bool) $input->getOption('dry-run');

    $cfg = $this->config->getArchiveConfig();

    if (!$cfg['enabled']) {
      $io->error('archive.enabled is FALSE in configuration. Enable it in ~/.solarwinds.yml first.');
      return 1;
    }

    if ($cfg['longterm_storage'] === '') {
      $io->error('archive.longterm_storage is not configured.');
      return 1;
    }

    if (!is_dir($cfg['longterm_storage'])) {
      $io->error(sprintf('Archive storage not available at %s — mount the volume or correct configuration.', $cfg['longterm_storage']));
      return 1;
    }

    if (!is_writable($cfg['longterm_storage'])) {
      $io->error(sprintf('Archive storage at %s is not writable.', $cfg['longterm_storage']));
      return 1;
    }

    $keepDaysOverride = $input->getOption('keep-days');
    $keepDays = $keepDaysOverride !== NULL ? (int) $keepDaysOverride : $cfg['local_keep_days'];

    if ($keepDays < 1) {
      $io->error("keep-days must be at least 1 (got $keepDays)");
      return 1;
    }

    $cutoffTime = time() - $keepDays * 86400;
    $cutoffIso = gmdate('Y-m-d\TH:i:s\Z', $cutoffTime);

    if (!$json) {
      $io->title('SolarWinds Archive');
      $io->definitionList(
        ['Storage path' => $cfg['longterm_storage']],
        ['Keep days' => $keepDays],
        ['Shard period' => $cfg['shard_period']],
        ['Cutoff (logs older move)' => $cutoffIso],
        ['Dry run' => $dryRun ? 'yes' : 'no'],
      );
    }

    // Acquire the exclusive maintenance lock for a real run so the VACUUM
    // can't collide with a sync. Dry-run is read-only and skips locking.
    $lockHandle = NULL;
    if (!$dryRun) {
      $lockHandle = $this->lock->acquire('db-maintenance', TRUE, 300);
      if ($lockHandle === NULL) {
        $io->error('Could not acquire the maintenance lock (a sync or another archive is running). Try again later.');
        return 1;
      }
    }

    // Find oldest archivable timestamp; if nothing predates the cutoff, exit.
    // Close the cursor immediately — VACUUM later requires no open statements.
    $stmt = $this->database->prepare('SELECT MIN(time) AS earliest, COUNT(*) AS count FROM logs WHERE time < :cutoff');
    $stmt->execute([':cutoff' => $cutoffTime]);
    $head = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $stmt = NULL;
    if ((int) $head['count'] === 0) {
      if ($json) {
        $output->writeln(json_encode(['status' => 'nothing_to_archive', 'cutoff_time' => $cutoffTime]));
      }
      else {
        $io->success('No logs older than the cutoff — nothing to archive.');
      }
      return 0;
    }

    $earliest = (int) $head['earliest'];
    $buckets = $this->periodBuckets($earliest, $cutoffTime, $cfg['shard_period']);

    $results = [];
    foreach ($buckets as $bucket) {
      $result = $this->archivePeriod($bucket, $cfg['longterm_storage'], $dryRun, $io, $json);
      $results[] = $result;
      // Bail on first error.
      if ($result['status'] === 'error') {
        break;
      }
    }

    if (!$dryRun) {
      // Reclaim space in main DB.
      if (!$json) {
        $io->section('Reclaiming space');
        $io->text('Running VACUUM on main DB…');
      }
      $dbPath = $this->config->getDatabasePath();
      clearstatcache(TRUE, $dbPath);
      $sizeBefore = filesize($dbPath);
      $this->database->exec('VACUUM');
      // PHP caches stat() results per path; without clearing, filesize() would
      // return the pre-VACUUM size and report "saved 0 B" even when the file
      // shrank substantially.
      clearstatcache(TRUE, $dbPath);
      $sizeAfter = filesize($dbPath);
      if (!$json) {
        $io->text(sprintf('Main DB: %s → %s (saved %s)',
          $this->humanBytes($sizeBefore),
          $this->humanBytes($sizeAfter),
          $this->humanBytes(max(0, $sizeBefore - $sizeAfter))
        ));
      }
    }

    if ($json) {
      $output->writeln(json_encode(['results' => $results], JSON_PRETTY_PRINT));
    }
    else {
      $totalRows = array_sum(array_column($results, 'rows_moved'));
      $shards = count(array_filter($results, fn($r) => ($r['rows_moved'] ?? 0) > 0));
      $io->success(sprintf('Archive complete: %s rows moved into %d shard(s)', number_format($totalRows), $shards));
    }

    return 0;
  }

  /**
   * Compute [start, end) buckets for each WHOLE period (week or month) that is
   * entirely older than the cutoff.
   *
   * A period is only emitted when its end is at or before the cutoff — so the
   * current partial period (whose end is still in the future relative to the
   * cutoff) is never archived. This makes each shard write-once: a period is
   * built and shipped exactly once, never appended to later.
   *
   * @param int $earliest Earliest log timestamp (unix)
   * @param int $cutoffTime now - keep_days (unix); periods ending after this stay local
   * @param string $period 'weekly' or 'monthly'
   * @return array<array{key: string, start: int, end: int}>
   */
  protected function periodBuckets(int $earliest, int $cutoffTime, string $period): array
  {
    $buckets = [];
    $cursor = $this->periodStart($earliest, $period);
    while (TRUE) {
      $next = $this->periodNext($cursor, $period);
      // Stop at the first period that is not entirely older than the cutoff.
      if ($next > $cutoffTime) {
        break;
      }
      $buckets[] = [
        'key' => $this->periodKey($cursor, $period),
        'start' => $cursor,
        'end' => $next,
      ];
      $cursor = $next;
    }
    return $buckets;
  }

  /**
   * Start (midnight UTC) of the period containing $timestamp.
   *
   * @param int $timestamp Unix timestamp
   * @param string $period 'weekly' or 'monthly'
   * @return int Period start (unix)
   */
  protected function periodStart(int $timestamp, string $period): int
  {
    if ($period === 'weekly') {
      // ISO week starts Monday. N = 1 (Mon) .. 7 (Sun).
      $dow = (int) gmdate('N', $timestamp);
      return (int) gmmktime(
        0,
        0,
        0,
        (int) gmdate('n', $timestamp),
        (int) gmdate('j', $timestamp) - ($dow - 1),
        (int) gmdate('Y', $timestamp)
      );
    }
    return (int) gmmktime(0, 0, 0, (int) gmdate('n', $timestamp), 1, (int) gmdate('Y', $timestamp));
  }

  /**
   * Start of the period after $cursor (which must be a period start).
   *
   * @param int $cursor A period start (unix)
   * @param string $period 'weekly' or 'monthly'
   * @return int Next period start (unix)
   */
  protected function periodNext(int $cursor, string $period): int
  {
    if ($period === 'weekly') {
      // Cursor is Monday 00:00 UTC; +7 days is exact in UTC (no DST).
      return $cursor + 7 * 86400;
    }
    return (int) gmmktime(0, 0, 0, (int) gmdate('n', $cursor) + 1, 1, (int) gmdate('Y', $cursor));
  }

  /**
   * Shard key for a period start, e.g. "2026-06" (monthly) or "2026-W26"
   * (weekly). Used in the shard filename logs-{key}.db.
   *
   * @param int $cursor Period start (unix)
   * @param string $period 'weekly' or 'monthly'
   * @return string Shard key
   */
  protected function periodKey(int $cursor, string $period): string
  {
    if ($period === 'weekly') {
      return gmdate('o-\WW', $cursor);
    }
    return gmdate('Y-m', $cursor);
  }

  /**
   * Archive a single period bucket: build the shard on local disk, copy the
   * finished file to longterm storage, verify, register, then delete from main.
   *
   * The shard is built locally and copied (rather than written directly to
   * longterm storage) because writing a live SQLite database over a network
   * mount is extremely slow and lock-prone, whereas a sequential file copy is
   * not. Rows are not deleted from main until the storage copy is verified.
   *
   * @param array{key: string, start: int, end: int} $bucket Period bucket
   * @param string $storage Longterm storage directory
   * @param bool $dryRun Report only, change nothing
   * @param SymfonyStyle $io Output styler
   * @param bool $jsonMode Suppress human output
   * @return array{key: string, status: string, rows_moved: int, shard_path?: string, error?: string}
   */
  protected function archivePeriod(array $bucket, string $storage, bool $dryRun, SymfonyStyle $io, bool $jsonMode): array
  {
    $key = $bucket['key'];
    $start = $bucket['start'];
    $end = $bucket['end'];
    $finalPath = rtrim($storage, '/') . "/logs-$key.db";

    // Count what would move.
    $stmt = $this->database->prepare('SELECT COUNT(*) FROM logs WHERE time >= :start AND time < :end');
    $stmt->execute([':start' => $start, ':end' => $end]);
    $toMove = (int) $stmt->fetchColumn();
    $stmt->closeCursor();
    $stmt = NULL;

    if ($toMove === 0) {
      return [
        'key' => $key,
        'status' => 'empty',
        'rows_moved' => 0,
      ];
    }

    if (!$jsonMode) {
      $io->section("Period $key");
      $io->text(sprintf('Shard: %s', $finalPath));
      $io->text(sprintf('Rows to move: %s', number_format($toMove)));
    }

    if ($dryRun) {
      return [
        'key' => $key,
        'status' => 'dry_run',
        'rows_moved' => 0,
        'rows_would_move' => $toMove,
        'shard_path' => $finalPath,
      ];
    }

    // Build on local disk first; stale temp from a prior crash is overwritten.
    $localTmp = $this->localTmpDir() . "/logs-$key-" . getmypid() . '.db';
    @unlink($localTmp);

    try {
      $this->database->exec(sprintf("ATTACH DATABASE '%s' AS arc", str_replace("'", "''", $localTmp)));
      $this->ensureShardSchema('arc');

      // Copy this period's rows into the local shard.
      $copyStmt = $this->database->prepare('INSERT INTO arc.logs SELECT * FROM main.logs WHERE time >= :start AND time < :end');
      $copyStmt->execute([':start' => $start, ':end' => $end]);

      // Verify against main and capture the shard's actual data extent.
      $verifyMain = $this->database->prepare('SELECT COUNT(*) FROM main.logs WHERE time >= :start AND time < :end');
      $verifyMain->execute([':start' => $start, ':end' => $end]);
      $mainCount = (int) $verifyMain->fetchColumn();
      $verifyMain->closeCursor();
      $verifyMain = NULL;

      $extentStmt = $this->database->query('SELECT MIN(time) AS lo, MAX(time) AS hi, COUNT(*) AS n FROM arc.logs');
      $extent = $extentStmt->fetch(PDO::FETCH_ASSOC);
      $extentStmt->closeCursor();
      $extentStmt = NULL;
      $shardCount = (int) $extent['n'];
      $shardStart = (int) $extent['lo'];
      $shardEnd = (int) $extent['hi'];

      if ($shardCount < $mainCount) {
        throw new \RuntimeException("Verification failed: local shard has $shardCount rows, main has $mainCount");
      }

      // Detach so the local shard file is flushed and closed before copying.
      $this->database->exec('DETACH DATABASE arc');

      // Copy the finished shard to longterm storage and verify the copy.
      if (!$jsonMode) {
        $io->text('Copying shard to storage…');
      }
      if (!@copy($localTmp, $finalPath)) {
        throw new \RuntimeException("Failed to copy shard to $finalPath");
      }
      $verifyCopy = new \PDO('sqlite:' . $finalPath);
      $copyCount = (int) $verifyCopy->query('SELECT COUNT(*) FROM logs')->fetchColumn();
      $verifyCopy = NULL;
      if ($copyCount !== $shardCount) {
        throw new \RuntimeException("Copy verification failed: storage shard has $copyCount rows, expected $shardCount");
      }

      // Register (pointing at the storage path), then it's safe to delete.
      clearstatcache(TRUE, $finalPath);
      $sizeBytes = filesize($finalPath) ?: 0;
      $register = $this->database->prepare(<<<'SQL'
INSERT INTO archive_files (file_path, year_month, start_time, end_time, record_count, size_bytes)
VALUES (:path, :key, :start, :end, :count, :size)
ON CONFLICT(file_path) DO UPDATE SET
  year_month = :key,
  start_time = :start,
  end_time = :end,
  record_count = :count,
  size_bytes = :size,
  archived_at = CURRENT_TIMESTAMP
SQL
      );
      $register->execute([
        ':path' => $finalPath,
        ':key' => $key,
        ':start' => $shardStart,
        ':end' => $shardEnd,
        ':count' => $shardCount,
        ':size' => $sizeBytes,
      ]);

      $delete = $this->database->prepare('DELETE FROM main.logs WHERE time >= :start AND time < :end');
      $delete->execute([':start' => $start, ':end' => $end]);

      @unlink($localTmp);

      if (!$jsonMode) {
        $io->text(sprintf('  ✓ Moved %s rows to %s', number_format($shardCount), basename($finalPath)));
      }

      return [
        'key' => $key,
        'status' => 'archived',
        'rows_moved' => $shardCount,
        'shard_path' => $finalPath,
      ];
    }
    catch (\Throwable $e) {
      try {
        $this->database->exec('DETACH DATABASE arc');
      }
      catch (\Throwable $ignored) {
      }
      @unlink($localTmp);
      if (!$jsonMode) {
        $io->error(sprintf('Failed to archive %s: %s', $key, $e->getMessage()));
      }
      return [
        'key' => $key,
        'status' => 'error',
        'rows_moved' => 0,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Local scratch directory for building shards before copying to storage.
   * Lives next to the main DB so it's on fast local disk with room to spare.
   *
   * @return string Absolute path to the local temp directory
   */
  protected function localTmpDir(): string
  {
    $dir = dirname($this->config->getDatabasePath()) . '/archive-tmp';
    if (!is_dir($dir)) {
      mkdir($dir, 0755, TRUE);
    }
    return $dir;
  }

  /**
   * Apply the logs table schema and indexes to a database alias.
   *
   * Keep this in sync with DatabaseService::createSchema()'s logs section.
   * Shards must match the main schema column-for-column so cross-DB UNION
   * queries succeed at attach-and-query time.
   */
  protected function ensureShardSchema(string $alias): void
  {
    $this->database->exec(<<<SQL
CREATE TABLE IF NOT EXISTS $alias.logs (
  id TEXT PRIMARY KEY,
  time INTEGER NOT NULL,
  retrieved_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
  data JSON NOT NULL,
  client_ip TEXT,
  resp_status INTEGER,
  req_user_agent TEXT,
  req_uri TEXT,
  orig_host TEXT,
  country TEXT,
  req_method TEXT,
  city TEXT,
  log_type TEXT,
  log_severity TEXT,
  program TEXT,
  message TEXT,
  url_arguments TEXT,
  base_path TEXT,
  cache_status TEXT,
  region TEXT
)
SQL
    );
    // Critical indexes for time-range and IP queries against shards.
    $this->database->exec("CREATE INDEX IF NOT EXISTS $alias.idx_time_method ON logs(time, req_method) WHERE req_method IS NOT NULL");
    $this->database->exec("CREATE INDEX IF NOT EXISTS $alias.idx_client_ip_time ON logs(client_ip, time) WHERE client_ip IS NOT NULL");
  }

  /**
   * Format a byte count as a human-readable string (e.g., "4.98 GB").
   *
   * @param int $bytes Raw byte count
   * @return string Formatted size with unit suffix
   */
  protected function humanBytes(int $bytes): string
  {
    $units = [
      'B',
      'KB',
      'MB',
      'GB',
      'TB',
    ];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
      $size /= 1024;
      $i++;
    }
    return sprintf('%.2f %s', $size, $units[$i]);
  }
}
