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
   * Whether queries may span archived shards. On by default — spanning is
   * automatic and driven by the requested time range, so it only ever
   * happens when a query actually reaches into archived time (a recent-only
   * query overlaps no shard and never touches the archive). Set FALSE to
   * force hot-tier-only regardless of range.
   *
   * @var bool
   */
  protected bool $includeArchive = TRUE;

  /**
   * Attached shard aliases for the in-flight query, cleaned up afterward.
   *
   * @var array<int, string>
   */
  protected array $attachedAliases = [];

  /**
   * Constructor.
   *
   * @param DatabaseService $database Database service for raw SQL execution
   */
  public function __construct(protected DatabaseService $database)
  {
  }

  /**
   * Enable or disable spanning archived shards in subsequent queries.
   *
   * @param bool $include Whether to include archive shards
   */
  public function setIncludeArchive(bool $include): void
  {
    $this->includeArchive = $include;
  }

  /**
   * Open a logs source for querying, optionally spanning archive shards.
   *
   * When archive inclusion is on and shards overlap the requested range,
   * ATTACHes each shard and creates a TEMP VIEW unioning main + shards;
   * returns the view name to use in place of "logs". Otherwise returns the
   * plain "logs" table. Always pair with closeSource().
   *
   * @param int|null $since Start of range (unix timestamp) or NULL
   * @param int|null $until End of range (unix timestamp) or NULL
   * @return string Table or view name to query ("logs" or "logs_all")
   */
  protected function openSource(?int $since, ?int $until): string
  {
    $this->attachedAliases = [];
    if (!$this->includeArchive) {
      return 'logs';
    }

    $shards = $this->findShards($since, $until);
    if (empty($shards)) {
      return 'logs';
    }

    $unionParts = ['SELECT * FROM logs'];
    foreach ($shards as $i => $path) {
      $alias = 'arc_' . $i;
      $escaped = str_replace("'", "''", $path);
      $this->database->exec("ATTACH DATABASE '$escaped' AS $alias");
      $this->attachedAliases[] = $alias;
      $unionParts[] = "SELECT * FROM $alias.logs";
    }

    $this->database->exec('DROP VIEW IF EXISTS logs_all');
    $this->database->exec('CREATE TEMP VIEW logs_all AS ' . implode(' UNION ALL ', $unionParts));
    return 'logs_all';
  }

  /**
   * Tear down whatever openSource() set up (view + attached shards).
   */
  protected function closeSource(): void
  {
    if (empty($this->attachedAliases)) {
      return;
    }
    $this->database->exec('DROP VIEW IF EXISTS logs_all');
    foreach ($this->attachedAliases as $alias) {
      $this->database->exec("DETACH DATABASE $alias");
    }
    $this->attachedAliases = [];
  }

  /**
   * Find archive shard file paths whose time range overlaps [since, until].
   *
   * Reads the archive_files registry. Silently skips registered shards whose
   * files are missing (e.g., an unmounted volume) so a query can still run
   * against whatever is available.
   *
   * @param int|null $since Start of range (unix timestamp) or NULL for open
   * @param int|null $until End of range (unix timestamp) or NULL for open
   * @return array<int, string> Existing shard file paths, oldest first
   */
  protected function findShards(?int $since, ?int $until): array
  {
    try {
      $sql = 'SELECT file_path, start_time, end_time FROM archive_files';
      $conds = [];
      $params = [];
      if ($until !== NULL) {
        $conds[] = 'start_time <= :until';
        $params[':until'] = $until;
      }
      if ($since !== NULL) {
        $conds[] = 'end_time >= :since';
        $params[':since'] = $since;
      }
      if (!empty($conds)) {
        $sql .= ' WHERE ' . implode(' AND ', $conds);
      }
      $sql .= ' ORDER BY start_time ASC';

      $stmt = $this->database->prepare($sql);
      $stmt->execute($params);
      $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\PDOException $e) {
      // archive_files table may not exist on older databases.
      return [];
    }

    $paths = [];
    foreach ($rows as $row) {
      if (is_file($row['file_path'])) {
        $paths[] = $row['file_path'];
      }
    }
    return $paths;
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
    $source = $this->openSource($since, $until);
    try {
      $sql = "SELECT * FROM $source WHERE 1=1";
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
    finally {
      $this->closeSource();
    }
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
    $source = $this->openSource($since, $until);
    try {
      // Select STORED columns directly for performance (Phase 1 optimization).
      // These columns are pre-computed and don't require JSON parsing.
      // NOTE: data column is optional - only included when $includeData = TRUE for display features that might need it
      // Use INDEXED BY hint when we have time constraints to force idx_time_method usage
      // (prevents SQLite from using idx_req_method which causes expensive TEMP B-TREE sorts)
      // However, don't use hint if there's a custom WHERE clause - let SQLite choose the optimal index.
      // The hint only applies to the base logs table; a union-over-archive view cannot be hinted.
      $indexHint = ($source === 'logs' && ($since || $until) && empty($whereClause)) ? ' INDEXED BY idx_time_method' : '';

      // Pull the JA3 fingerprint as a scalar via json_extract rather than
      // loading the full data blob - cheap enough to include for all callers
      // and needed for fingerprint-based campaign analysis.
      $ja3Col = ", json_extract(data, '$.tls_client_ja3_md5') AS tls_client_ja3_md5";
      if ($includeData) {
        // Raw/full callers also get the Drupal/JSON log columns (message and the
        // log_type/log_severity denormalizations); the default HTTP projection
        // omits them, which leaves Drupal log entries with no visible message.
        $sql = "SELECT id, time, data, message, log_type, log_severity, client_ip, resp_status, req_user_agent, req_uri, orig_host, country, req_method, cache_status, region, city, url_arguments$ja3Col FROM $source" . $indexHint . ' WHERE 1=1';
      }
      else {
        $sql = "SELECT id, time, client_ip, resp_status, req_user_agent, req_uri, orig_host, country, req_method, cache_status, region, city, url_arguments$ja3Col FROM $source" . $indexHint . ' WHERE 1=1';
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
      $countSql = "SELECT COUNT(*) FROM $source WHERE 1=1";
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
      // fetchColumn() leaves the cursor open; close it so the later DETACH in
      // closeSource() isn't blocked by a lingering read lock on a shard.
      $countStmt->closeCursor();
      $countStmt = NULL;
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
    finally {
      $this->closeSource();
    }
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
    $source = $this->openSource($since, $until);
    try {
      $sql = "SELECT id, time, data, client_ip, resp_status, req_user_agent, req_uri, orig_host, country, req_method, cache_status FROM $source WHERE client_ip = :ip";
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
    finally {
      $this->closeSource();
    }
  }
}
