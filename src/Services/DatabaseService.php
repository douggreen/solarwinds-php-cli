<?php

/**
 * @file DatabaseService.php
 * @brief SQLite database service for efficient HTTP log storage and querying
 *
 * @class DatabaseService
 * @brief Manages SQLite database for storing and querying HTTP logs
 *
 * Replaces JSON-based caching with indexed SQLite database for:
 * - Fast IP address lookups (for volume analysis)
 * - Fast URI pattern matching (for exploit detection)
 * - Pre-parsed URL arguments (for efficient pattern matching)
 * - Compressed storage (SQLite is more efficient than JSON)
 * - Scalable to months of data (not just 2 weeks)
 */

namespace SolarWinds\Services;

use PDO;
use PDOException;

/**
 * Database Service - SQLite storage for HTTP logs
 *
 * Provides indexed storage and efficient querying of HTTP traffic logs.
 */
class DatabaseService
{
  protected PDO $db;
  protected string $dbPath;

  /**
   * Constructor.
   *
   * Initializes database connection and ensures schema exists.
   *
   * @param ConfigurationService $config Configuration service
   * @throws \RuntimeException If database initialization fails
   */
  public function __construct(protected ConfigurationService $config)
  {
    // Get database path from configuration.
    $this->dbPath = $config->getDatabasePath();

    // Ensure parent directory exists.
    $dbDir = dirname($this->dbPath);
    if (!is_dir($dbDir)) {
      mkdir($dbDir, 0755, TRUE);
    }

    $this->initializeDatabase();
  }

  /**
   * Initialize SQLite database and create schema if needed.
   *
   * Sets up PDO connection with WAL mode for better concurrency.
   *
   * @throws \RuntimeException If database initialization fails
   */
  protected function initializeDatabase(): void
  {
    try {
      $this->db = new PDO('sqlite:' . $this->dbPath);
      $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

      // Enable WAL mode for better concurrent access.
      $this->db->exec('PRAGMA journal_mode=WAL');

      // Create schema if it doesn't exist.
      $this->createSchema();
    }
    catch (PDOException $e) {
      throw new \RuntimeException('Failed to initialize database: ' . $e->getMessage());
    }
  }

  /**
   * Create database schema.
   *
   * Uses JSON column to store heterogeneous log types (HTTP logs, Drupal logs, etc.)
   * with generated columns for common indexed fields.
   *
   * Creates logs table with generated virtual columns and indexes for fast queries.
   *
   * Only creates indexes for columns that exist - safe for both new and existing databases.
   *
   * Generated columns:
   * - retrieved_at: Timestamp when log was fetched from API (for freshness tracking)
   * - url_arguments: Pre-parsed JSON of URL query parameters (for fast exploit detection)
   * - program: Program name from syslog/Drupal logs
   * - orig_host: Original host/site name (commonly used for grouping)
   * - message: Log message text (Drupal message field)
   * - hostname: Hostname field (alternative to orig_host)
   * - country: Country code from geoip data
   * - city: City name from geoip data
   */
  protected function createSchema(): void
  {
    // Create table with all columns for new databases.
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS logs (
  id TEXT PRIMARY KEY,
  time TEXT NOT NULL,
  retrieved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data JSON NOT NULL,
  client_ip TEXT GENERATED ALWAYS AS (json_extract(data, '$.client_ip')) VIRTUAL,
  req_method TEXT GENERATED ALWAYS AS (json_extract(data, '$.req_method')) VIRTUAL,
  req_uri TEXT GENERATED ALWAYS AS (json_extract(data, '$.req_uri')) VIRTUAL,
  req_user_agent TEXT GENERATED ALWAYS AS (json_extract(data, '$.req_user_agent')) VIRTUAL,
  resp_status INTEGER GENERATED ALWAYS AS (json_extract(data, '$.resp_status')) VIRTUAL,
  log_type TEXT GENERATED ALWAYS AS (json_extract(data, '$.type')) VIRTUAL,
  log_severity TEXT GENERATED ALWAYS AS (json_extract(data, '$.severity')) VIRTUAL,
  program TEXT GENERATED ALWAYS AS (json_extract(data, '$.program')) VIRTUAL,
  orig_host TEXT GENERATED ALWAYS AS (json_extract(data, '$.orig_host')) VIRTUAL,
  hostname TEXT GENERATED ALWAYS AS (json_extract(data, '$.hostname')) VIRTUAL,
  message TEXT GENERATED ALWAYS AS (json_extract(data, '$.message')) VIRTUAL,
  country TEXT GENERATED ALWAYS AS (COALESCE(
    json_extract(data, '$.geoip.country_code2'),
    json_extract(data, '$.geoip.country_name'),
    json_extract(data, '$.country'),
    json_extract(data, '$.geo.country')
  )) VIRTUAL,
  city TEXT GENERATED ALWAYS AS (COALESCE(
    json_extract(data, '$.geoip.city_name'),
    json_extract(data, '$.city'),
    json_extract(data, '$.geo.city')
  )) VIRTUAL,
  url_arguments JSON GENERATED ALWAYS AS (json_extract(data, '$.url_arguments')) VIRTUAL,
  base_path TEXT GENERATED ALWAYS AS (json_extract(data, '$.base_path')) VIRTUAL
);
SQL;

    $this->db->exec($sql);

    // Create sync_ranges tracking table.
    $syncRangesSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_ranges (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  start_time TEXT NOT NULL,
  end_time TEXT NOT NULL,
  status TEXT NOT NULL CHECK(status IN ('pending', 'in_progress', 'completed', 'failed', 'interrupted')),
  started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TEXT,
  records_expected INTEGER,
  records_inserted INTEGER DEFAULT 0,
  chunks_total INTEGER,
  chunks_completed INTEGER DEFAULT 0,
  error_message TEXT
);
SQL;
    $this->db->exec($syncRangesSql);

    // Create indexes for sync_ranges.
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sync_status ON sync_ranges(status)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sync_times ON sync_ranges(start_time, end_time)");
    $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sync_range_unique ON sync_ranges(start_time, end_time, status) WHERE status IN ('in_progress', 'completed')");

    // Create campaign_analysis table for storing exploit campaign analysis.
    $campaignAnalysisSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS campaign_analysis (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT NOT NULL,
  country TEXT,

  -- Analysis timeframe
  time_start TEXT NOT NULL,
  time_end TEXT NOT NULL,
  time_range TEXT NOT NULL,
  analyzed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Campaign metrics
  total_requests INTEGER NOT NULL,
  exploit_requests INTEGER NOT NULL,
  first_seen TEXT,
  last_seen TEXT,
  time_span_days REAL,

  -- Attack patterns (JSON arrays)
  attack_types TEXT,
  severity TEXT,
  top_paths TEXT,

  -- Behavior analysis
  behavior_type TEXT,
  request_rate REAL,
  ratio_40x REAL,
  ratio_exploit REAL,
  path_diversity REAL,
  uri_dup_ratio REAL,

  -- Blocking recommendation
  should_block INTEGER,
  confidence TEXT,
  block_reasons TEXT,

  -- Context
  user_agent TEXT,
  bot_name TEXT,
  total_volume INTEGER,
  ratio_edge_blocked REAL,

  -- Deep dive flag
  from_deep_dive INTEGER DEFAULT 0,

  UNIQUE(ip, time_start, time_end)
);
SQL;
    $this->db->exec($campaignAnalysisSql);

    // Create indexes for campaign_analysis.
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_campaign_ip ON campaign_analysis(ip)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_campaign_analyzed_at ON campaign_analysis(analyzed_at)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_campaign_confidence ON campaign_analysis(confidence)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_campaign_time_range ON campaign_analysis(time_range)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_campaign_last_seen ON campaign_analysis(last_seen)");

    // Create ip_blocklist table for tracking blocking decisions.
    $ipBlocklistSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS ip_blocklist (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT NOT NULL UNIQUE,

  -- Decision tracking
  added_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  added_by TEXT,
  reason TEXT,

  -- Block status
  status TEXT NOT NULL DEFAULT 'active',
  expires_at TEXT,

  -- Reference to analysis
  campaign_analysis_id INTEGER,

  FOREIGN KEY(campaign_analysis_id) REFERENCES campaign_analysis(id)
);
SQL;
    $this->db->exec($ipBlocklistSql);

    // Create indexes for ip_blocklist.
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_blocklist_status ON ip_blocklist(status)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_blocklist_ip ON ip_blocklist(ip)");

    // Create realtime_alerts table for 15-minute scan alerts.
    $realtimeAlertsSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS realtime_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT NOT NULL,

  -- Alert details
  detected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  alert_type TEXT NOT NULL,
  severity TEXT NOT NULL,

  -- Metrics that triggered alert
  time_window TEXT NOT NULL,
  requests_in_window INTEGER,
  request_rate REAL,
  attack_types TEXT,

  -- Context
  in_historical_analysis INTEGER DEFAULT 0,
  deep_dive_performed INTEGER DEFAULT 0,

  -- Status
  status TEXT NOT NULL DEFAULT 'new',
  acknowledged_at TEXT,
  acknowledged_by TEXT
);
SQL;
    $this->db->exec($realtimeAlertsSql);

    // Create indexes for realtime_alerts.
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_detected_at ON realtime_alerts(detected_at)");

    // Create bot_ip_ranges table for verified bot IP ranges.
    $botIpRangesSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS bot_ip_ranges (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  bot_name TEXT NOT NULL,              -- 'googlebot', 'bingbot', 'facebookbot', etc.
  ip_range TEXT NOT NULL,              -- CIDR notation: '66.249.64.0/19'
  source TEXT,                         -- GitHub URL or source identifier
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at TEXT                     -- When we last verified this range is still valid
);
SQL;
    $this->db->exec($botIpRangesSql);

    // Create indexes for bot_ip_ranges.
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_bot_ip_bot_name ON bot_ip_ranges(bot_name)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_bot_ip_updated ON bot_ip_ranges(updated_at)");

    // Create bot_ip_metadata table for tracking bot IP list updates.
    $botIpMetadataSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS bot_ip_metadata (
  bot_name TEXT PRIMARY KEY,
  source_url TEXT NOT NULL,            -- GitHub raw URL to fetch from
  last_checked TEXT NOT NULL,          -- When we last checked for updates
  last_updated TEXT NOT NULL,          -- When we last successfully updated ranges
  range_count INTEGER NOT NULL DEFAULT 0,
  enabled INTEGER NOT NULL DEFAULT 1   -- Allow disabling specific bot verification
);
SQL;
    $this->db->exec($botIpMetadataSql);

    // Create index for bot_ip_metadata.
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_bot_metadata_last_checked ON bot_ip_metadata(last_checked)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_status ON realtime_alerts(status)");
    $this->db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_ip ON realtime_alerts(ip)");

    // Get list of existing columns to avoid creating indexes on non-existent columns.
    $existingColumns = [];
    $result = $this->db->query("PRAGMA table_info(logs)");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
      $existingColumns[] = $row['name'];
    }

    // Create indexes only for columns that exist.
    $indexDefinitions = [
      'idx_time' => 'time',
      'idx_client_ip' => 'client_ip',
      'idx_req_method' => 'req_method',
      'idx_resp_status' => 'resp_status',
      'idx_log_type' => 'log_type',
      'idx_log_severity' => 'log_severity',
      'idx_program' => 'program',
      'idx_orig_host' => 'orig_host',
      'idx_hostname' => 'hostname',
      'idx_country' => 'country',
      'idx_city' => 'city',
      'idx_base_path' => 'base_path',
    ];

    foreach ($indexDefinitions as $indexName => $columnName) {
      if (in_array($columnName, $existingColumns)) {
        $this->db->exec("CREATE INDEX IF NOT EXISTS $indexName ON logs($columnName) WHERE $columnName IS NOT NULL");
      }
    }
  }

  /**
   * Migrate database schema to add new columns and indexes.
   *
   * This adds new VIRTUAL columns and indexes to an existing database.
   * Safe to run multiple times - uses IF NOT EXISTS.
   *
   * @return array Statistics about the migration (columns_added, indexes_created)
   */
  public function migrateSchema(): array
  {
    $stats = ['columns_added' => 0, 'indexes_created' => 0];

    // Get list of existing columns.
    $existingColumns = [];
    $result = $this->db->query("PRAGMA table_info(logs)");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
      $existingColumns[] = $row['name'];
    }

    // List of new columns to add.
    $newColumns = [
      'program' => "TEXT GENERATED ALWAYS AS (json_extract(data, '\$.program')) VIRTUAL",
      'orig_host' => "TEXT GENERATED ALWAYS AS (json_extract(data, '\$.orig_host')) VIRTUAL",
      'hostname' => "TEXT GENERATED ALWAYS AS (json_extract(data, '\$.hostname')) VIRTUAL",
      'message' => "TEXT GENERATED ALWAYS AS (json_extract(data, '\$.message')) VIRTUAL",
      'country' => "TEXT GENERATED ALWAYS AS (COALESCE(json_extract(data, '\$.geoip.country_code2'), json_extract(data, '\$.geoip.country_name'), json_extract(data, '\$.country'), json_extract(data, '\$.geo.country'))) VIRTUAL",
      'city' => "TEXT GENERATED ALWAYS AS (COALESCE(json_extract(data, '\$.geoip.city_name'), json_extract(data, '\$.city'), json_extract(data, '\$.geo.city'))) VIRTUAL",
      'base_path' => "TEXT GENERATED ALWAYS AS (json_extract(data, '\$.base_path')) VIRTUAL",
    ];

    // Add missing columns.
    foreach ($newColumns as $columnName => $columnDef) {
      if (!in_array($columnName, $existingColumns)) {
        try {
          $this->db->exec("ALTER TABLE logs ADD COLUMN $columnName $columnDef");
          $stats['columns_added']++;
        }
        catch (PDOException $e) {
          // Column might already exist or other error - continue.
        }
      }
    }

    // Create indexes for new columns.
    // Note: message field has no index - LIKE '%text%' queries don't benefit from indexes.
    $indexes = [
      'idx_program' => 'program',
      'idx_orig_host' => 'orig_host',
      'idx_hostname' => 'hostname',
      'idx_country' => 'country',
      'idx_city' => 'city',
      'idx_base_path' => 'base_path',
    ];

    foreach ($indexes as $indexName => $columnName) {
      $this->db->exec("CREATE INDEX IF NOT EXISTS $indexName ON logs($columnName) WHERE $columnName IS NOT NULL");
      $stats['indexes_created']++;
    }

    return $stats;
  }

  /**
   * Insert log entries into database.
   *
   * Stores entire log as JSON. Generated columns automatically extract indexed fields.
   * Parses URL query parameters into url_arguments column for fast exploit detection.
   *
   * @param array $logs Array of log entries from SolarWinds API
   * @return array Array with 'inserted' count and 'fixed' count
   */
  public function insertLogs(array $logs): array
  {
    if (empty($logs)) {
      return ['inserted' => 0, 'fixed' => 0];
    }

    $this->db->beginTransaction();

    try {
      // Prepare statement for checking existence (fast PRIMARY KEY lookup)
      $checkStmt = $this->db->prepare('SELECT 1 FROM logs WHERE id = :id LIMIT 1');

      // Prepare statement for insertion
      $stmt = $this->db->prepare(<<<'SQL'
INSERT OR REPLACE INTO logs (id, time, data)
VALUES (:id, :time, :data)
SQL
      );

      $inserted = 0;
      $fixed = 0;
      foreach ($logs as $log) {
        $logId = $log['id'] ?? '';

        // Fast check: does this ID already exist?
        $checkStmt->execute([':id' => $logId]);
        if ($checkStmt->fetchColumn() !== FALSE) {
          // Already exists - skip expensive JSON processing
          continue;
        }

        $message = $log['message'] ?? '';

        // Decode JSON once and handle errors inline.
        $logData = NULL;
        if (!empty($message)) {
          $logData = json_decode($message, TRUE);

          // If decode failed, try to fix malformed JSON.
          if (json_last_error() !== JSON_ERROR_NONE) {
            $fixed++;
            // Try to fix control characters by removing them.
            $cleanedMessage = preg_replace('/[\x00-\x1F\x7F]/u', '', $message);
            $logData = json_decode($cleanedMessage, TRUE);

            if (json_last_error() !== JSON_ERROR_NONE) {
              // Last resort: store the original as a JSON-escaped string.
              $message = json_encode(['raw' => $message, 'error' => json_last_error_msg()], JSON_INVALID_UTF8_SUBSTITUTE);
              $logData = NULL;
            }
            else {
              $message = $cleanedMessage;
            }
          }
        }

        // Inject URL arguments and base path into JSON data for fast exploit detection.
        if ($logData && isset($logData['req_uri'])) {
          $urlParts = $this->parseUriComponents($logData['req_uri']);
          if ($urlParts) {
            if ($urlParts['base_path'] !== NULL) {
              $logData['base_path'] = $urlParts['base_path'];
            }
            if ($urlParts['url_arguments'] !== NULL) {
              $logData['url_arguments'] = $urlParts['url_arguments'];
            }
            // Use JSON_INVALID_UTF8_SUBSTITUTE to handle URIs with special characters.
            $message = json_encode($logData, JSON_INVALID_UTF8_SUBSTITUTE);
          }
        }

        $stmt->execute([
          ':id' => $log['id'] ?? '',
          ':time' => $log['time'] ?? '',
          ':data' => $message,  // Store JSON with url_arguments injected
        ]);

        $inserted++;
      }

      $this->db->commit();

      // Return count of fixed entries for caller to track.
      return ['inserted' => $inserted, 'fixed' => $fixed];
    }
    catch (PDOException $e) {
      $this->db->rollBack();
      throw new \RuntimeException('Failed to insert logs: ' . $e->getMessage());
    }
  }

  /**
   * Get database connection.
   *
   * @return PDO Database connection
   * @todo Refactor architecture: DatabaseService should only handle connections/queries,
   *       not application logic. Move campaign analysis, bot IP operations, and sync
   *       tracking to dedicated domain services (CampaignAnalysisService, BotIpService,
   *       SyncTrackingService). See TODO.md for details.
   */
  public function getConnection(): PDO
  {
    return $this->db;
  }

  /**
   * Get logs from database.
   *
   * @param string|null $since Start time (ISO 8601)
   * @param string|null $until End time (ISO 8601)
   * @return array Array of log entries in original format
   */
  public function getLogs(?string $since = NULL, ?string $until = NULL): array
  {
    return $this->getLogsWithQuery(NULL, [], $since, $until);
  }

  /**
   * Get logs from database with SQL WHERE clause filtering.
   *
   * @param string|null $whereClause SQL WHERE clause (without WHERE keyword)
   * @param array $whereParams PDO parameters for WHERE clause
   * @param string|null $since Start time (ISO 8601)
   * @param string|null $until End time (ISO 8601)
   * @return array Array of log entries in original format
   */
  public function getLogsWithQuery(?string $whereClause, array $whereParams, ?string $since = NULL, ?string $until = NULL): array
  {
    $sql = 'SELECT id, time, data FROM logs WHERE 1=1';
    $params = [];

    if ($since) {
      $sql .= ' AND time >= :since';
      $params[':since'] = $since;
    }

    if ($until) {
      $sql .= ' AND time <= :until';
      $params[':until'] = $until;
    }

    // Add custom WHERE clause if provided
    if (!empty($whereClause)) {
      $sql .= ' AND (' . $whereClause . ')';
      $params = array_merge($params, $whereParams);
    }

    $sql .= ' ORDER BY time ASC';

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $results[] = [
        'id' => $row['id'],
        'time' => $row['time'],
        'message' => $row['data'],  // JSON data
      ];
    }

    return $results;
  }

  /**
   * Get logs for a specific IP address.
   *
   * @param string $ip IP address
   * @param string|null $since Start time (ISO 8601)
   * @param string|null $until End time (ISO 8601)
   * @return array Array of log entries
   */
  public function getLogsByIp(string $ip, ?string $since = NULL, ?string $until = NULL): array
  {
    $sql = 'SELECT id, time, data FROM logs WHERE client_ip = :ip';
    $params = [':ip' => $ip];

    if ($since) {
      $sql .= ' AND time >= :since';
      $params[':since'] = $since;
    }

    if ($until) {
      $sql .= ' AND time <= :until';
      $params[':until'] = $until;
    }

    $sql .= ' ORDER BY time ASC';

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $results[] = [
        'id' => $row['id'],
        'time' => $row['time'],
        'message' => $row['data'],  // JSON data
      ];
    }

    return $results;
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
  public function getRecordCount(string $since, string $until): int
  {
    $sql = 'SELECT COUNT(*) as count FROM logs WHERE time >= :since AND time <= :until';
    $stmt = $this->db->prepare($sql);
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
  public function createSyncRange(string $startTime, string $endTime, int $chunksTotal): int
  {
    $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO sync_ranges (start_time, end_time, status, chunks_total)
VALUES (:start_time, :end_time, 'pending', :chunks_total)
SQL
    );

    $stmt->execute([
      ':start_time' => $startTime,
      ':end_time' => $endTime,
      ':chunks_total' => $chunksTotal,
    ]);

    return (int) $this->db->lastInsertId();
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

    $stmt = $this->db->prepare($sql);
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
    $stmt = $this->db->prepare(<<<'SQL'
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
    $this->db->exec(<<<'SQL'
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
    $incompleteStmt = $this->db->query(<<<'SQL'
SELECT id, start_time, end_time FROM sync_ranges
WHERE status IN ('failed', 'interrupted', 'in_progress')
ORDER BY started_at DESC
SQL
    );

    $toComplete = [];
    while ($sync = $incompleteStmt->fetch(PDO::FETCH_ASSOC)) {
      // Check if this range has any log data
      $checkStmt = $this->db->prepare(<<<'SQL'
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
      $this->db->exec(<<<SQL
UPDATE sync_ranges
SET status = 'completed',
    completed_at = CURRENT_TIMESTAMP,
    error_message = 'Auto-completed: data recovered by subsequent sync'
WHERE id IN ($ids)
SQL
      );
    }

    $stmt = $this->db->query(<<<'SQL'
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
    $stmt = $this->db->query(<<<'SQL'
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
    $this->db->prepare(<<<'SQL'
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
  public function detectGapsInSyncedRanges(string $requestedStart, string $requestedEnd): array
  {
    // Clamp requested start time to API retention limit
    // E.g., if retention is 14 days and user requests 1 month, only fetch last 14 days
    $retentionLimit = $this->config->getApiRetentionLimit();
    $retentionStart = gmdate('Y-m-d\TH:i:s\Z', time() - $retentionLimit);

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
    $stmt = $this->db->prepare(<<<'SQL'
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
  public function detectMissingRanges(string $requestedStart, string $requestedEnd): array
  {
    // Check if sync_ranges table exists and has data.
    $hasSyncRanges = FALSE;
    try {
      $stmt = $this->db->query("SELECT COUNT(*) as count FROM sync_ranges WHERE status = 'completed'");
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $hasSyncRanges = ($row['count'] ?? 0) > 0;

      // If sync_ranges is empty but logs table has data, initialize from existing data
      if (!$hasSyncRanges) {
        $logStmt = $this->db->query("SELECT COUNT(*) as count FROM logs");
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

      // Get coverage info from logs table for compatibility.
      $stmt = $this->db->prepare(<<<'SQL'
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

      return [
        'has_data' => $coverage['count'] > 0,
        'coverage' => $coverage,
        'ranges' => $gaps,
        'method' => 'sync_tracking',
      ];
    }

    // Fall back to old method: analyze log density (edges only).
    // Get the actual time range covered by data in database.
    $stmt = $this->db->prepare(<<<'SQL'
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
   * Parse URI components into base path and URL arguments.
   *
   * Parses the URI once and extracts both the base path (path portion)
   * and URL arguments (query parameters). This is more efficient than
   * parsing the URL multiple times.
   *
   * @param string $uri Request URI
   * @return array|null Array with 'base_path' and 'url_arguments', or NULL
   */
  protected function parseUriComponents(string $uri): ?array
  {
    // Parse URL once.
    $parts = parse_url($uri);

    if ($parts === FALSE) {
      return NULL;
    }

    $result = [
      'base_path' => NULL,
      'url_arguments' => NULL,
    ];

    // Extract base path.
    if (isset($parts['path']) && !empty($parts['path'])) {
      $result['base_path'] = $parts['path'];
    }

    // Extract and parse query parameters.
    if (isset($parts['query']) && !empty($parts['query'])) {
      parse_str($parts['query'], $params);
      $result['url_arguments'] = $params;
    }

    return $result;
  }

  /**
   * Save campaign analysis to database.
   *
   * Stores campaign analysis results for historical reference.
   * Automatically handles JSON encoding of arrays.
   *
   * @param array $campaign Campaign data from detectCampaigns()
   * @param string $timeRange Time range used for analysis ('15m', '1d', '2w', etc.)
   * @param string $timeStart ISO 8601 timestamp
   * @param string $timeEnd ISO 8601 timestamp
   * @param bool $fromDeepDive Whether this campaign was discovered via deep-dive
   * @return int Campaign analysis ID
   */
  public function saveCampaignAnalysis(
    array $campaign,
    string $timeRange,
    string $timeStart,
    string $timeEnd,
    bool $fromDeepDive = FALSE
  ): int {
    $blockingRec = $campaign['blocking_recommendation'] ?? [];

    $stmt = $this->db->prepare(<<<'SQL'
INSERT OR REPLACE INTO campaign_analysis (
  ip, country,
  time_start, time_end, time_range, analyzed_at,
  total_requests, exploit_requests, first_seen, last_seen, time_span_days,
  attack_types, severity, top_paths,
  behavior_type, request_rate, ratio_40x, ratio_exploit, path_diversity, uri_dup_ratio,
  should_block, confidence, block_reasons,
  user_agent, bot_name, total_volume, ratio_edge_blocked,
  from_deep_dive
) VALUES (
  :ip, :country,
  :time_start, :time_end, :time_range, CURRENT_TIMESTAMP,
  :total_requests, :exploit_requests, :first_seen, :last_seen, :time_span_days,
  :attack_types, :severity, :top_paths,
  :behavior_type, :request_rate, :ratio_40x, :ratio_exploit, :path_diversity, :uri_dup_ratio,
  :should_block, :confidence, :block_reasons,
  :user_agent, :bot_name, :total_volume, :ratio_edge_blocked,
  :from_deep_dive
)
SQL
    );

    $volumeAnalysis = $campaign['volume_analysis'] ?? [];

    $stmt->execute([
      ':ip' => $campaign['ip'] ?? '',
      ':country' => $campaign['country'] ?? '',
      ':time_start' => $timeStart,
      ':time_end' => $timeEnd,
      ':time_range' => $timeRange,
      ':total_requests' => $campaign['count'] ?? 0,
      ':exploit_requests' => $campaign['count'] ?? 0,
      ':first_seen' => $campaign['first_seen'] ?? NULL,
      ':last_seen' => $campaign['last_seen'] ?? NULL,
      ':time_span_days' => $campaign['time_span_days'] ?? 0,
      ':attack_types' => json_encode($campaign['attack_types'] ?? []),
      ':severity' => $campaign['severity'] ?? 'low',
      ':top_paths' => json_encode($campaign['top_paths'] ?? []),
      ':behavior_type' => $campaign['behavior'] ?? '',
      ':request_rate' => $campaign['request_rate'] ?? 0,
      ':ratio_40x' => $campaign['ratio_40x'] ?? 0,
      ':ratio_exploit' => $campaign['ratio_exploit'] ?? 0,
      ':path_diversity' => $campaign['path_diversity'] ?? 0,
      ':uri_dup_ratio' => $campaign['uri_dup_ratio'] ?? 0,
      ':should_block' => ($blockingRec['should_block'] ?? FALSE) ? 1 : 0,
      ':confidence' => $blockingRec['confidence'] ?? 'none',
      ':block_reasons' => json_encode($blockingRec['reasons'] ?? []),
      ':user_agent' => $campaign['user_agent'] ?? '',
      ':bot_name' => $campaign['bot_name'] ?? NULL,
      ':total_volume' => $volumeAnalysis['total_volume'] ?? 0,
      ':ratio_edge_blocked' => $volumeAnalysis['ratio_edge_blocked'] ?? 0,
      ':from_deep_dive' => $fromDeepDive ? 1 : 0,
    ]);

    return (int) $this->db->lastInsertId();
  }

  /**
   * Get campaign analysis for a specific IP.
   *
   * Returns most recent analysis for the IP.
   *
   * @param string $ip IP address
   * @return array|null Campaign analysis or NULL if not found
   */
  public function getCampaignAnalysisByIp(string $ip): ?array
  {
    $stmt = $this->db->prepare(<<<'SQL'
SELECT * FROM campaign_analysis
WHERE ip = :ip
ORDER BY analyzed_at DESC
LIMIT 1
SQL
    );

    $stmt->execute([':ip' => $ip]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
      return NULL;
    }

    // Decode JSON fields.
    $result['attack_types'] = json_decode($result['attack_types'] ?? '[]', TRUE);
    $result['top_paths'] = json_decode($result['top_paths'] ?? '[]', TRUE);
    $result['block_reasons'] = json_decode($result['block_reasons'] ?? '[]', TRUE);

    return $result;
  }

  /**
   * Get all campaign analyses within a time range.
   *
   * @param string|null $since Start time (ISO 8601)
   * @param string|null $until End time (ISO 8601)
   * @param string|null $minConfidence Minimum confidence level
   * @return array Array of campaign analyses
   */
  public function getCampaignAnalyses(
    ?string $since = NULL,
    ?string $until = NULL,
    ?string $minConfidence = NULL
  ): array {
    $sql = 'SELECT * FROM campaign_analysis WHERE 1=1';
    $params = [];

    if ($since) {
      $sql .= ' AND analyzed_at >= :since';
      $params[':since'] = $since;
    }

    if ($until) {
      $sql .= ' AND analyzed_at <= :until';
      $params[':until'] = $until;
    }

    if ($minConfidence) {
      $confidenceLevels = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'urgent' => 4];
      $minLevel = $confidenceLevels[$minConfidence] ?? 0;

      $sql .= ' AND confidence IN (';
      $sql .= implode(',', array_map(fn($k) => "'$k'", array_filter(
        array_keys($confidenceLevels),
        fn($k) => $confidenceLevels[$k] >= $minLevel
      )));
      $sql .= ')';
    }

    $sql .= ' ORDER BY analyzed_at DESC';

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      // Decode JSON fields.
      $row['attack_types'] = json_decode($row['attack_types'] ?? '[]', TRUE);
      $row['top_paths'] = json_decode($row['top_paths'] ?? '[]', TRUE);
      $row['block_reasons'] = json_decode($row['block_reasons'] ?? '[]', TRUE);
      $results[] = $row;
    }

    return $results;
  }

  /**
   * Check if IP is in recent campaign analysis.
   *
   * Looks for campaign analysis within last N days.
   *
   * @param string $ip IP address
   * @param int $daysBack Number of days to look back
   * @return bool TRUE if IP has recent analysis
   */
  public function hasRecentCampaignAnalysis(string $ip, int $daysBack = 7): bool
  {
    $since = date('Y-m-d H:i:s', strtotime("-$daysBack days"));

    $stmt = $this->db->prepare(<<<'SQL'
SELECT COUNT(*) as count FROM campaign_analysis
WHERE ip = :ip AND analyzed_at >= :since
SQL
    );

    $stmt->execute([
      ':ip' => $ip,
      ':since' => $since,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row['count'] ?? 0) > 0;
  }

  /**
   * Get campaign analyses for a specific time range.
   *
   * Retrieves campaigns that were analyzed for the specified time range,
   * ordered by most recent analysis first.
   *
   * @param string $timeStart Start of analyzed time range (ISO 8601)
   * @param string $timeEnd End of analyzed time range (ISO 8601)
   * @param int $maxAgeMinutes Maximum age of analysis in minutes (default: 60)
   * @param string|null $timeRange Time range label for fuzzy matching (e.g., "last all")
   * @return array|null Array with 'campaigns' and 'analyzed_at', or NULL if not found/too old
   */
  public function getCachedCampaignsByTimeRange(string $timeStart, string $timeEnd, int $maxAgeMinutes = 60, ?string $timeRange = NULL): ?array
  {
    // Calculate cutoff time for cache freshness.
    // Use gmdate() to match SQLite's CURRENT_TIMESTAMP which stores in UTC.
    $cutoffTime = gmdate('Y-m-d H:i:s', time() - ($maxAgeMinutes * 60));

    // Step 1: Find the most recent analysis timestamp.
    // If time_range label is provided, match on that instead of exact timestamps.
    // This handles cases like "--all" where timestamps drift but the range is the same.
    if ($timeRange !== NULL) {
      $stmt = $this->db->prepare(<<<'SQL'
SELECT MAX(analyzed_at) as latest_analysis
FROM campaign_analysis
WHERE time_range = :time_range
  AND analyzed_at >= :cutoff
SQL
      );

      $stmt->execute([
        ':time_range' => $timeRange,
        ':cutoff' => $cutoffTime,
      ]);
    }
    else {
      // Fall back to exact time range matching.
      $stmt = $this->db->prepare(<<<'SQL'
SELECT MAX(analyzed_at) as latest_analysis
FROM campaign_analysis
WHERE time_start = :time_start
  AND time_end = :time_end
  AND analyzed_at >= :cutoff
SQL
      );

      $stmt->execute([
        ':time_start' => $timeStart,
        ':time_end' => $timeEnd,
        ':cutoff' => $cutoffTime,
      ]);
    }

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $analyzedAt = $result['latest_analysis'] ?? NULL;

    if ($analyzedAt === NULL) {
      return NULL; // No cache found
    }

    // Step 2: Get all campaigns from that specific analysis.
    $stmt = $this->db->prepare(<<<'SQL'
SELECT *
FROM campaign_analysis
WHERE analyzed_at = :analyzed_at
SQL
    );

    $stmt->execute([':analyzed_at' => $analyzedAt]);

    $campaigns = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      // Decode JSON fields.
      $row['attack_types'] = json_decode($row['attack_types'] ?? '[]', TRUE);
      $row['top_paths'] = json_decode($row['top_paths'] ?? '[]', TRUE);
      $row['block_reasons'] = json_decode($row['block_reasons'] ?? '[]', TRUE);

      $campaigns[] = $row;
    }

    if (empty($campaigns)) {
      return NULL;
    }

    return [
      'campaigns' => $campaigns,
      'analyzed_at' => $analyzedAt,
    ];
  }

  /**
   * Get earliest log timestamp in database.
   *
   * Used for deep-dive queries to determine how far back to search.
   *
   * @return string|null ISO 8601 timestamp or NULL if no logs
   */
  public function getEarliestLogDate(): ?string
  {
    $stmt = $this->db->query('SELECT MIN(time) as earliest FROM logs');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['earliest'] ?? NULL;
  }

}
