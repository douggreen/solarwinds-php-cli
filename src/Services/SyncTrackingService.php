<?php

/**
 * @file SyncTrackingService.php
 * @brief Service for tracking sync operations and detecting coverage gaps
 *
 * @class SyncTrackingService
 * @brief Manages sync_ranges table and gap detection logic
 */

namespace SolarWinds\Services;

use PDO;

/**
 * Sync Tracking Service
 *
 * Handles sync range tracking and gap detection including:
 * - Recording sync operations
 * - Detecting missing data ranges
 * - Auto-recovery of interrupted syncs
 * - Coverage analysis
 */
class SyncTrackingService
{
  /**
   * Cache for earliest log date (per-session).
   * Set to 0 if database is empty.
   *
   * @var int|null
   */
  protected ?int $earliestLogDateCache = NULL;

  /**
   * Constructor.
   *
   * @param ConfigurationService $config Configuration service
   * @param DatabaseService $database Database service
   */
  public function __construct(
    protected ConfigurationService $config,
    protected DatabaseService $database
  ) {
  }

  /**
   * Get estimated record count for a time range.
   *
   * Returns approximate number of records in database for the specified time range.
   * Used for confirmation prompts to show users estimated query size.
   *
   * @param string $since Start time (ISO 8601 format)
   * @param string $until End time (ISO 8601 format)
   * @return int Estimated record count
   */
  public function getRecordCount(int $since, int $until): int
  {
    $sql = 'SELECT COUNT(*) as count FROM logs WHERE time >= :since AND time <= :until';
    $stmt = $this->database->prepare($sql);
    $stmt->execute([
      ':since' => $since,
      ':until' => $until,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['count'] ?? 0);
  }

  /**
   * Default chunk size for claimable work units.
   *
   * 1 hour is the parallelism granularity: large enough to keep API request
   * overhead modest on big backfills (336 chunks for a 14-day sync, ~50ms
   * setup each = ~17s extra total) but small enough that multi-hour gaps
   * naturally divide across cooperating processes. Smaller would parallelize
   * more aggressively at the cost of API throughput.
   */
  protected const DEFAULT_CHUNK_SECONDS = 3600;

  /**
   * Atomically claim the next available chunk of work inside [start, end].
   *
   * Walks the requested range for the first sub-window that isn't blocked
   * by an in_progress or completed sync, clips it to chunkSeconds, and
   * inserts a single sync_ranges row as 'in_progress'. Two concurrent
   * processes calling this method against the same range will end up
   * claiming different chunks — the unit of parallelism is the chunk,
   * not the gap — so a multi-hour gap can be drained cooperatively.
   *
   * Returns NULL when no unclaimed work remains in the requested range
   * (either fully covered by completed syncs or fully held by other
   * in_progress claims). Caller loops until NULL.
   *
   * Runs inside BEGIN IMMEDIATE so the find-and-claim is atomic.
   *
   * @param int $rangeStart Outer range start (unix timestamp)
   * @param int $rangeEnd Outer range end (unix timestamp)
   * @param int|null $chunkSeconds Max chunk size; defaults to DEFAULT_CHUNK_SECONDS
   * @return array{id: int, start_time: int, end_time: int}|null
   */
  public function claimNextChunk(int $rangeStart, int $rangeEnd, ?int $chunkSeconds = NULL): ?array
  {
    if ($rangeStart >= $rangeEnd) {
      return NULL;
    }
    $chunkSeconds = $chunkSeconds ?? static::DEFAULT_CHUNK_SECONDS;

    // BEGIN IMMEDIATE acquires the writer lock at transaction start; without
    // it, two concurrent processes could both pass the overlap check before
    // either insert lands, and both would claim the same range.
    $this->database->exec('BEGIN IMMEDIATE');
    try {
      // Find every in_progress or completed range that overlaps the request.
      // Including completed ones closes the TOCTOU window where a sync could
      // complete between detectMissingRanges and claimNextChunk.
      $stmt = $this->database->prepare(<<<'SQL'
SELECT start_time, end_time
FROM sync_ranges
WHERE status IN ('in_progress', 'completed')
  AND CAST(start_time AS INTEGER) < :req_end
  AND CAST(end_time AS INTEGER) > :req_start
ORDER BY CAST(start_time AS INTEGER) ASC
SQL
      );
      $stmt->execute([
        ':req_start' => $rangeStart,
        ':req_end' => $rangeEnd,
      ]);
      $blockers = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // The first free sub-range is the next chunk's natural home. We take
      // just the first one rather than claiming everything free — that way a
      // sibling process can grab the next free piece while we work on this.
      $subRanges = $this->subtractActiveRanges($rangeStart, $rangeEnd, $blockers);
      if (empty($subRanges)) {
        $this->database->exec('COMMIT');
        return NULL;
      }

      [$freeStart, $freeEnd] = $subRanges[0];
      $chunkEnd = min($freeEnd, $freeStart + $chunkSeconds);

      $insertStmt = $this->database->prepare(<<<'SQL'
INSERT INTO sync_ranges (start_time, end_time, status, started_at, chunks_total)
VALUES (:start, :end, 'in_progress', CURRENT_TIMESTAMP, 1)
SQL
      );
      $insertStmt->execute([
        ':start' => $freeStart,
        ':end' => $chunkEnd,
      ]);
      $id = (int) $this->database->lastInsertId();

      $this->database->exec('COMMIT');
      return [
        'id' => $id,
        'start_time' => $freeStart,
        'end_time' => $chunkEnd,
      ];
    }
    catch (\Throwable $e) {
      $this->database->exec('ROLLBACK');
      throw $e;
    }
  }

  /**
   * Compute non-overlapping sub-ranges of [$start, $end] after subtracting
   * each blocker interval.
   *
   * Standard interval-subtraction sweep: walk blockers left-to-right, emit
   * the free gap before each blocker, advance the cursor past it. Final tail
   * after the last blocker is emitted as well.
   *
   * @param int $start Desired range start
   * @param int $end Desired range end
   * @param array<array{start_time: int|string, end_time: int|string}> $blockers
   *   Blockers as returned by claimNextChunk's overlap query; values are coerced
   *   to int to handle SQLite TEXT-affinity columns.
   * @return array<int, array{0: int, 1: int}> Sub-ranges as [start, end] pairs
   */
  protected function subtractActiveRanges(int $start, int $end, array $blockers): array
  {
    $intervals = [];
    foreach ($blockers as $b) {
      $intervals[] = [
        (int) $b['start_time'],
        (int) $b['end_time'],
      ];
    }
    usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);

    $result = [];
    $cursor = $start;
    foreach ($intervals as [$bStart, $bEnd]) {
      if ($bEnd <= $cursor) {
        continue;
      }
      if ($bStart >= $end) {
        break;
      }
      if ($bStart > $cursor) {
        $result[] = [
          $cursor,
          $bStart,
        ];
      }
      $cursor = max($cursor, $bEnd);
    }
    if ($cursor < $end) {
      $result[] = [
        $cursor,
        $end,
      ];
    }
    return $result;
  }


  /**
   * Update sync range status.
   *
   * @param int $syncId Sync range ID
   * @param string $status New status ('pending', 'in_progress', 'completed', 'failed', 'interrupted')
   * @param string|null $errorMessage Optional error message for failed status
   */
  public function updateSyncStatus(int $syncId, string $status, ?string $errorMessage = NULL): void
  {
    $sql = 'UPDATE sync_ranges SET status = :status';

    if ($status === 'completed') {
      $sql .= ', completed_at = CURRENT_TIMESTAMP';
    }

    if ($errorMessage !== NULL) {
      $sql .= ', error_message = :error';
    }

    $sql .= ' WHERE id = :id';

    $params = [
      ':status' => $status,
      ':id' => $syncId,
    ];

    if ($errorMessage !== NULL) {
      $params[':error'] = $errorMessage;
    }

    $stmt = $this->database->prepare($sql);
    $stmt->execute($params);
  }

  /**
   * Update sync range progress.
   *
   * @param int $syncId Sync range ID
   * @param int $recordsInserted Number of records inserted so far
   * @param int $chunksCompleted Number of chunks completed
   */
  public function updateSyncProgress(int $syncId, int $recordsInserted, int $chunksCompleted): void
  {
    $stmt = $this->database->prepare(<<<'SQL'
UPDATE sync_ranges
SET records_inserted = :records,
    chunks_completed = :chunks
WHERE id = :id
SQL
    );

    $stmt->execute([
      ':records' => $recordsInserted,
      ':chunks' => $chunksCompleted,
      ':id' => $syncId,
    ]);
  }

  /**
   * Get incomplete sync ranges.
   *
   * Returns sync ranges that were interrupted or failed.
   *
   * @return array Array of incomplete sync range records
   */
  public function getIncompleteSyncRanges(): array
  {
    // First, auto-recover stale "in_progress" syncs (older than 1 hour)
    // These are likely from crashed processes or interrupted sessions
    $this->database->exec(<<<'SQL'
UPDATE sync_ranges
SET status = 'interrupted',
    completed_at = CURRENT_TIMESTAMP,
    error_message = 'Auto-recovered: stale in_progress sync'
WHERE status = 'in_progress'
  AND datetime(started_at, '+1 hour') < datetime('now')
SQL
    );

    // Delete failed/interrupted/in_progress rows whose exact (start_time,
    // end_time) range already has a completed sibling. Otherwise the
    // auto-complete UPDATE below would try to flip these to 'completed' and
    // collide with the unique index on (start_time, end_time, status). This
    // happens normally whenever a chunk fails and is successfully reclaimed
    // by a later sync — the old failed row becomes redundant.
    $this->database->exec(<<<'SQL'
DELETE FROM sync_ranges
WHERE status IN ('failed', 'interrupted', 'in_progress')
  AND EXISTS (
    SELECT 1 FROM sync_ranges AS done
    WHERE done.status = 'completed'
      AND done.start_time = sync_ranges.start_time
      AND done.end_time = sync_ranges.end_time
      AND done.id != sync_ranges.id
  )
SQL
    );

    // Second, auto-complete incomplete syncs that now have data.
    // Check if the time range has actual log data - if so, mark as completed.
    // This includes 'in_progress' syncs that were filled by subsequent syncs.
    //
    // IMPORTANT: drain all SELECT results into arrays BEFORE running the
    // closing UPDATE. An open cursor holds an implicit read transaction, and
    // SQLite refuses to upgrade a read txn to a write txn while another
    // process holds the writer lock — it returns SQLITE_BUSY immediately
    // (no busy_timeout retry, because retrying could deadlock). The only
    // way the closing UPDATE can wait on busy_timeout is if no read cursors
    // are open when it starts.
    $incompleteStmt = $this->database->query(<<<'SQL'
SELECT id, start_time, end_time FROM sync_ranges
WHERE status IN ('failed', 'interrupted', 'in_progress')
ORDER BY started_at DESC
SQL
    );
    $incomplete = $incompleteStmt->fetchAll(PDO::FETCH_ASSOC);
    $incompleteStmt = NULL;

    $toComplete = [];
    foreach ($incomplete as $sync) {
      // Check if this range has any log data
      $checkStmt = $this->database->prepare(<<<'SQL'
SELECT COUNT(*) as count FROM logs
WHERE time >= :start AND time <= :end
LIMIT 1
SQL
      );
      $checkStmt->execute([
        ':start' => $sync['start_time'],
        ':end' => $sync['end_time'],
      ]);
      $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
      $checkStmt->closeCursor();

      // If we have data for this range, mark it for completion
      if ($result['count'] > 0) {
        $toComplete[] = $sync['id'];
      }
    }
    $checkStmt = NULL;

    // Mark all recovered syncs as completed
    if (!empty($toComplete)) {
      $ids = implode(',', $toComplete);
      $this->database->exec(<<<SQL
UPDATE sync_ranges
SET status = 'completed',
    completed_at = CURRENT_TIMESTAMP,
    error_message = 'Auto-completed: data recovered by subsequent sync'
WHERE id IN ($ids)
SQL
      );
    }

    $stmt = $this->database->query(<<<'SQL'
SELECT * FROM sync_ranges
WHERE status IN ('pending', 'in_progress', 'failed', 'interrupted')
ORDER BY started_at DESC
SQL
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Initialize sync_ranges from existing log data.
   *
   * Called when sync_ranges is empty but logs table has data.
   * Analyzes continuous data ranges and creates sync_range entries for them.
   * This allows gap detection to work correctly for pre-existing data.
   */
  public function initializeSyncRangesFromData(): void
  {
    // Get the overall data range. Close the cursor explicitly before the
    // subsequent INSERT — SQLite refuses to upgrade a read txn to a write txn
    // while another process holds the writer lock (returns SQLITE_BUSY
    // immediately, no busy_timeout retry), so any open read cursor needs to
    // be finalized first.
    $stmt = $this->database->query(<<<'SQL'
SELECT MIN(time) as earliest, MAX(time) as latest, COUNT(*) as count
FROM logs
SQL
    );
    $coverage = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $stmt = NULL;

    if ($coverage['count'] == 0 || $coverage['earliest'] === NULL) {
      // No data to initialize from
      return;
    }

    // For simplicity, create a single sync_range entry covering the entire data range
    // This assumes the historical data is continuous (which is usually the case)
    $this->database->prepare(<<<'SQL'
INSERT INTO sync_ranges (start_time, end_time, status, started_at, completed_at, records_inserted, error_message)
VALUES (:start, :end, 'completed', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :count, 'Initialized from existing data')
SQL
    )->execute([
      ':start' => $coverage['earliest'],
      ':end' => $coverage['latest'],
      ':count' => $coverage['count'],
    ]);
  }

  /**
   * Detect gaps in synced data using sync_ranges tracking.
   *
   * Analyzes completed sync ranges to find gaps between them and at the edges
   * of the requested time range. This is more reliable than analyzing log density.
   *
   * @param string $requestedStart Start of requested range (ISO 8601)
   * @param string $requestedEnd End of requested range (ISO 8601)
   * @return array Array of gap ranges that need to be synced
   */
  public function detectGapsInSyncedRanges(int $requestedStart, int $requestedEnd): array
  {
    // Clamp requested start time to API retention limit
    // E.g., if retention is 14 days and user requests 1 month, only fetch last 14 days
    $retentionLimit = $this->config->getApiRetentionLimit();
    $retentionStart = time() - $retentionLimit;

    // If requested start is before retention limit, mark that range as beyond retention
    $gapsBeforeRetention = [];
    if ($requestedStart < $retentionStart) {
      $gapsBeforeRetention[] = [
        'start_time' => $requestedStart,
        'end_time' => min($retentionStart, $requestedEnd),
        'reason' => 'beyond_retention',
      ];
      // Adjust requested start to retention limit for actual fetching
      $requestedStart = $retentionStart;
    }

    // If entire range is before retention limit, return early
    if ($requestedStart >= $requestedEnd) {
      return $gapsBeforeRetention;
    }

    // Get all completed sync ranges that overlap with requested range.
    $stmt = $this->database->prepare(<<<'SQL'
SELECT start_time, end_time
FROM sync_ranges
WHERE status = 'completed'
  AND end_time >= :req_start
  AND start_time <= :req_end
ORDER BY start_time ASC
SQL
    );

    $stmt->execute([
      ':req_start' => $requestedStart,
      ':req_end' => $requestedEnd,
    ]);

    $completedRanges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // No completed syncs - need entire range.
    if (empty($completedRanges)) {
      return [[
        'start_time' => $requestedStart,
        'end_time' => $requestedEnd,
        'reason' => 'no_sync_history',
      ]];
    }

    // Find gaps between completed ranges.
    $gaps = [];
    $currentPosition = $requestedStart;

    foreach ($completedRanges as $range) {
      $rangeStart = $range['start_time'];
      $rangeEnd = $range['end_time'];

      // Gap before this range?
      if ($currentPosition < $rangeStart) {
        $gaps[] = [
          'start_time' => $currentPosition,
          'end_time' => $rangeStart,
          'reason' => 'gap_between_syncs',
        ];
      }

      // Move position forward to end of this range.
      if ($rangeEnd > $currentPosition) {
        $currentPosition = $rangeEnd;
      }
    }

    // Gap after last range?
    if ($currentPosition < $requestedEnd) {
      $gaps[] = [
        'start_time' => $currentPosition,
        'end_time' => $requestedEnd,
        'reason' => 'recent_data',
      ];
    }

    // Merge beyond-retention gaps with regular gaps
    return array_merge($gapsBeforeRetention, $gaps);
  }

  /**
   * Detect missing data ranges using sync tracking or log analysis.
   *
   * Preferred method: Uses sync_ranges table to detect gaps (if available).
   * Fallback method: Analyzes log density for beginning/end gaps only.
   *
   * The sync_ranges method is superior because it:
   * - Detects gaps in the middle (not just edges)
   * - Knows about interrupted syncs
   * - Distinguishes "no data" from "not synced"
   *
   * @param string $requestedStart Start of requested range (ISO 8601)
   * @param string $requestedEnd End of requested range (ISO 8601)
   * @return array Array with 'has_data', 'coverage' info and 'ranges' (missing ranges) to fetch
   */
  public function detectMissingRanges(int $requestedStart, int $requestedEnd): array
  {
    // Check if sync_ranges table exists and has data.
    $hasSyncRanges = FALSE;
    try {
      $stmt = $this->database->query("SELECT COUNT(*) as count FROM sync_ranges WHERE status = 'completed'");
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $hasSyncRanges = ($row['count'] ?? 0) > 0;

      // If sync_ranges is empty but logs table has data, initialize from existing data
      if (!$hasSyncRanges) {
        $logStmt = $this->database->query("SELECT COUNT(*) as count FROM logs");
        $logRow = $logStmt->fetch(PDO::FETCH_ASSOC);
        if (($logRow['count'] ?? 0) > 0) {
          $this->initializeSyncRangesFromData();
          $hasSyncRanges = TRUE;
        }
      }
    }
    catch (\PDOException $e) {
      // Table doesn't exist yet - fall back to log analysis.
      $hasSyncRanges = FALSE;
    }

    // Use sync_ranges tracking if available (preferred method).
    if ($hasSyncRanges) {
      $gaps = $this->detectGapsInSyncedRanges($requestedStart, $requestedEnd);

      // Calculate coverage from sync_ranges instead of expensive logs table query.
      // Get all completed syncs that overlap with requested range.
      $stmt = $this->database->prepare(<<<'SQL'
SELECT MIN(start_time) as earliest, MAX(end_time) as latest, SUM(records_inserted) as count
FROM sync_ranges
WHERE status = 'completed'
  AND end_time >= :req_start
  AND start_time <= :req_end
SQL
      );
      $stmt->execute([
        ':req_start' => $requestedStart,
        ':req_end' => $requestedEnd,
      ]);
      $coverage = $stmt->fetch(PDO::FETCH_ASSOC);

      // Handle case where no completed syncs overlap (shouldn't happen after initialization)
      if ($coverage['count'] === NULL) {
        $coverage = [
          'earliest' => NULL,
          'latest' => NULL,
          'count' => 0,
        ];
      }

      return [
        'has_data' => $coverage['count'] > 0,
        'coverage' => $coverage,
        'ranges' => $gaps,
        'method' => 'sync_tracking',
      ];
    }

    // Fall back to old method: analyze log density (edges only).
    // Get the actual time range covered by data in database.
    $stmt = $this->database->prepare(<<<'SQL'
SELECT MIN(time) as earliest, MAX(time) as latest, COUNT(*) as count
FROM logs
WHERE time >= :start AND time <= :end
SQL
    );
    $stmt->execute([
      ':start' => $requestedStart,
      ':end' => $requestedEnd,
    ]);
    $coverage = $stmt->fetch(PDO::FETCH_ASSOC);

    // No data at all - need to fetch entire range.
    if ($coverage['count'] == 0 || $coverage['earliest'] === NULL) {
      return [
        'has_data' => FALSE,
        'coverage' => [
          'earliest' => NULL,
          'latest' => NULL,
          'count' => 0,
        ],
        'ranges' => [
          [
            'start_time' => $requestedStart,
            'end_time' => $requestedEnd,
            'reason' => 'no_data',
          ],
        ],
        'method' => 'log_analysis',
      ];
    }

    // We have some data - check for ranges at the beginning and/or end.
    $ranges = [];

    // Range before existing data?
    if ($coverage['earliest'] > $requestedStart) {
      $ranges[] = [
        'start_time' => $requestedStart,
        'end_time' => $coverage['earliest'],
        'reason' => 'historical',
      ];
    }

    // Range after existing data?
    if ($coverage['latest'] < $requestedEnd) {
      $ranges[] = [
        'start_time' => $coverage['latest'],
        'end_time' => $requestedEnd,
        'reason' => 'recent',
      ];
    }

    return [
      'has_data' => TRUE,
      'coverage' => $coverage,
      'ranges' => $ranges,
      'method' => 'log_analysis',
    ];
  }

  /**
   * Get earliest log timestamp in database.
   *
   * Used for deep-dive queries to determine how far back to search.
   *
   * @return int Unix timestamp (0 if no logs)
   */
  public function getEarliestLogDate(): int
  {
    // Return cached value if available (result never changes during session).
    if ($this->earliestLogDateCache !== NULL) {
      return $this->earliestLogDateCache;
    }

    $stmt = $this->database->query('SELECT MIN(time) as earliest FROM logs');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $this->earliestLogDateCache = $row['earliest'] ?? 0;

    return $this->earliestLogDateCache;
  }
}
