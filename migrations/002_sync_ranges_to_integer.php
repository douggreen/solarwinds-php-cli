<?php

/**
 * Migration: Convert sync_ranges metadata timestamps to INTEGER (unix timestamp)
 *
 * This migration converts started_at and completed_at from TEXT (ISO 8601) to INTEGER
 * (unix timestamp) for consistency. The start_time and end_time fields are already
 * integers and just need their schema updated.
 *
 * Converts: started_at, completed_at from TEXT to INTEGER
 * Schema update: start_time, end_time remain INTEGER (no data conversion needed)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;

// Initialize config and connect directly to database to avoid schema initialization
$config = new ConfigurationService();
$dbPath = $config->getDatabasePath();
$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Starting sync_ranges migration to INTEGER timestamps...\n";

// Create new table with INTEGER timestamps for ALL time fields
echo "Creating new sync_ranges_new table...\n";
$db->exec(<<<'SQL'
CREATE TABLE sync_ranges_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  start_time INTEGER NOT NULL,
  end_time INTEGER NOT NULL,
  status TEXT NOT NULL CHECK(status IN ('pending', 'in_progress', 'completed', 'failed', 'interrupted')),
  started_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
  completed_at INTEGER,
  records_expected INTEGER,
  records_inserted INTEGER DEFAULT 0,
  chunks_total INTEGER,
  chunks_completed INTEGER DEFAULT 0,
  error_message TEXT
)
SQL
);

// Migrate data, converting TEXT ISO 8601 fields (started_at, completed_at) to INTEGER unix timestamps
// Note: start_time and end_time are already integers, so we just copy them
echo "Migrating data and converting timestamps...\n";
$db->exec(<<<'SQL'
INSERT INTO sync_ranges_new (
  id, start_time, end_time, status, started_at, completed_at,
  records_expected, records_inserted, chunks_total, chunks_completed, error_message
)
SELECT
  id,
  start_time,
  end_time,
  status,
  strftime('%s', started_at) as started_at,
  CASE WHEN completed_at IS NULL THEN NULL ELSE strftime('%s', completed_at) END as completed_at,
  records_expected,
  records_inserted,
  chunks_total,
  chunks_completed,
  error_message
FROM sync_ranges
SQL
);

// Drop old table and rename new one
echo "Replacing old table...\n";
$db->exec('DROP TABLE sync_ranges');
$db->exec('ALTER TABLE sync_ranges_new RENAME TO sync_ranges');

// Recreate indexes
echo "Recreating indexes...\n";
$db->exec('CREATE INDEX idx_sync_status ON sync_ranges(status)');
$db->exec('CREATE INDEX idx_sync_times ON sync_ranges(start_time, end_time)');
$db->exec('CREATE UNIQUE INDEX idx_sync_range_unique ON sync_ranges(start_time, end_time, status) WHERE status IN (\'in_progress\', \'completed\')');

// Verify migration
$stmt = $db->query('SELECT COUNT(*) as count FROM sync_ranges');
$count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "Migration complete! Migrated $count sync_ranges records.\n";

// Show sample of converted data
echo "\nSample of converted data:\n";
$stmt = $db->query('SELECT id, datetime(start_time, "unixepoch") as start, datetime(end_time, "unixepoch") as end, datetime(started_at, "unixepoch") as started, status FROM sync_ranges ORDER BY id DESC LIMIT 3');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  echo "  ID {$row['id']}: {$row['start']} to {$row['end']} (started: {$row['started']}, status: {$row['status']})\n";
}
