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
use PDOStatement;

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

      $inserted = $fixed = 0;
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
   * Execute a simple SQL query.
   *
   * @param string $sql SQL query to execute
   * @return StatementWrapper Fluent statement wrapper for chaining
   */
  public function query(string $sql): StatementWrapper
  {
    return new StatementWrapper($this->db->query($sql));
  }

  /**
   * Prepare a SQL statement for execution.
   *
   * @param string $sql SQL query with placeholders
   * @return StatementWrapper Fluent statement wrapper for chaining
   */
  public function prepare(string $sql): StatementWrapper
  {
    return new StatementWrapper($this->db->prepare($sql));
  }

  /**
   * Execute a SQL statement with parameters.
   *
   * @param string $sql SQL query with placeholders
   * @param array $params Parameters to bind
   * @return StatementWrapper Fluent statement wrapper for chaining
   */
  public function execute(string $sql, array $params = []): StatementWrapper
  {
    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);
    return new StatementWrapper($stmt);
  }

  /**
   * Execute a SQL statement directly (for DDL commands).
   *
   * @param string $sql SQL command to execute
   * @return int Number of affected rows
   */
  public function exec(string $sql): int
  {
    return $this->db->exec($sql);
  }

  /**
   * Begin a database transaction.
   *
   * @return self For method chaining
   */
  public function beginTransaction(): self
  {
    $this->db->beginTransaction();
    return $this;
  }

  /**
   * Commit the current transaction.
   *
   * @return self For method chaining
   */
  public function commit(): self
  {
    $this->db->commit();
    return $this;
  }

  /**
   * Roll back the current transaction.
   *
   * @return self For method chaining
   */
  public function rollBack(): self
  {
    $this->db->rollBack();
    return $this;
  }

  /**
   * Get the ID of the last inserted row.
   *
   * @return string Last insert ID
   */
  public function lastInsertId(): string
  {
    return $this->db->lastInsertId();
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
    // Don't select VIRTUAL generated columns - extract from JSON instead for better performance
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
        'message' => $row['data'],  // JSON data (will be decoded by parseLogMessage)
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

}
