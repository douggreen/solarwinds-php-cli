#!/usr/bin/env php
<?php

/**
 * Test script for cross-timeframe database sync
 *
 * Tests the scenario: --1d, wait, --2d, wait, --1w
 * Verifies that gap detection works correctly when expanding time ranges
 * and that we only fetch missing data (not re-fetch existing data).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;

echo "=== Cross-Timeframe Database Sync Test ===\n\n";

// Create test database
$testDbPath = '/tmp/solarwinds-test-crosstime-' . uniqid() . '.db';
echo "Test database: $testDbPath\n\n";

// Create mock ConfigurationService that returns our test DB path
$mockConfig = new class($testDbPath) extends ConfigurationService {
  private string $testDbPath;

  public function __construct(string $testDbPath) {
    $this->testDbPath = $testDbPath;
  }

  public function getDatabasePath(): string {
    return $this->testDbPath;
  }
};

$db = new DatabaseService($mockConfig);

// Helper to simulate data sync
function simulateSync(DatabaseService $db, string $requestedStart, string $requestedEnd, string $label): array {
  echo "--- {$label}: Sync from {$requestedStart} to {$requestedEnd} ---\n";

  $result = $db->detectMissingRanges($requestedStart, $requestedEnd);

  if (!$result['has_data']) {
    echo "[NO DATA] Database is empty, need to fetch entire range\n";
    $gaps = $result['gaps'];
  } elseif (count($result['gaps']) === 0) {
    echo "[COMPLETE] Database has all requested data, no API calls needed\n";
    return ['gaps_fetched' => 0, 'hours_fetched' => 0];
  } else {
    echo "[PARTIAL] Database has some data, need to fetch gaps:\n";
    echo "  Existing coverage: {$result['coverage']['earliest']} to {$result['coverage']['latest']}\n";
    echo "  Record count: {$result['coverage']['count']}\n";
    $gaps = $result['gaps'];
  }

  $totalHoursFetched = 0;
  $gapsFetched = 0;

  foreach ($gaps as $gap) {
    $gapStart = strtotime($gap['start']);
    $gapEnd = strtotime($gap['end']);
    $gapHours = ($gapEnd - $gapStart) / 3600;

    echo "  Gap: {$gap['start']} to {$gap['end']} ({$gapHours}h, {$gap['reason']})\n";

    // Simulate API fetch by inserting test data for the gap
    $testLogs = [];
    for ($t = $gapStart; $t <= $gapEnd; $t += 3600) {
      $timestamp = date('Y-m-d\TH:i:s\Z', $t);
      $testLogs[] = [
        'id' => "log-{$t}",
        'time' => $timestamp,
        'message' => json_encode([
          'client_ip' => '1.2.3.4',
          'req_uri' => '/test',
          'time' => $timestamp,
        ]),
      ];
    }

    $inserted = $db->insertLogs($testLogs);
    echo "  [API] Fetched and inserted {$inserted} logs\n";

    $totalHoursFetched += $gapHours;
    $gapsFetched++;
  }

  echo "Summary: Fetched {$gapsFetched} gap(s), {$totalHoursFetched}h of data\n\n";

  return ['gaps_fetched' => $gapsFetched, 'hours_fetched' => $totalHoursFetched];
}

// Simulate time progression
$now = time();

// TEST 1: Run --1d query (initial sync)
echo "=== TEST 1: Initial --1d Query ===\n";
$oneDayAgo = $now - 86400;
$startTime1d = date('Y-m-d\TH:i:s\Z', $oneDayAgo);
$endTime1d = date('Y-m-d\TH:i:s\Z', $now);

$result1d = simulateSync($db, $startTime1d, $endTime1d, '--1d query');

if ($result1d['gaps_fetched'] === 1 && $result1d['hours_fetched'] >= 23 && $result1d['hours_fetched'] <= 25) {
  echo "✅ PASS: First --1d fetched 1 gap (~24h)\n";
} else {
  echo "❌ FAIL: Expected 1 gap of ~24h, got {$result1d['gaps_fetched']} gap(s) with {$result1d['hours_fetched']}h\n";
}
echo "\n";

// Verify database has 1 day of data
$allLogs = $db->getLogs($startTime1d, $endTime1d);
echo "Database now contains " . count($allLogs) . " logs\n\n";

// TEST 2: Run --2d query (should only fetch missing older day)
echo "=== TEST 2: Subsequent --2d Query ===\n";

$twoDaysAgo = $now - (2 * 86400);
$startTime2d = date('Y-m-d\TH:i:s\Z', $twoDaysAgo);
$endTime2d = date('Y-m-d\TH:i:s\Z', $now);

$result2d = simulateSync($db, $startTime2d, $endTime2d, '--2d query');

// Should fetch 1 gap (historical only, both use same end time "now")
$expectedGaps = 1;
$totalHours = $result2d['hours_fetched'];

if ($result2d['gaps_fetched'] === $expectedGaps) {
  echo "✅ PASS: --2d fetched {$expectedGaps} gap (historical only)\n";

  // Verify roughly 24h of new data
  if ($totalHours >= 23 && $totalHours <= 25) {
    echo "✅ PASS: Fetched ~24h of new data (didn't re-fetch existing day)\n";
  } else {
    echo "⚠️  WARNING: Expected ~24h, got {$totalHours}h\n";
  }
} else {
  echo "❌ FAIL: Expected {$expectedGaps} gap, got {$result2d['gaps_fetched']} gap(s)\n";
}
echo "\n";

// TEST 3: Run --1w query (should fetch 5 more days)
echo "=== TEST 3: Subsequent --1w Query ===\n";

$oneWeekAgo = $now - (7 * 86400);
$startTime1w = date('Y-m-d\TH:i:s\Z', $oneWeekAgo);
$endTime1w = date('Y-m-d\TH:i:s\Z', $now);

$result1w = simulateSync($db, $startTime1w, $endTime1w, '--1w query');

// Should fetch 1 gap (historical only - 5 days we don't have yet)
$expectedGaps = 1;
$totalHours = $result1w['hours_fetched'];
$expectedHours = 5 * 24; // ~120h

if ($result1w['gaps_fetched'] === $expectedGaps) {
  echo "✅ PASS: --1w fetched {$expectedGaps} gap (historical only)\n";

  // Verify roughly 5 days of new data
  if ($totalHours >= 119 && $totalHours <= 121) {
    echo "✅ PASS: Fetched ~5d of new data (didn't re-fetch existing 2 days)\n";
  } else {
    echo "⚠️  WARNING: Expected ~120h (5d), got {$totalHours}h\n";
  }
} else {
  echo "❌ FAIL: Expected {$expectedGaps} gap, got {$result1w['gaps_fetched']} gap(s)\n";
}
echo "\n";

// TEST 4: Run same --1w query again immediately - should fetch nothing
// Use same time range as previous query (no time progression)
echo "=== TEST 4: Repeat --1w Query (Immediately After) ===\n";

$result1wRepeat = simulateSync($db, $startTime1w, $endTime1w, '--1w query (repeat)');

if ($result1wRepeat['gaps_fetched'] === 0) {
  echo "✅ PASS: Repeat query fetched 0 gaps (all data already in database)\n";
} else {
  echo "❌ FAIL: Expected 0 gaps, got {$result1wRepeat['gaps_fetched']} gap(s)\n";
}
echo "\n";

// TEST 5: Query for subset of existing data - should fetch nothing
echo "=== TEST 5: Query Subset (--3d, which is within existing --1w data) ===\n";

$threeDaysAgo = $now - (3 * 86400);
$startTime3d = date('Y-m-d\TH:i:s\Z', $threeDaysAgo);
$endTime3d = date('Y-m-d\TH:i:s\Z', $now);

$result3d = simulateSync($db, $startTime3d, $endTime3d, '--3d query');

if ($result3d['gaps_fetched'] === 0) {
  echo "✅ PASS: Subset query fetched 0 gaps (data already exists)\n";
} else {
  echo "❌ FAIL: Expected 0 gaps, got {$result3d['gaps_fetched']} gap(s)\n";
}
echo "\n";

// TEST 6: Expand beyond existing range - should only fetch new portion
echo "=== TEST 6: Expand Range (--2w, which extends beyond existing --1w) ===\n";

$twoWeeksAgo = $now - (14 * 86400);
$startTime2w = date('Y-m-d\TH:i:s\Z', $twoWeeksAgo);
$endTime2w = date('Y-m-d\TH:i:s\Z', $now);

$result2w = simulateSync($db, $startTime2w, $endTime2w, '--2w query');

// Should fetch 1 gap at beginning (the additional older week)
$expectedGaps = 1;
$expectedHours = 7 * 24; // ~168h

if ($result2w['gaps_fetched'] === $expectedGaps) {
  echo "✅ PASS: Expanded range fetched {$expectedGaps} gap (historical only)\n";

  $totalHours = $result2w['hours_fetched'];
  if ($totalHours >= 167 && $totalHours <= 169) {
    echo "✅ PASS: Fetched ~7d of new data (didn't re-fetch existing week)\n";
  } else {
    echo "⚠️  WARNING: Expected ~168h (7d), got {$totalHours}h\n";
  }
} else {
  echo "❌ FAIL: Expected {$expectedGaps} gap, got {$result2w['gaps_fetched']} gap(s)\n";
}
echo "\n";

// Verify total database coverage
echo "=== Final Database Coverage ===\n";
$allLogs = $db->getLogs($startTime2w, $endTime2w);
$totalLogs = count($allLogs);
$expectedLogs = 14 * 24 + 1; // 14 days * 24 hours + 1 (inclusive end)

echo "Total logs in database: {$totalLogs}\n";
echo "Expected logs (~14 days): {$expectedLogs}\n";

if ($totalLogs >= $expectedLogs - 5 && $totalLogs <= $expectedLogs + 5) {
  echo "✅ PASS: Database contains ~2 weeks of data\n";
} else {
  echo "❌ FAIL: Expected ~{$expectedLogs} logs, got {$totalLogs}\n";
}
echo "\n";

// Cleanup
echo "=== Cleanup ===\n";
@unlink($testDbPath);
@unlink($testDbPath . '-shm');
@unlink($testDbPath . '-wal');
echo "Cleaned up test database\n";

echo "\n=== Cross-Timeframe Test Complete ===\n";
echo "\nKey Findings:\n";
echo "  - Initial --1d sync: " . ($result1d['gaps_fetched'] === 1 ? "✅ PASS" : "❌ FAIL") . "\n";
echo "  - Expand to --2d: " . ($result2d['gaps_fetched'] === 1 ? "✅ PASS" : "❌ FAIL") . "\n";
echo "  - Expand to --1w: " . ($result1w['gaps_fetched'] === 1 ? "✅ PASS" : "❌ FAIL") . "\n";
echo "  - Repeat --1w: " . ($result1wRepeat['gaps_fetched'] === 0 ? "✅ PASS" : "❌ FAIL") . "\n";
echo "  - Subset --3d: " . ($result3d['gaps_fetched'] === 0 ? "✅ PASS" : "❌ FAIL") . "\n";
echo "  - Expand to --2w: " . ($result2w['gaps_fetched'] === 1 ? "✅ PASS" : "❌ FAIL") . "\n";
