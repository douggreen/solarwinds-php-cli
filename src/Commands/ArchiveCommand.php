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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ArchiveCommand extends Command
{
  protected ConfigurationService $config;
  protected DatabaseService $database;

  public function __construct()
  {
    $this->config = new ConfigurationService();
    parent::__construct();
    $this->database = new DatabaseService($this->config);
  }

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
        ['Cutoff (logs older move)' => $cutoffIso],
        ['Granularity' => $cfg['shard_granularity']],
        ['Dry run' => $dryRun ? 'yes' : 'no'],
      );
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
    $months = $this->monthBuckets($earliest, $cutoffTime);

    $results = [];
    foreach ($months as $bucket) {
      $result = $this->archiveMonth($bucket, $cutoffTime, $cfg['longterm_storage'], $dryRun, $io, $json);
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
      $sizeBefore = filesize($this->config->getDatabasePath());
      $this->database->exec('VACUUM');
      $sizeAfter = filesize($this->config->getDatabasePath());
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
   * Compute the [start, end) bounds for each month containing data between
   * [earliest, cutoffTime). Capped so the final bucket never extends past
   * cutoffTime.
   *
   * @return array<array{year_month: string, start: int, end: int}>
   */
  protected function monthBuckets(int $earliest, int $cutoffTime): array
  {
    $buckets = [];
    $cursor = (int) gmmktime(0, 0, 0, (int) gmdate('n', $earliest), 1, (int) gmdate('Y', $earliest));
    while ($cursor < $cutoffTime) {
      $year = (int) gmdate('Y', $cursor);
      $month = (int) gmdate('n', $cursor);
      $next = (int) gmmktime(0, 0, 0, $month + 1, 1, $year);
      $bucketEnd = min($next, $cutoffTime);
      $buckets[] = [
        'year_month' => gmdate('Y-m', $cursor),
        'start' => $cursor,
        'end' => $bucketEnd,
      ];
      $cursor = $next;
    }
    return $buckets;
  }

  /**
   * Archive a single month bucket. Returns a structured result.
   *
   * @return array{year_month: string, status: string, rows_moved: int, shard_path?: string, error?: string}
   */
  protected function archiveMonth(array $bucket, int $cutoffTime, string $storage, bool $dryRun, SymfonyStyle $io, bool $jsonMode): array
  {
    $ym = $bucket['year_month'];
    $start = $bucket['start'];
    $end = $bucket['end'];
    $shardPath = rtrim($storage, '/') . "/logs-$ym.db";

    // Count what would move.
    $stmt = $this->database->prepare('SELECT COUNT(*) FROM logs WHERE time >= :start AND time < :end');
    $stmt->execute([':start' => $start, ':end' => $end]);
    $toMove = (int) $stmt->fetchColumn();
    $stmt->closeCursor();
    $stmt = NULL;

    if ($toMove === 0) {
      return [
        'year_month' => $ym,
        'status' => 'empty',
        'rows_moved' => 0,
      ];
    }

    if (!$jsonMode) {
      $io->section("Month $ym");
      $io->text(sprintf('Shard: %s', $shardPath));
      $io->text(sprintf('Rows to move: %s', number_format($toMove)));
    }

    if ($dryRun) {
      return [
        'year_month' => $ym,
        'status' => 'dry_run',
        'rows_moved' => 0,
        'rows_would_move' => $toMove,
        'shard_path' => $shardPath,
      ];
    }

    try {
      // ATTACH the shard (creates the file if missing), ensure schema.
      $this->database->exec(sprintf("ATTACH DATABASE '%s' AS arc", str_replace("'", "''", $shardPath)));
      $this->ensureShardSchema('arc');

      // Copy with INSERT OR IGNORE for idempotency.
      $copyStmt = $this->database->prepare(<<<'SQL'
INSERT OR IGNORE INTO arc.logs
SELECT * FROM main.logs WHERE time >= :start AND time < :end
SQL
      );
      $copyStmt->execute([':start' => $start, ':end' => $end]);

      // Verify shard now has every row from this range. Close cursors
      // explicitly — leftover prepared statements block VACUUM at the end.
      $verifyMain = $this->database->prepare('SELECT COUNT(*) FROM main.logs WHERE time >= :start AND time < :end');
      $verifyMain->execute([':start' => $start, ':end' => $end]);
      $mainCount = (int) $verifyMain->fetchColumn();
      $verifyMain->closeCursor();
      $verifyMain = NULL;

      $verifyArc = $this->database->prepare('SELECT COUNT(*) FROM arc.logs WHERE time >= :start AND time < :end');
      $verifyArc->execute([':start' => $start, ':end' => $end]);
      $arcCount = (int) $verifyArc->fetchColumn();
      $verifyArc->closeCursor();
      $verifyArc = NULL;

      if ($arcCount < $mainCount) {
        throw new \RuntimeException("Verification failed: shard has $arcCount rows, main has $mainCount");
      }

      // Register / update archive_files entry.
      $sizeBytes = filesize($shardPath) ?: 0;
      $register = $this->database->prepare(<<<'SQL'
INSERT INTO archive_files (file_path, year_month, start_time, end_time, record_count, size_bytes)
VALUES (:path, :ym, :start, :end, :count, :size)
ON CONFLICT(file_path) DO UPDATE SET
  start_time = MIN(start_time, :start),
  end_time = MAX(end_time, :end),
  record_count = :count,
  size_bytes = :size,
  archived_at = CURRENT_TIMESTAMP
SQL
      );
      $register->execute([
        ':path' => $shardPath,
        ':ym' => $ym,
        ':start' => $start,
        ':end' => $end,
        ':count' => $arcCount,
        ':size' => $sizeBytes,
      ]);

      // Now safe to delete from main.
      $delete = $this->database->prepare('DELETE FROM main.logs WHERE time >= :start AND time < :end');
      $delete->execute([':start' => $start, ':end' => $end]);

      $this->database->exec('DETACH DATABASE arc');

      if (!$jsonMode) {
        $io->text(sprintf('  ✓ Moved %s rows to %s', number_format($arcCount), basename($shardPath)));
      }

      return [
        'year_month' => $ym,
        'status' => 'archived',
        'rows_moved' => $arcCount,
        'shard_path' => $shardPath,
      ];
    }
    catch (\Throwable $e) {
      try { $this->database->exec('DETACH DATABASE arc'); } catch (\Throwable $ignored) {}
      if (!$jsonMode) {
        $io->error(sprintf('Failed to archive %s: %s', $ym, $e->getMessage()));
      }
      return [
        'year_month' => $ym,
        'status' => 'error',
        'rows_moved' => 0,
        'error' => $e->getMessage(),
      ];
    }
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
