#!/usr/bin/env php
<?php

/**
 * Test script for SyncTrackingService::getCoverageSummary()
 *
 * Verifies the status-oriented coverage logic that powers `sync:status`:
 *  - merges adjacent/overlapping completed sync ranges into contiguous spans
 *  - reports only GENUINE holes (gaps larger than the threshold)
 *  - tags each hole refillable vs permanent against the API retention window
 *  - reports freshness (lag from last log to now) separately, never as a hole
 *  - treats pre-retention data as covered, not missing
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;
use SolarWinds\Services\SyncTrackingService;

$testDbPath = '/tmp/solarwinds-test-coverage-' . uniqid() . '.db';
echo "Test Database: $testDbPath\n\n";

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
   * Return a fixed 14-day retention window for testing.
   */
  public function getApiRetentionLimit(): int {
    return 14 * 86400;
  }
};

$resetDb = function() use ($mockConfig, $testDbPath): array {
  @unlink($testDbPath);
  @unlink($testDbPath . '-shm');
  @unlink($testDbPath . '-wal');
  $db = new DatabaseService($mockConfig);
  $tracking = new SyncTrackingService($mockConfig, $db);
  return [$db, $tracking];
};

$assert = function(string $name, bool $cond, string $detail = ''): void {
  echo ($cond ? "✅ PASS: " : "❌ FAIL: ") . $name . "\n";
  if ($detail !== '') {
    echo "   $detail\n";
  }
};

// Insert one log at a given unix time so the logs aggregate has data.
$insertLogAt = function(DatabaseService $db, int $t): void {
  $iso = gmdate('Y-m-d\TH:i:s\Z', $t);
  $db->insertLogs([[
    'id' => "log-$t",
    'time' => $iso,
    'message' => json_encode([
      'client_ip' => '1.2.3.4',
      'req_uri' => '/test',
      'time' => $iso,
    ]),
  ]]);
};

// Insert a completed sync_ranges row directly.
$insertRange = function(DatabaseService $db, int $start, int $end): void {
  $stmt = $db->prepare(<<<'SQL'
INSERT INTO sync_ranges (start_time, end_time, status, started_at, completed_at, records_inserted)
VALUES (:s, :e, 'completed', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1)
SQL
  );
  $stmt->execute([':s' => $start, ':e' => $end]);
};

$now = time();
$hour = 3600;
$day = 86400;

// Test 1: Empty database.
echo "=== Test 1: Empty database ===\n";
[$db, $tracking] = $resetDb();
$s = $tracking->getCoverageSummary();
$assert('has_data is FALSE', $s['has_data'] === FALSE);
$assert('no holes', $s['holes'] === []);
echo "\n";

// Test 2: Contiguous ranges (adjacent + overlapping) => no holes.
echo "=== Test 2: Contiguous coverage ===\n";
[$db, $tracking] = $resetDb();
$base = $now - 5 * $day;
$insertLogAt($db, $base);
$insertLogAt($db, $base + 3 * $hour);
// Three ranges: adjacent, then overlapping — should merge into one span.
$insertRange($db, $base, $base + $hour);
$insertRange($db, $base + $hour, $base + 2 * $hour);          // adjacent
$insertRange($db, $base + 2 * $hour - 60, $base + 3 * $hour); // overlapping
$s = $tracking->getCoverageSummary();
$assert('has_data', $s['has_data'] === TRUE);
$assert('no holes (merged contiguous)', $s['holes'] === [], 'got ' . count($s['holes']) . ' holes');
$assert('earliest matches', $s['earliest'] === $base);
echo "\n";

// Test 3: A real mid-history hole, INSIDE retention => refillable.
echo "=== Test 3: Real hole within retention (refillable) ===\n";
[$db, $tracking] = $resetDb();
$a = $now - 5 * $day;
$insertLogAt($db, $a);
$insertRange($db, $a, $a + $hour);
// 3-hour hole, then resume — entirely within the 14-day window.
$insertRange($db, $a + 4 * $hour, $a + 5 * $hour);
$s = $tracking->getCoverageSummary();
$assert('exactly one hole', count($s['holes']) === 1, 'got ' . count($s['holes']));
if (count($s['holes']) === 1) {
  $h = $s['holes'][0];
  $assert('hole spans the 3-hour gap', $h['seconds'] === 3 * $hour, "got {$h['seconds']}s");
  $assert('hole is refillable (within retention)', $h['refillable'] === TRUE);
}
echo "\n";

// Test 4: A real hole BEYOND retention => permanent.
echo "=== Test 4: Real hole beyond retention (permanent) ===\n";
[$db, $tracking] = $resetDb();
$old = $now - 30 * $day;
$insertLogAt($db, $old);
$insertRange($db, $old, $old + $hour);
$insertRange($db, $old + 5 * $hour, $old + 6 * $hour); // hole at ~30 days ago
$s = $tracking->getCoverageSummary();
$assert('exactly one hole', count($s['holes']) === 1, 'got ' . count($s['holes']));
if (count($s['holes']) === 1) {
  $assert('hole is PERMANENT (beyond retention)', $s['holes'][0]['refillable'] === FALSE);
}
echo "\n";

// Test 5: Sub-threshold gap is treated as contiguous (chunk-boundary rounding).
echo "=== Test 5: Tiny gap below threshold => contiguous ===\n";
[$db, $tracking] = $resetDb();
$b = $now - 3 * $day;
$insertLogAt($db, $b);
$insertRange($db, $b, $b + $hour);
$insertRange($db, $b + $hour + 30, $b + 2 * $hour); // 30-second gap, < 120 threshold
$s = $tracking->getCoverageSummary();
$assert('30s gap is NOT a hole', $s['holes'] === [], 'got ' . count($s['holes']) . ' holes');
echo "\n";

// Test 6: Freshness reported separately, trailing lag is not a hole.
echo "=== Test 6: Freshness, not a hole ===\n";
[$db, $tracking] = $resetDb();
$c = $now - 2 * $day;
$insertLogAt($db, $c);
$insertLogAt($db, $now - 1800); // latest log 30 min ago
$insertRange($db, $c, $now - 1800);
$s = $tracking->getCoverageSummary();
$assert('no holes despite 30-min freshness lag', $s['holes'] === []);
$assert('fresh_seconds ~30 min', $s['fresh_seconds'] >= 1700 && $s['fresh_seconds'] <= 1900, "got {$s['fresh_seconds']}s");
echo "\n";

// Cleanup.
echo "=== Cleanup ===\n";
@unlink($testDbPath);
@unlink($testDbPath . '-shm');
@unlink($testDbPath . '-wal');
echo "Removed test database.\n";

echo "\n=== getCoverageSummary tests complete ===\n";
