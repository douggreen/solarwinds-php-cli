#!/usr/bin/env php
<?php

/**
 * Test script for DatabaseService gap detection
 *
 * Tests detectMissingRanges() to ensure it correctly identifies
 * missing data at the beginning and end of requested time ranges.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;

// Create test database
$testDbPath = '/tmp/solarwinds-test-gap-' . uniqid() . '.db';

echo "Test Database: $testDbPath\n\n";

// Create mock ConfigurationService that returns our test DB path
$mockConfig = new class($testDbPath) extends ConfigurationService {
  private string $testDbPath;

  public function __construct(string $testDbPath) {
    $this->testDbPath = $testDbPath;
    // Don't call parent constructor
  }

  public function getDatabasePath(): string {
    return $this->testDbPath;
  }
};

$db = new DatabaseService($mockConfig);

// Test 1: Empty database - should detect full range as missing
echo "=== Test 1: Empty Database (No Data) ===\n";
$now = time();
$oneDayAgo = $now - 86400;
$requestedStart = date('Y-m-d\TH:i:s\Z', $oneDayAgo);
$requestedEnd = date('Y-m-d\TH:i:s\Z', $now);

$result = $db->detectMissingRanges($requestedStart, $requestedEnd);

if (!$result['has_data']) {
  echo "✅ PASS: Detected no data in database\n";
} else {
  echo "❌ FAIL: Should have detected no data\n";
}

if (count($result['gaps']) === 1) {
  $gap = $result['gaps'][0];
  if ($gap['start'] === $requestedStart && $gap['end'] === $requestedEnd && $gap['reason'] === 'no_data') {
    echo "✅ PASS: Gap covers entire requested range\n";
    echo "   Gap: {$gap['start']} to {$gap['end']}\n";
  } else {
    echo "❌ FAIL: Gap should cover entire range\n";
  }
} else {
  echo "❌ FAIL: Should have 1 gap, got " . count($result['gaps']) . "\n";
}
echo "\n";

// Test 2: Database has data up to 10 hours ago - should detect recent gap
echo "=== Test 2: Missing Recent Data (10h gap at end) ===\n";

$tenHoursAgo = $now - (10 * 3600);
$oneDayAgoForData = $tenHoursAgo - 86400;

// Insert logs from 34h ago to 10h ago (24h worth of data ending 10h ago)
$testLogs = [];
for ($t = $oneDayAgoForData; $t <= $tenHoursAgo; $t += 3600) {
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
echo "Inserted $inserted test logs (ending 10h ago)\n";

// Query for last 24 hours (should find gap at end)
$result = $db->detectMissingRanges($requestedStart, $requestedEnd);

if ($result['has_data']) {
  echo "✅ PASS: Detected existing data\n";
  echo "   Coverage: {$result['coverage']['earliest']} to {$result['coverage']['latest']}\n";
  echo "   Count: {$result['coverage']['count']}\n";
} else {
  echo "❌ FAIL: Should have detected existing data\n";
}

if (count($result['gaps']) === 1) {
  $gap = $result['gaps'][0];
  $gapStart = strtotime($gap['start']);
  $gapEnd = strtotime($gap['end']);
  $gapHours = ($gapEnd - $gapStart) / 3600;

  if ($gap['reason'] === 'recent' && $gapHours >= 9 && $gapHours <= 11) {
    echo "✅ PASS: Detected recent gap of ~10h\n";
    echo "   Gap: {$gap['start']} to {$gap['end']} ({$gapHours}h)\n";
  } else {
    echo "❌ FAIL: Gap should be ~10h recent data, got {$gapHours}h with reason '{$gap['reason']}'\n";
  }
} else {
  echo "❌ FAIL: Should have 1 gap (recent), got " . count($result['gaps']) . "\n";
}
echo "\n";

// Test 3: Database has data from 10h ago to now - should detect historical gap
echo "=== Test 3: Missing Historical Data (14h gap at beginning) ===\n";

// Clear database and insert only recent data (last 10h)
$db = new DatabaseService($mockConfig);
@unlink($testDbPath);
$db = new DatabaseService($mockConfig);

$testLogs = [];
for ($t = $tenHoursAgo; $t <= $now; $t += 3600) {
  $timestamp = date('Y-m-d\TH:i:s\Z', $t);
  $testLogs[] = [
    'id' => "log-recent-{$t}",
    'time' => $timestamp,
    'message' => json_encode([
      'client_ip' => '1.2.3.4',
      'req_uri' => '/test',
      'time' => $timestamp,
    ]),
  ];
}

$inserted = $db->insertLogs($testLogs);
echo "Inserted $inserted test logs (starting 10h ago)\n";

// Query for last 24 hours (should find gap at beginning)
$result = $db->detectMissingRanges($requestedStart, $requestedEnd);

if ($result['has_data']) {
  echo "✅ PASS: Detected existing data\n";
  echo "   Coverage: {$result['coverage']['earliest']} to {$result['coverage']['latest']}\n";
} else {
  echo "❌ FAIL: Should have detected existing data\n";
}

if (count($result['gaps']) === 1) {
  $gap = $result['gaps'][0];
  $gapStart = strtotime($gap['start']);
  $gapEnd = strtotime($gap['end']);
  $gapHours = ($gapEnd - $gapStart) / 3600;

  if ($gap['reason'] === 'historical' && $gapHours >= 13 && $gapHours <= 15) {
    echo "✅ PASS: Detected historical gap of ~14h\n";
    echo "   Gap: {$gap['start']} to {$gap['end']} ({$gapHours}h)\n";
  } else {
    echo "❌ FAIL: Gap should be ~14h historical data, got {$gapHours}h with reason '{$gap['reason']}'\n";
  }
} else {
  echo "❌ FAIL: Should have 1 gap (historical), got " . count($result['gaps']) . "\n";
}
echo "\n";

// Test 4: Database has all data - should detect no gaps
echo "=== Test 4: Complete Data (No Gaps) ===\n";

// Clear database and insert full 24h of data
$db = new DatabaseService($mockConfig);
@unlink($testDbPath);
$db = new DatabaseService($mockConfig);

$testLogs = [];
for ($t = $oneDayAgo; $t <= $now; $t += 3600) {
  $timestamp = date('Y-m-d\TH:i:s\Z', $t);
  $testLogs[] = [
    'id' => "log-complete-{$t}",
    'time' => $timestamp,
    'message' => json_encode([
      'client_ip' => '1.2.3.4',
      'req_uri' => '/test',
      'time' => $timestamp,
    ]),
  ];
}

$inserted = $db->insertLogs($testLogs);
echo "Inserted $inserted test logs (full 24h)\n";

$result = $db->detectMissingRanges($requestedStart, $requestedEnd);

if ($result['has_data']) {
  echo "✅ PASS: Detected existing data\n";
} else {
  echo "❌ FAIL: Should have detected existing data\n";
}

if (count($result['gaps']) === 0) {
  echo "✅ PASS: No gaps detected (complete coverage)\n";
} else {
  echo "❌ FAIL: Should have 0 gaps, got " . count($result['gaps']) . "\n";
  foreach ($result['gaps'] as $gap) {
    echo "   Unexpected gap: {$gap['start']} to {$gap['end']} ({$gap['reason']})\n";
  }
}
echo "\n";

// Test 5: Database has gap in middle but covers edges - should detect no gaps
echo "=== Test 5: Middle Gap (Not Detected - Edge Detection Only) ===\n";

// Clear database and insert data with gap in middle
$db = new DatabaseService($mockConfig);
@unlink($testDbPath);
$db = new DatabaseService($mockConfig);

$testLogs = [];

// Insert first 10h
$tenHoursAgoTimestamp = $oneDayAgo;
for ($t = $oneDayAgo; $t <= $oneDayAgo + (10 * 3600); $t += 3600) {
  $timestamp = date('Y-m-d\TH:i:s\Z', $t);
  $testLogs[] = [
    'id' => "log-early-{$t}",
    'time' => $timestamp,
    'message' => json_encode([
      'client_ip' => '1.2.3.4',
      'req_uri' => '/test',
      'time' => $timestamp,
    ]),
  ];
}

// Skip 4h gap in middle

// Insert last 10h
for ($t = $now - (10 * 3600); $t <= $now; $t += 3600) {
  $timestamp = date('Y-m-d\TH:i:s\Z', $t);
  $testLogs[] = [
    'id' => "log-late-{$t}",
    'time' => $timestamp,
    'message' => json_encode([
      'client_ip' => '1.2.3.4',
      'req_uri' => '/test',
      'time' => $timestamp,
    ]),
  ];
}

$inserted = $db->insertLogs($testLogs);
echo "Inserted $inserted test logs (with 4h gap in middle)\n";

$result = $db->detectMissingRanges($requestedStart, $requestedEnd);

if ($result['has_data']) {
  echo "✅ PASS: Detected existing data\n";
  echo "   Coverage: {$result['coverage']['earliest']} to {$result['coverage']['latest']}\n";
} else {
  echo "❌ FAIL: Should have detected existing data\n";
}

if (count($result['gaps']) === 0) {
  echo "✅ PASS: No gaps detected (edge detection only, middle gap ignored)\n";
  echo "   Note: detectMissingRanges() only detects edge gaps, not middle gaps\n";
} else {
  echo "❌ FAIL: Should have 0 gaps (middle gaps not detected), got " . count($result['gaps']) . "\n";
}
echo "\n";

// Test 6: Two-day query with only 1 day of data - should detect historical gap
echo "=== Test 6: Expanding Time Range (--1d to --2d) ===\n";

// Clear database and insert 1 day of data ending now
$db = new DatabaseService($mockConfig);
@unlink($testDbPath);
$db = new DatabaseService($mockConfig);

$testLogs = [];
for ($t = $oneDayAgo; $t <= $now; $t += 3600) {
  $timestamp = date('Y-m-d\TH:i:s\Z', $t);
  $testLogs[] = [
    'id' => "log-oneday-{$t}",
    'time' => $timestamp,
    'message' => json_encode([
      'client_ip' => '1.2.3.4',
      'req_uri' => '/test',
      'time' => $timestamp,
    ]),
  ];
}

$inserted = $db->insertLogs($testLogs);
echo "Inserted $inserted test logs (1 day)\n";

// Now query for 2 days
$twoDaysAgo = $now - (2 * 86400);
$requestedStart2d = date('Y-m-d\TH:i:s\Z', $twoDaysAgo);
$requestedEnd2d = date('Y-m-d\TH:i:s\Z', $now);

$result = $db->detectMissingRanges($requestedStart2d, $requestedEnd2d);

if (count($result['gaps']) === 1) {
  $gap = $result['gaps'][0];
  $gapStart = strtotime($gap['start']);
  $gapEnd = strtotime($gap['end']);
  $gapHours = ($gapEnd - $gapStart) / 3600;

  if ($gap['reason'] === 'historical' && $gapHours >= 23 && $gapHours <= 25) {
    echo "✅ PASS: Detected historical gap of ~24h (missing older day)\n";
    echo "   Gap: {$gap['start']} to {$gap['end']} ({$gapHours}h)\n";
  } else {
    echo "❌ FAIL: Gap should be ~24h historical, got {$gapHours}h with reason '{$gap['reason']}'\n";
  }
} else {
  echo "❌ FAIL: Should have 1 gap (historical day), got " . count($result['gaps']) . "\n";
}
echo "\n";

// Cleanup
echo "=== Cleanup ===\n";
@unlink($testDbPath);
@unlink($testDbPath . '-shm');
@unlink($testDbPath . '-wal');
echo "Cleaned up test database\n";

echo "\n=== All Gap Detection Tests Complete ===\n";
