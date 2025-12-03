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
   * Create a new sync range tracking entry.
   *
   * Records the start of a sync operation for a specific time range.
   *
   * @param string $startTime Start of range (ISO 8601)
   * @param string $endTime End of range (ISO 8601)
   * @param int $chunksTotal Total number of chunks to process
   * @return int Sync range ID
   */
  public function createSyncRange(int $startTime, int $endTime, int $chunksTotal): int
  {
    $stmt = $this->database->prepare(<<<'SQL'
INSERT INTO sync_ranges (start_time, end_time, status, chunks_total)
VALUES (:start_time, :end_time, 'pending', :chunks_total)
SQL
    );

    $stmt->execute([
      ':start_time' => $startTime,
      ':end_time' => $endTime,
      ':chunks_total' => $chunksTotal,
    ]);

    return (int) $this->database->lastInsertId();
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

    // Second, auto-complete incomplete syncs that now have data.
    // Check if the time range has actual log data - if so, mark as completed.
    // This includes 'in_progress' syncs that were filled by subsequent syncs.
    $incompleteStmt = $this->database->query(<<<'SQL'
SELECT id, start_time, end_time FROM sync_ranges
WHERE status IN ('failed', 'interrupted', 'in_progress')
ORDER BY started_at DESC
SQL
    );

    $toComplete = [];
    while ($sync = $incompleteStmt->fetch(PDO::FETCH_ASSOC)) {
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

      // If we have data for this range, mark it for completion
      if ($result['count'] > 0) {
        $toComplete[] = $sync['id'];
      }
    }

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
    // Get the overall data range
    $stmt = $this->database->query(<<<'SQL'
SELECT MIN(time) as earliest, MAX(time) as latest, COUNT(*) as count
FROM logs
SQL
    );
    $coverage = $stmt->fetch(PDO::FETCH_ASSOC);

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
        'start' => $requestedStart,
        'end' => min($retentionStart, $requestedEnd),
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
        'start' => $requestedStart,
        'end' => $requestedEnd,
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
          'start' => $currentPosition,
          'end' => $rangeStart,
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
        'start' => $currentPosition,
        'end' => $requestedEnd,
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
            'start' => $requestedStart,
            'end' => $requestedEnd,
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
        'start' => $requestedStart,
        'end' => $coverage['earliest'],
        'reason' => 'historical',
      ];
    }

    // Range after existing data?
    if ($coverage['latest'] < $requestedEnd) {
      $ranges[] = [
        'start' => $coverage['latest'],
        'end' => $requestedEnd,
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
