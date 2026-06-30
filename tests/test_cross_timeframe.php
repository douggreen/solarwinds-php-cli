#!/usr/bin/env php
<?php

/**
 * Test script for cross-timeframe database sync.
 *
 * Simulates the workflow: --1d, then --2d, then --1w, etc. Verifies gap
 * detection only fetches MISSING data when the requested range expands, and
 * fetches nothing for a repeat or a subset query.
 *
 * The current detector tracks coverage via the sync_ranges table, so the
 * simulated "fetch" both inserts logs AND records a completed sync_range for
 * the filled gap — mirroring what a real sync does.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;
use SolarWinds\Services\SyncTrackingService;

echo "=== Cross-Timeframe Database Sync Test ===\n\n";

$testDbPath = '/tmp/solarwinds-test-crosstime-' . uniqid() . '.db';
echo "Test database: $testDbPath\n\n";

$mockConfig = new class($testDbPath) extends ConfigurationService {
  protected string $testDbPath;

  /**
   * Capture the test database path.
   */
  public function __construct(string $testDbPath) {
    $this->testDbPath = $testDbPath;
  }

  /**
   * Return the test database path.
   */
  public function getDatabasePath(): string {
    return $this->testDbPath;
  }

  /**
   * Return a fixed 14-day retention window for deterministic edges.
   */
  public function getApiRetentionLimit(): int {
    return 14 * 86400;
  }
};

$db = new DatabaseService($mockConfig);
$tracking = new SyncTrackingService($mockConfig, $db);

/**
 * Simulate a sync over [start, end]: detect missing ranges, then for each
 * gap insert hourly logs and record a completed sync_range.
 *
 * @param DatabaseService $db Database service
 * @param SyncTrackingService $tracking Sync tracking service
 * @param int $start Requested start (unix timestamp)
 * @param int $end Requested end (unix timestamp)
 * @param string $label Human label for logging
 * @return array{gaps_fetched: int, hours_fetched: float}
 */
function simulateSync(DatabaseService $db, SyncTrackingService $tracking, int $start, int $end, string $label): array {
  echo "--- {$label} ---\n";
  $result = $tracking->detectMissingRanges($start, $end);

  if (empty($result['ranges'])) {
    echo "[COMPLETE] No gaps, no fetch needed\n\n";
    return ['gaps_fetched' => 0, 'hours_fetched' => 0];
  }

  $totalHours = 0;
  $gapsFetched = 0;
  foreach ($result['ranges'] as $gap) {
    $gapStart = (int) $gap['start_time'];
    $gapEnd = (int) $gap['end_time'];
    $hours = ($gapEnd - $gapStart) / 3600;
    echo "  Gap: " . ($hours) . "h ({$gap['reason']})\n";

    // Insert hourly logs across the gap.
    $logs = [];
    for ($t = $gapStart; $t <= $gapEnd; $t += 3600) {
      $iso = gmdate('Y-m-d\TH:i:s\Z', $t);
      $logs[] = [
        'id' => "log-$t",
        'time' => $iso,
        'message' => json_encode(['client_ip' => '1.2.3.4', 'req_uri' => '/test', 'time' => $iso]),
      ];
    }
    $db->insertLogs($logs);

    // Record a completed sync_range for the filled gap (what a real sync does).
    $stmt = $db->prepare(<<<'SQL'
INSERT INTO sync_ranges (start_time, end_time, status, started_at, completed_at, records_inserted)
VALUES (:s, :e, 'completed', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :n)
SQL
    );
    $stmt->execute([':s' => $gapStart, ':e' => $gapEnd, ':n' => count($logs)]);

    $totalHours += $hours;
    $gapsFetched++;
  }
  echo "  => fetched $gapsFetched gap(s), {$totalHours}h\n\n";
  return ['gaps_fetched' => $gapsFetched, 'hours_fetched' => $totalHours];
}

$assert = function(string $name, bool $cond, string $detail = ''): void {
  echo ($cond ? "✅ PASS: " : "❌ FAIL: ") . $name . "\n";
  if ($detail !== '') {
    echo "   $detail\n";
  }
};

$now = time();
$day = 86400;

// Test 1: Initial --1d query fetches the whole day.
$r = simulateSync($db, $tracking, $now - $day, $now, 'TEST 1: --1d initial');
$assert('--1d fetched 1 gap ~24h', $r['gaps_fetched'] === 1 && $r['hours_fetched'] >= 23 && $r['hours_fetched'] <= 25, "{$r['gaps_fetched']} gap(s), {$r['hours_fetched']}h");

// Test 2: --2d fetches only the older missing day.
$r = simulateSync($db, $tracking, $now - 2 * $day, $now, 'TEST 2: --2d expand');
$assert('--2d fetched 1 gap ~24h (older day only)', $r['gaps_fetched'] === 1 && $r['hours_fetched'] >= 23 && $r['hours_fetched'] <= 25, "{$r['gaps_fetched']} gap(s), {$r['hours_fetched']}h");

// Test 3: --1w fetches only the missing ~5 days.
$r = simulateSync($db, $tracking, $now - 7 * $day, $now, 'TEST 3: --1w expand');
$assert('--1w fetched 1 gap ~120h (5 more days)', $r['gaps_fetched'] === 1 && $r['hours_fetched'] >= 119 && $r['hours_fetched'] <= 121, "{$r['gaps_fetched']} gap(s), {$r['hours_fetched']}h");

// Test 4: Repeat --1w immediately fetches nothing.
$r = simulateSync($db, $tracking, $now - 7 * $day, $now, 'TEST 4: --1w repeat');
$assert('repeat --1w fetched 0 gaps', $r['gaps_fetched'] === 0, "{$r['gaps_fetched']} gap(s)");

// Test 5: Subset --3d (within --1w) fetches nothing.
$r = simulateSync($db, $tracking, $now - 3 * $day, $now, 'TEST 5: --3d subset');
$assert('subset --3d fetched 0 gaps', $r['gaps_fetched'] === 0, "{$r['gaps_fetched']} gap(s)");

// Test 6: Expand to --2w fetches only the additional older week.
$r = simulateSync($db, $tracking, $now - 14 * $day, $now, 'TEST 6: --2w expand');
$assert('--2w fetched 1 gap ~168h (7 more days)', $r['gaps_fetched'] === 1 && $r['hours_fetched'] >= 167 && $r['hours_fetched'] <= 169, "{$r['gaps_fetched']} gap(s), {$r['hours_fetched']}h");

echo "\n=== Cleanup ===\n";
@unlink($testDbPath);
@unlink($testDbPath . '-shm');
@unlink($testDbPath . '-wal');
echo "Cleaned up test database.\n";

echo "\n=== cross-timeframe test complete ===\n";
