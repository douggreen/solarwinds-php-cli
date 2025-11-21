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
   */
  protected function createSchema(): void
  {
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS logs (
  id TEXT PRIMARY KEY,
  time TEXT NOT NULL,
  data JSON NOT NULL,
  client_ip TEXT GENERATED ALWAYS AS (json_extract(data, '$.client_ip')) VIRTUAL,
  req_method TEXT GENERATED ALWAYS AS (json_extract(data, '$.req_method')) VIRTUAL,
  req_uri TEXT GENERATED ALWAYS AS (json_extract(data, '$.req_uri')) VIRTUAL,
  req_user_agent TEXT GENERATED ALWAYS AS (json_extract(data, '$.req_user_agent')) VIRTUAL,
  resp_status INTEGER GENERATED ALWAYS AS (json_extract(data, '$.resp_status')) VIRTUAL,
  log_type TEXT GENERATED ALWAYS AS (json_extract(data, '$.type')) VIRTUAL,
  log_severity TEXT GENERATED ALWAYS AS (json_extract(data, '$.severity')) VIRTUAL
);

CREATE INDEX IF NOT EXISTS idx_time ON logs(time);
CREATE INDEX IF NOT EXISTS idx_client_ip ON logs(client_ip) WHERE client_ip IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_req_method ON logs(req_method) WHERE req_method IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_resp_status ON logs(resp_status) WHERE resp_status IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_log_type ON logs(log_type) WHERE log_type IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_log_severity ON logs(log_severity) WHERE log_severity IS NOT NULL;
SQL;

    $this->db->exec($sql);
  }

  /**
   * Insert log entries into database.
   *
   * Stores entire log as JSON. Generated columns automatically extract indexed fields.
   *
   * @param array $logs Array of log entries from SolarWinds API
   * @return int Number of inserted records
   */
  public function insertLogs(array $logs): int
  {
    if (empty($logs)) {
      return 0;
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

        // Validate and fix JSON if needed.
        if (!empty($message)) {
          json_decode($message);
          if (json_last_error() !== JSON_ERROR_NONE) {
            // JSON is malformed - attempt to fix it.
            // Common issues: control characters, unescaped quotes, truncation.

            // Try to fix control characters by removing/escaping them.
            $fixed++;
            $message = preg_replace('/[\x00-\x1F\x7F]/u', '', $message);

            // If still invalid, try to decode and re-encode to fix escaping.
            json_decode($message);
            if (json_last_error() !== JSON_ERROR_NONE) {
              // Last resort: store the original as a JSON-escaped string.
              $message = json_encode(['raw' => $message, 'error' => json_last_error_msg()]);
            }
          }
        }

        $stmt->execute([
          ':id' => $log['id'] ?? '',
          ':time' => $log['time'] ?? '',
          ':data' => $message,  // Store JSON (fixed if needed)
        ]);

        $inserted++;
      }

      // Log fixed entries if any.
      if ($fixed > 0) {
        error_log(sprintf(
          'Fixed %d log entries with malformed JSON from API',
          $fixed
        ));
      }

      $this->db->commit();
      return $inserted;
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
   * and end of the requested range. It does NOT detect gaps in the middle.
   *
   * @param string $requestedStart Start of requested range (ISO 8601)
   * @param string $requestedEnd End of requested range (ISO 8601)
   * @return array Array with 'has_data', 'coverage' info and 'gaps' (missing ranges) to fetch
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
        'gaps' => [
          [
            'start' => $requestedStart,
            'end' => $requestedEnd,
            'reason' => 'no_data',
          ],
        ],
      ];
    }

    // We have some data - check for gaps at the beginning and/or end.
    $gaps = [];

    // Gap before existing data?
    if ($coverage['earliest'] > $requestedStart) {
      $gaps[] = [
        'start' => $requestedStart,
        'end' => $coverage['earliest'],
        'reason' => 'historical',
      ];
    }

    // Gap after existing data?
    if ($coverage['latest'] < $requestedEnd) {
      $gaps[] = [
        'start' => $coverage['latest'],
        'end' => $requestedEnd,
        'reason' => 'recent',
      ];
    }

    return [
      'has_data' => TRUE,
      'coverage' => $coverage,
      'gaps' => $gaps,
    ];
  }

}
