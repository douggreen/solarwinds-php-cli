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
  url_arguments JSON GENERATED ALWAYS AS (json_extract(data, '$.url_arguments')) VIRTUAL
);
SQL;

    $this->db->exec($sql);

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
      $stmt = $this->db->prepare(<<<'SQL'
INSERT OR REPLACE INTO logs (id, time, data)
VALUES (:id, :time, :data)
SQL
      );

      $inserted = 0;
      $fixed = 0;
      foreach ($logs as $log) {
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
              $message = json_encode(['raw' => $message, 'error' => json_last_error_msg()]);
              $logData = NULL;
            }
            else {
              $message = $cleanedMessage;
            }
          }
        }

        // Inject URL arguments into JSON data for fast exploit detection.
        if ($logData && isset($logData['req_uri'])) {
          $urlArguments = $this->parseUrlArguments($logData['req_uri']);
          if ($urlArguments !== NULL) {
            // Inject url_arguments into the JSON data.
            $logData['url_arguments'] = json_decode($urlArguments, TRUE);
            $message = json_encode($logData);
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
   * Detect missing data ranges at beginning/end of requested time range.
   *
   * Analyzes what data exists in the database for the requested time range
   * and identifies missing ranges that need to be fetched from the API.
   *
   * NOTE: This implementation only detects missing data at the beginning
   * and end of the requested range. It does NOT detect ranges in the middle.
   *
   * @param string $requestedStart Start of requested range (ISO 8601)
   * @param string $requestedEnd End of requested range (ISO 8601)
   * @return array Array with 'has_data', 'coverage' info and 'ranges' (missing ranges) to fetch
   */
  public function detectMissingRanges(string $requestedStart, string $requestedEnd): array
  {
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
    ];
  }

  /**
   * Parse URL query parameters from URI.
   *
   * Extracts and returns query parameters as JSON for fast exploit detection.
   * Returns NULL if no query string present.
   *
   * @param string $uri Request URI
   * @return string|null JSON-encoded query parameters or NULL
   */
  protected function parseUrlArguments(string $uri): ?string
  {
    // Parse URL and extract query string.
    $parts = parse_url($uri);
    if (!isset($parts['query']) || empty($parts['query'])) {
      return NULL;
    }

    // Parse query string into associative array.
    parse_str($parts['query'], $params);

    // Return as JSON for storage.
    return json_encode($params);
  }

}
