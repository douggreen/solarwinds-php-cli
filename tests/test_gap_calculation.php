#!/usr/bin/env php
<?php

/**
 * Test script for incremental cache gap calculation
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\CacheService;

// Create test cache directory
$testCacheDir = '/tmp/solarwinds-cache-test-gap-' . uniqid();
@mkdir($testCacheDir, 0755, TRUE);

echo "Test Cache Directory: $testCacheDir\n\n";

$cache = new CacheService($testCacheDir);

// Test 1: Should use incremental - 1d query, 10h old cache
echo "=== Test 1: Should Use Incremental (--1d query, 10h old cache) ===\n";
$cacheKey1 = 'test_incremental_yes_' . uniqid();

// Create cache from 10 hours ago
$tenHoursAgo = time() - (10 * 3600);
$cache->saveToCache(
  $cacheKey1,
  [['id' => '1']],
  5,
  date('Y-m-d\TH:i:s\Z', $tenHoursAgo - 86400), // 10h ago minus 24h
  date('Y-m-d\TH:i:s\Z', $tenHoursAgo), // 10h ago
  86400,
  'test_hash',
  'test'
);

// Manually set created_at to 10 hours ago
$metadata = $cache->loadMetadata($cacheKey1);
$metadata['created_at'] = $tenHoursAgo;
$cache->saveMetadata($cacheKey1, $metadata);

// Current query: last 24 hours (now)
$currentEnd = date('Y-m-d\TH:i:s\Z');
$currentStart = date('Y-m-d\TH:i:s\Z', time() - 86400);

$shouldUse = $cache->shouldUseIncrementalUpdate(
  $cacheKey1,
  $currentStart,
  $currentEnd,
  86400
);

if ($shouldUse) {
  echo "✅ PASS: Should use incremental update\n";

  $gap = $cache->calculateGapQuery($cacheKey1, $currentEnd);
  if ($gap === NULL) {
    echo "❌ FAIL: Gap calculation returned NULL\n";
  } else {
    echo "   Gap start: {$gap['start_time']}\n";
    echo "   Gap end: {$gap['end_time']}\n";

    // Verify gap is approximately 10 hours
    $gapStart = strtotime($gap['start_time']);
    $gapEnd = strtotime($gap['end_time']);
    $gapHours = ($gapEnd - $gapStart) / 3600;
    if ($gapHours >= 9 && $gapHours <= 11) {
      echo "✅ PASS: Gap is approximately 10 hours ({$gapHours}h)\n";
    } else {
      echo "❌ FAIL: Gap should be ~10h, got {$gapHours}h\n";
    }
  }
} else {
  echo "❌ FAIL: Should have used incremental update\n";
}
echo "\n";

// Test 2: Should NOT use incremental - 1d query, 15h old cache (> 50%)
echo "=== Test 2: Should NOT Use Incremental (--1d query, 15h old cache) ===\n";
$cacheKey2 = 'test_incremental_no_old_' . uniqid();

$fifteenHoursAgo = time() - (15 * 3600);
$cache->saveToCache(
  $cacheKey2,
  [['id' => '2']],
  5,
  date('Y-m-d\TH:i:s\Z', $fifteenHoursAgo - 86400),
  date('Y-m-d\TH:i:s\Z', $fifteenHoursAgo),
  86400,
  'test_hash',
  'test'
);

$metadata = $cache->loadMetadata($cacheKey2);
$metadata['created_at'] = $fifteenHoursAgo;
$cache->saveMetadata($cacheKey2, $metadata);

$shouldUse = $cache->shouldUseIncrementalUpdate(
  $cacheKey2,
  $currentStart,
  $currentEnd,
  86400
);

if (!$shouldUse) {
  echo "✅ PASS: Should NOT use incremental (cache too old)\n";
} else {
  echo "❌ FAIL: Should NOT have used incremental update (cache > 50% old)\n";
}
echo "\n";

// Test 3: Should NOT use incremental - 1h query (too short)
echo "=== Test 3: Should NOT Use Incremental (--1h query, too short) ===\n";
$cacheKey3 = 'test_incremental_no_short_' . uniqid();

$oneHourAgo = time() - 3600;
$cache->saveToCache(
  $cacheKey3,
  [['id' => '3']],
  5,
  date('Y-m-d\TH:i:s\Z', $oneHourAgo - 3600),
  date('Y-m-d\TH:i:s\Z', $oneHourAgo),
  3600,
  'test_hash',
  'test'
);

$shouldUse = $cache->shouldUseIncrementalUpdate(
  $cacheKey3,
  date('Y-m-d\TH:i:s\Z', time() - 3600),
  date('Y-m-d\TH:i:s\Z'),
  3600 // 1 hour
);

if (!$shouldUse) {
  echo "✅ PASS: Should NOT use incremental (time range < 1d)\n";
} else {
  echo "❌ FAIL: Should NOT have used incremental for 1h query\n";
}
echo "\n";

// Test 4: Should NOT use incremental - legacy format cache
echo "=== Test 4: Should NOT Use Incremental (legacy format) ===\n";
$cacheKey4 = 'test_incremental_no_legacy_' . uniqid();

// Save in legacy format (no time range metadata)
$cache->saveToCache(
  $cacheKey4,
  [['id' => '4']],
  5
);

$shouldUse = $cache->shouldUseIncrementalUpdate(
  $cacheKey4,
  $currentStart,
  $currentEnd,
  86400
);

if (!$shouldUse) {
  echo "✅ PASS: Should NOT use incremental (legacy format)\n";
} else {
  echo "❌ FAIL: Should NOT have used incremental for legacy format\n";
}
echo "\n";

// Test 5: Should use incremental - 1w query, 2d old cache
echo "=== Test 5: Should Use Incremental (--1w query, 2d old cache) ===\n";
$cacheKey5 = 'test_incremental_yes_week_' . uniqid();

$twoDaysAgo = time() - (2 * 86400);
$oneWeekSeconds = 7 * 86400;

$cache->saveToCache(
  $cacheKey5,
  [['id' => '5']],
  5,
  date('Y-m-d\TH:i:s\Z', $twoDaysAgo - $oneWeekSeconds),
  date('Y-m-d\TH:i:s\Z', $twoDaysAgo),
  $oneWeekSeconds,
  'test_hash',
  'test'
);

$metadata = $cache->loadMetadata($cacheKey5);
$metadata['created_at'] = $twoDaysAgo;
$cache->saveMetadata($cacheKey5, $metadata);

$shouldUse = $cache->shouldUseIncrementalUpdate(
  $cacheKey5,
  date('Y-m-d\TH:i:s\Z', time() - $oneWeekSeconds),
  date('Y-m-d\TH:i:s\Z'),
  $oneWeekSeconds
);

if ($shouldUse) {
  echo "✅ PASS: Should use incremental update for 1w query\n";

  $gap = $cache->calculateGapQuery($cacheKey5, date('Y-m-d\TH:i:s\Z'));
  $gapStart = strtotime($gap['start_time']);
  $gapEnd = strtotime($gap['end_time']);
  $gapDays = ($gapEnd - $gapStart) / 86400;

  if ($gapDays >= 1.9 && $gapDays <= 2.1) {
    echo "✅ PASS: Gap is approximately 2 days ({$gapDays}d)\n";
  } else {
    echo "❌ FAIL: Gap should be ~2d, got {$gapDays}d\n";
  }
} else {
  echo "❌ FAIL: Should have used incremental update for 1w query\n";
}
echo "\n";

// Cleanup
echo "=== Cleanup ===\n";
$files = glob($testCacheDir . '/*');
foreach ($files as $file) {
  unlink($file);
}
rmdir($testCacheDir);
echo "Cleaned up test directory\n";

echo "\n=== All Gap Calculation Tests Complete ===\n";
