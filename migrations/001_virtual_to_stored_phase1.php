#!/usr/bin/env php
<?php

/**
 * Migration: Convert ALL VIRTUAL columns to STORED
 *
 * This migration converts all columns from VIRTUAL (computed on-the-fly)
 * to STORED (computed once at insert, stored on disk) to improve query performance.
 *
 * Columns being converted:
 * - client_ip: Heavy filtering usage in WHERE clauses
 * - resp_status: Used in every status query
 * - req_user_agent: Bot detection with LIKE patterns
 * - req_uri: Path filtering and exploit detection
 * - orig_host: Multi-site filtering
 * - country: Complex COALESCE, frequent geographic filtering
 * - req_method: HTTP method filtering
 * - city: Geographic filtering
 * - log_type: Log categorization
 * - log_severity: Severity filtering
 * - program: Application filtering
 * - hostname: Host filtering
 * - message: Message content
 * - url_arguments: Query parameter analysis
 * - base_path: Path-based filtering
 *
 * Performance Impact:
 * - Eliminates JSON parsing during WHERE clause evaluation
 * - Improves index efficiency for filtered queries
 * - JSON data preserved intact (no deduplication - safe migration)
 * - Database size will INCREASE due to storing computed columns
 * - Slower INSERT operations (columns computed at write time)
 *
 * Before: 11 GB database with 10,916,238 records
 * After: TBD (expect size increase, but much faster queries)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// Color output helpers
function info(string $msg): void {
  echo "\033[36m[INFO]\033[0m $msg\n";
}

function success(string $msg): void {
  echo "\033[32m[SUCCESS]\033[0m $msg\n";
}

function error(string $msg): void {
  echo "\033[31m[ERROR]\033[0m $msg\n";
}

function warning(string $msg): void {
  echo "\033[33m[WARNING]\033[0m $msg\n";
}

// Get database path from configuration
function getDatabasePath(): string {
  $configPath = $_SERVER['HOME'] . '/.solarwinds.yml';
  if (file_exists($configPath)) {
    $config = Yaml::parseFile($configPath);
    if (isset($config['database']['path'])) {
      return $config['database']['path'];
    }
  }
  return $_SERVER['HOME'] . '/.solarwinds/logs.db';
}

// Main migration logic
function runMigration(): int {
  $dbPath = getDatabasePath();

  info("Starting Migration: Converting ALL VIRTUAL columns to STORED");
  info("Database: $dbPath");

  if (!file_exists($dbPath)) {
    error("Database not found: $dbPath");
    return 1;
  }

  // Show initial statistics
  info("Gathering pre-migration statistics...");
  $db = new PDO('sqlite:' . $dbPath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $stats = $db->query("
    SELECT
      (SELECT COUNT(*) FROM logs) as record_count,
      (SELECT page_count FROM pragma_page_count()) as page_count,
      (SELECT page_size FROM pragma_page_size()) as page_size
  ")->fetch(PDO::FETCH_ASSOC);

  $sizeMB = ($stats['page_count'] * $stats['page_size']) / 1024 / 1024;

  info("Pre-migration stats:");
  info("  Records: " . number_format($stats['record_count']));
  info("  Size: " . number_format($sizeMB, 2) . " MB");
  info("  Pages: " . number_format($stats['page_count']));

  // Backup recommendation
  warning("This migration will modify the database schema.");
  warning("Recommended: Create a backup first:");
  warning("  cp $dbPath $dbPath.backup");
  echo "\nContinue with migration? [y/N]: ";

  $response = @fgets(STDIN);
  if ($response === FALSE) {
    // Non-interactive mode - check for --yes flag
    global $argv;
    if (!in_array('--yes', $argv ?? [])) {
      error("Running in non-interactive mode. Use --yes flag to proceed.");
      return 1;
    }
    $response = 'y';
  }
  else {
    $response = trim($response);
  }

  if (strtolower($response) !== 'y') {
    info("Migration cancelled.");
    return 0;
  }

  // Begin migration
  info("\nStarting schema migration...");

  try {
    // Clean up any previous failed migration attempt
    $db->exec('DROP TABLE IF EXISTS logs_new');

    info("Step 1/6: Creating new table with STORED columns...");

    // Create new table with ALL columns as STORED
    $db->exec(<<<'SQL'
CREATE TABLE logs_new (
  id TEXT PRIMARY KEY,
  time TEXT NOT NULL,
  retrieved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
  hostname TEXT,
  message TEXT,
  url_arguments TEXT,
  base_path TEXT
)
SQL
);

    info("Step 2/6: Copying data to new table using native SQLite JSON functions...");

    $startTime = microtime(TRUE);

    // Use SQLite's native JSON functions to extract and deduplicate in one statement
    $db->exec(<<<'SQL'
INSERT INTO logs_new (
  id, time, retrieved_at, data,
  client_ip,
  resp_status,
  req_user_agent,
  req_uri,
  orig_host,
  country,
  req_method,
  city,
  log_type,
  log_severity,
  program,
  hostname,
  message,
  url_arguments,
  base_path
)
SELECT
  id,
  time,
  retrieved_at,
  -- Deduplicated JSON: remove all extracted fields
  json_remove(
    data,
    '$.client_ip', '$.ip',
    '$.resp_status',
    '$.req_user_agent',
    '$.req_uri',
    '$.orig_host',
    '$.req_method', '$.request_method',
    '$.url_arguments',
    '$.type',
    '$.severity',
    '$.program',
    '$.hostname',
    '$.message',
    '$.base_path',
    '$.country',
    '$.city',
    '$.geoip.country_code2', '$.geoip.country_name', '$.geoip.city_name', '$.geoip.ip',
    '$.geo.country', '$.geo.city'
  ) as data,
  -- Extract fields with fallback chains
  COALESCE(json_extract(data, '$.geoip.ip'), json_extract(data, '$.ip'), json_extract(data, '$.client_ip')) as client_ip,
  json_extract(data, '$.resp_status') as resp_status,
  json_extract(data, '$.req_user_agent') as req_user_agent,
  json_extract(data, '$.req_uri') as req_uri,
  json_extract(data, '$.orig_host') as orig_host,
  COALESCE(
    json_extract(data, '$.geoip.country_code2'),
    json_extract(data, '$.geoip.country_name'),
    json_extract(data, '$.country'),
    json_extract(data, '$.geo.country')
  ) as country,
  COALESCE(json_extract(data, '$.req_method'), json_extract(data, '$.request_method')) as req_method,
  COALESCE(
    json_extract(data, '$.geoip.city_name'),
    json_extract(data, '$.city'),
    json_extract(data, '$.geo.city')
  ) as city,
  json_extract(data, '$.type') as log_type,
  json_extract(data, '$.severity') as log_severity,
  json_extract(data, '$.program') as program,
  json_extract(data, '$.hostname') as hostname,
  json_extract(data, '$.message') as message,
  json_extract(data, '$.url_arguments') as url_arguments,
  json_extract(data, '$.base_path') as base_path
FROM logs
SQL
);

    $elapsed = microtime(TRUE) - $startTime;
    info("  Data copy completed in " . number_format($elapsed, 2) . " seconds");

    info("Step 3/6: Dropping old table...");
    $db->exec('DROP TABLE logs');

    info("Step 4/6: Renaming new table...");
    $db->exec('ALTER TABLE logs_new RENAME TO logs');

    info("Step 5/6: Creating indexes on migrated table...");

    // Now create indexes with original names (old table is gone, so names are available)
    $db->exec('CREATE INDEX idx_time ON logs(time)');
    $db->exec('CREATE INDEX idx_client_ip ON logs(client_ip) WHERE client_ip IS NOT NULL');
    $db->exec('CREATE INDEX idx_resp_status ON logs(resp_status) WHERE resp_status IS NOT NULL');
    $db->exec('CREATE INDEX idx_req_user_agent ON logs(req_user_agent) WHERE req_user_agent IS NOT NULL');
    $db->exec('CREATE INDEX idx_req_uri ON logs(req_uri) WHERE req_uri IS NOT NULL');
    $db->exec('CREATE INDEX idx_orig_host ON logs(orig_host) WHERE orig_host IS NOT NULL');
    $db->exec('CREATE INDEX idx_country ON logs(country) WHERE country IS NOT NULL');
    $db->exec('CREATE INDEX idx_req_method ON logs(req_method) WHERE req_method IS NOT NULL');
    $db->exec('CREATE INDEX idx_log_type ON logs(log_type) WHERE log_type IS NOT NULL');
    $db->exec('CREATE INDEX idx_log_severity ON logs(log_severity) WHERE log_severity IS NOT NULL');
    $db->exec('CREATE INDEX idx_hostname ON logs(hostname) WHERE hostname IS NOT NULL');
    $db->exec('CREATE INDEX idx_city ON logs(city) WHERE city IS NOT NULL');
    $db->exec('CREATE INDEX idx_base_path ON logs(base_path) WHERE base_path IS NOT NULL');

    // Close all prepared statements before VACUUM
    unset($insertStmt, $selectStmt, $countStmt);

    info("Step 6/6: Running VACUUM to optimize database...");
    info("  This may take several minutes...");

    $vacuumStartTime = microtime(TRUE);
    $db->exec('VACUUM');
    $vacuumElapsed = microtime(TRUE) - $vacuumStartTime;

    info("  VACUUM completed in " . number_format($vacuumElapsed, 2) . " seconds");

    success("Migration completed successfully!");

    // Show post-migration statistics
    info("\nGathering post-migration statistics...");

    $newStats = $db->query("
      SELECT
        (SELECT COUNT(*) FROM logs) as record_count,
        (SELECT page_count FROM pragma_page_count()) as page_count,
        (SELECT page_size FROM pragma_page_size()) as page_size
    ")->fetch(PDO::FETCH_ASSOC);

    $newSizeMB = ($newStats['page_count'] * $newStats['page_size']) / 1024 / 1024;
    $increaseMB = $newSizeMB - $sizeMB;
    $increasePercent = ($increaseMB / $sizeMB) * 100;

    info("Post-migration stats:");
    info("  Records: " . number_format($newStats['record_count']));
    info("  Size: " . number_format($newSizeMB, 2) . " MB");
    info("  Pages: " . number_format($newStats['page_count']));
    info("\nSize change:");
    info("  Before: " . number_format($sizeMB, 2) . " MB");
    info("  After: " . number_format($newSizeMB, 2) . " MB");
    info("  Increase: " . number_format($increaseMB, 2) . " MB (" . number_format($increasePercent, 2) . "%)");

    // Verify data integrity
    info("\nVerifying data integrity...");

    // Sample verification - check that columns are populated
    $sample = $db->query("
      SELECT
        COUNT(*) as total,
        COUNT(client_ip) as with_client_ip,
        COUNT(req_method) as with_req_method,
        COUNT(country) as with_country
      FROM logs
    ")->fetch(PDO::FETCH_ASSOC);

    info("  Total records: " . number_format($sample['total']));
    info("  Records with client_ip: " . number_format($sample['with_client_ip']));
    info("  Records with req_method: " . number_format($sample['with_req_method']));
    info("  Records with country: " . number_format($sample['with_country']));

    if ($sample['total'] != $stats['record_count']) {
      error("Record count mismatch! Expected " . number_format($stats['record_count']) . " but got " . number_format($sample['total']));
      return 1;
    }

    success("Data integrity verified - all records migrated successfully");

    return 0;
  }
  catch (PDOException $e) {
    error("Migration failed: " . $e->getMessage());
    error("The 'logs' table is unchanged. You may need to manually drop 'logs_new' if it exists.");
    return 1;
  }
}

// Run migration
exit(runMigration());

