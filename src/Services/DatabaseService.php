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
   * @param ConfigurationService $config Configuration service
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
      foreach ($logs as $log) {
        $message = $log['message'] ?? '';

        $stmt->execute([
          ':id' => $log['id'] ?? '',
          ':time' => $log['time'] ?? '',
          ':data' => $message,  // Store entire JSON as-is
        ]);

        $inserted++;
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
   * Get database statistics.
   *
   * @return array Statistics
   */
  public function getStats(): array
  {
    $stmt = $this->db->query('SELECT COUNT(*) as count FROM logs');
    $logCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $fileSize = file_exists($this->dbPath) ? filesize($this->dbPath) : 0;

    return [
      'log_count' => $logCount,
      'file_size' => $fileSize,
      'file_size_mb' => round($fileSize / 1024 / 1024, 2),
      'db_path' => $this->dbPath,
    ];
  }
}
