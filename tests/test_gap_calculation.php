#!/usr/bin/env php
<?php

/**
 * Test script for SyncTrackingService::detectMissingRanges()
 *
 * Verifies gap detection identifies missing data at the edges of a requested
 * range. Uses the current sync_ranges-based detector (auto-initialized from
 * log extent when sync_ranges is empty), so a single detection call after a
 * fresh insert sees one contiguous span and reports edge gaps with the
 * current reason labels (no_data, gap_between_syncs, recent_data).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;
use SolarWinds\Services\SyncTrackingService;

$testDbPath = '/tmp/solarwinds-test-gap-' . uniqid() . '.db';
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
   * Return a fixed 14-day retention window so edge cases are deterministic.
   */
  public function getApiRetentionLimit(): int {
    return 14 * 86400;
  }
};

$reset = function() use ($mockConfig, $testDbPath): array {
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

// Insert hourly logs across [from, to] (inclusive).
$insertHourly = function(DatabaseService $db, int $from, int $to): void {
  $logs = [];
  for ($t = $from; $t <= $to; $t += 3600) {
    $iso = gmdate('Y-m-d\TH:i:s\Z', $t);
    $logs[] = [
      'id' => "log-$t",
      'time' => $iso,
      'message' => json_encode([
        'client_ip' => '1.2.3.4',
        'req_uri' => '/test',
        'time' => $iso,
      ]),
    ];
  }
  $db->insertLogs($logs);
};

$now = time();
$hour = 3600;
$day = 86400;
$oneDayAgo = $now - $day;

// Test 1: Empty database — entire requested range missing as 'no_data'.
echo "=== Test 1: Empty database ===\n";
[$db, $tracking] = $reset();
$result = $tracking->detectMissingRanges($oneDayAgo, $now);
$assert('has_data is FALSE', $result['has_data'] === FALSE);
$assert('one missing range', count($result['ranges']) === 1, 'got ' . count($result['ranges']));
if (count($result['ranges']) === 1) {
  $r = $result['ranges'][0];
  $assert(
    'range covers the request, reason no_data',
    (int) $r['start_time'] === $oneDayAgo && (int) $r['end_time'] === $now && $r['reason'] === 'no_data',
    "reason={$r['reason']}"
  );
}
echo "\n";

// Test 2: Data ending 10h ago — gap at the recent edge.
echo "=== Test 2: Missing recent data (~10h at end) ===\n";
[$db, $tracking] = $reset();
$tenHoursAgo = $now - 10 * $hour;
$insertHourly($db, $tenHoursAgo - $day, $tenHoursAgo);
$result = $tracking->detectMissingRanges($oneDayAgo, $now);
$assert('has_data is TRUE', $result['has_data'] === TRUE);
$assert('one gap', count($result['ranges']) === 1, 'got ' . count($result['ranges']));
if (count($result['ranges']) === 1) {
  $r = $result['ranges'][0];
  $h = ((int) $r['end_time'] - (int) $r['start_time']) / 3600;
  $assert('recent gap ~10h, reason recent_data', $r['reason'] === 'recent_data' && $h >= 9 && $h <= 11, "reason={$r['reason']}, {$h}h");
}
echo "\n";

// Test 3: Data starting 10h ago — gap at the historical edge.
echo "=== Test 3: Missing historical data (~14h at start) ===\n";
[$db, $tracking] = $reset();
$insertHourly($db, $now - 10 * $hour, $now);
$result = $tracking->detectMissingRanges($oneDayAgo, $now);
$assert('one gap', count($result['ranges']) === 1, 'got ' . count($result['ranges']));
if (count($result['ranges']) === 1) {
  $r = $result['ranges'][0];
  $h = ((int) $r['end_time'] - (int) $r['start_time']) / 3600;
  $assert('historical gap ~14h, reason gap_between_syncs', $r['reason'] === 'gap_between_syncs' && $h >= 13 && $h <= 15, "reason={$r['reason']}, {$h}h");
}
echo "\n";

// Test 4: Complete coverage — no gaps.
echo "=== Test 4: Complete coverage ===\n";
[$db, $tracking] = $reset();
$insertHourly($db, $oneDayAgo, $now);
$result = $tracking->detectMissingRanges($oneDayAgo, $now);
$assert('zero gaps', count($result['ranges']) === 0, 'got ' . count($result['ranges']));
echo "\n";

// Test 5: Middle gap is hidden — auto-init spans MIN..MAX, so edges-only.
echo "=== Test 5: Middle gap not reported (single auto-init span) ===\n";
[$db, $tracking] = $reset();
$insertHourly($db, $oneDayAgo, $oneDayAgo + 10 * $hour); // first 10h
$insertHourly($db, $now - 10 * $hour, $now);             // last 10h (4h hole between)
$result = $tracking->detectMissingRanges($oneDayAgo, $now);
$assert('zero gaps (middle hole not detected)', count($result['ranges']) === 0, 'got ' . count($result['ranges']));
echo "\n";

// Test 6: Expanding the request beyond stored data finds the older gap.
echo "=== Test 6: Expanded range (--1d data, --2d query) ===\n";
[$db, $tracking] = $reset();
$insertHourly($db, $oneDayAgo, $now);
$result = $tracking->detectMissingRanges($now - 2 * $day, $now);
$assert('one gap', count($result['ranges']) === 1, 'got ' . count($result['ranges']));
if (count($result['ranges']) === 1) {
  $r = $result['ranges'][0];
  $h = ((int) $r['end_time'] - (int) $r['start_time']) / 3600;
  $assert('older gap ~24h, reason gap_between_syncs', $r['reason'] === 'gap_between_syncs' && $h >= 23 && $h <= 25, "reason={$r['reason']}, {$h}h");
}
echo "\n";

echo "=== Cleanup ===\n";
@unlink($testDbPath);
@unlink($testDbPath . '-shm');
@unlink($testDbPath . '-wal');
echo "Cleaned up test database.\n";

echo "\n=== gap detection tests complete ===\n";
