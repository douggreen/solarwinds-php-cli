<?php

/**
 * @file LogQueryService.php
 * @brief Application-level log query service
 *
 * @class LogQueryService
 * @brief Provides application-level query methods for the logs table
 *
 * This service sits between DatabaseService (low-level SQL) and Commands (business logic).
 * It provides convenient query methods with application-specific parameters like time ranges,
 * WHERE clauses, and IP filtering.
 *
 * ARCHITECTURE:
 * - Uses DatabaseService for raw SQL execution
 * - Handles time range conversions (ISO 8601 to unix timestamps)
 * - Builds complete SQL queries with WHERE clauses
 * - Returns log arrays ready for use by Commands
 *
 * Methods in this service take application parameters ($since, $until, $whereClause, $ip)
 * and translate them into SQL queries executed via DatabaseService.
 */

namespace SolarWinds\Services;

/**
 * Log Query Service - Application-level queries for logs table
 *
 * Provides convenient query methods that accept application parameters
 * and translate them to SQL queries via DatabaseService.
 */
class LogQueryService
{
  protected array $lastQueryTiming = [];

  /**
   * Constructor.
   *
   * @param DatabaseService $database Database service for raw SQL execution
   */
  public function __construct(protected DatabaseService $database)
  {
  }

  /**
   * Get all logs within a time range.
   *
   * @param int|null $since Start time (unix timestamp)
   * @param int|null $until End time (unix timestamp)
   * @return array Array of log entries
   */
  public function getLogs(?int $since = NULL, ?int $until = NULL): array
  {
    $sql = 'SELECT * FROM logs WHERE 1=1';
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

    $stmt = $this->database->prepare($sql);
    $stmt->execute($params);

    $results = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      $results[] = $row;
    }

    return $results;
  }

  /**
   * Query logs with custom WHERE clause and time range.
   *
   * This is the primary query method used by Commands to fetch logs.
   * Supports custom WHERE clauses, time filtering, and optional data column inclusion.
   *
   * @param string|null $whereClause SQL WHERE clause (without 'WHERE' keyword)
   * @param array $whereParams PDO parameters for WHERE clause
   * @param int|null $since Start time (unix timestamp)
   * @param int|null $until End time (unix timestamp)
   * @param bool $includeData Whether to include the data JSON column (for display features)
   * @param callable|null $progressCallback Optional callback(current, total) for progress updates
   * @return array Array of log entries
   */
  public function getLogsWithQuery(?string $whereClause, array $whereParams, ?int $since = NULL, ?int $until = NULL, bool $includeData = FALSE, ?callable $progressCallback = NULL): array
  {
    // Select STORED columns directly for performance (Phase 1 optimization).
    // These columns are pre-computed and don't require JSON parsing.
    // NOTE: data column is optional - only included when $includeData = TRUE for display features that might need it
    // Use INDEXED BY hint when we have time constraints to force idx_time_method usage
    // (prevents SQLite from using idx_req_method which causes expensive TEMP B-TREE sorts)
    $indexHint = ($since || $until) ? ' INDEXED BY idx_time_method' : '';

    if ($includeData) {
      $sql = 'SELECT id, time, data, client_ip, resp_status, req_user_agent, req_uri, orig_host, country, req_method, cache_status, region, url_arguments FROM logs' . $indexHint . ' WHERE 1=1';
    }
    else {
      $sql = 'SELECT id, time, client_ip, resp_status, req_user_agent, req_uri, orig_host, country, req_method, cache_status, region, url_arguments FROM logs' . $indexHint . ' WHERE 1=1';
    }
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

    // First, do a COUNT(*) query to get row count quickly
    $countSql = 'SELECT COUNT(*) FROM logs WHERE 1=1';
    if ($since) {
      $countSql .= ' AND time >= :since';
    }
    if ($until) {
      $countSql .= ' AND time <= :until';
    }
    if (!empty($whereClause)) {
      $countSql .= ' AND (' . $whereClause . ')';
    }

    $countStart = microtime(TRUE);
    $countStmt = $this->database->prepare($countSql);
    $countStmt->execute($params);
    $rowCount = (int) $countStmt->fetchColumn();
    $countTime = microtime(TRUE) - $countStart;

    $sql .= ' ORDER BY time ASC';

    // Time the query execution
    $executeStart = microtime(TRUE);
    $stmt = $this->database->prepare($sql);
    $stmt->execute($params);
    $executeTime = microtime(TRUE) - $executeStart;

    // Time the row fetching
    $fetchStart = microtime(TRUE);
    $results = [];

    // Fetch with optional progress callback
    $fetchedCount = 0;
    $updateInterval = max(1, (int) ($rowCount / 100)); // Update every 1% for large queries
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      $results[] = $row;
      $fetchedCount++;

      // Call progress callback periodically
      if ($progressCallback && ($fetchedCount % $updateInterval === 0 || $fetchedCount === $rowCount)) {
        $progressCallback($fetchedCount, $rowCount);
      }
    }
    $fetchTime = microtime(TRUE) - $fetchStart;

    // Store timing info for caller to display
    $this->lastQueryTiming = [
      'count' => $countTime,
      'execute' => $executeTime,
      'fetch' => $fetchTime,
      'total' => $countTime + $executeTime + $fetchTime,
      'row_count' => $rowCount,
    ];

    return $results;
  }

  /**
   * Get timing information from the last query.
   *
   * @return array Array with 'execute', 'fetch', 'total', and 'row_count' keys
   */
  public function getLastQueryTiming(): array
  {
    return $this->lastQueryTiming;
  }

  /**
   * Get logs for a specific IP address.
   *
   * @param string $ip IP address
   * @param int|null $since Start time (unix timestamp)
   * @param int|null $until End time (unix timestamp)
   * @return array Array of log entries
   */
  public function getLogsByIp(string $ip, ?int $since = NULL, ?int $until = NULL): array
  {
    $sql = 'SELECT id, time, data, client_ip, resp_status, req_user_agent, req_uri, orig_host, country, req_method, cache_status FROM logs WHERE client_ip = :ip';
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

    $stmt = $this->database->prepare($sql);
    $stmt->execute($params);

    $results = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      $results[] = $row;
    }

    return $results;
  }
}
