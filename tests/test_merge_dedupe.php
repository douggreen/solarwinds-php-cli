#!/usr/bin/env php
<?php

/**
 * Test script for result merging and deduplication
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\CacheService;

$cache = new CacheService();

echo "=== Testing Result Merging and Deduplication ===\n\n";

// Test 1: Basic merge without duplicates
echo "Test 1: Basic merge without duplicates\n";
$cached = [
  ['id' => '1', 'message' => 'Cached log 1'],
  ['id' => '2', 'message' => 'Cached log 2'],
];
$fresh = [
  ['id' => '3', 'message' => 'Fresh log 3'],
  ['id' => '4', 'message' => 'Fresh log 4'],
];

$merged = $cache->mergeAndDeduplicateResults($cached, $fresh);
if (count($merged) === 4) {
  echo "✅ PASS: Merged 2 + 2 = 4 results\n";
} else {
  echo "❌ FAIL: Expected 4 results, got " . count($merged) . "\n";
}
echo "\n";

// Test 2: Merge with duplicates
echo "Test 2: Merge with duplicates (deduplication)\n";
$cached = [
  ['id' => '1', 'message' => 'Cached log 1'],
  ['id' => '2', 'message' => 'Cached log 2'],
  ['id' => '3', 'message' => 'Cached log 3'],
];
$fresh = [
  ['id' => '3', 'message' => 'Fresh log 3 (duplicate)'],
  ['id' => '4', 'message' => 'Fresh log 4'],
];

$merged = $cache->mergeAndDeduplicateResults($cached, $fresh);
if (count($merged) === 4) {
  echo "✅ PASS: Deduplicated 3 + 2 = 4 unique results\n";

  // Verify cached version kept (first occurrence)
  $log3 = array_filter($merged, fn($log) => ($log['id'] ?? NULL) === '3');
  $log3 = reset($log3);
  if ($log3 && strpos($log3['message'], 'Cached') !== FALSE) {
    echo "✅ PASS: Kept first occurrence (cached version)\n";
  } else {
    echo "❌ FAIL: Should have kept cached version of duplicate\n";
  }
} else {
  echo "❌ FAIL: Expected 4 results after deduplication, got " . count($merged) . "\n";
}
echo "\n";

// Test 3: Entries without IDs are included
echo "Test 3: Entries without IDs are included (no deduplication)\n";
$cached = [
  ['id' => '1', 'message' => 'Log with ID'],
  ['message' => 'Log without ID 1'],
];
$fresh = [
  ['message' => 'Log without ID 2'],
  ['id' => '2', 'message' => 'Another log with ID'],
];

$merged = $cache->mergeAndDeduplicateResults($cached, $fresh);
if (count($merged) === 4) {
  echo "✅ PASS: All 4 entries included (including those without IDs)\n";
} else {
  echo "❌ FAIL: Expected 4 results, got " . count($merged) . "\n";
}
echo "\n";

// Test 4: All duplicates scenario
echo "Test 4: All duplicates (cached data still fresh)\n";
$cached = [
  ['id' => '1', 'message' => 'Cached log 1'],
  ['id' => '2', 'message' => 'Cached log 2'],
  ['id' => '3', 'message' => 'Cached log 3'],
];
$fresh = [
  ['id' => '1', 'message' => 'Fresh log 1 (dup)'],
  ['id' => '2', 'message' => 'Fresh log 2 (dup)'],
  ['id' => '3', 'message' => 'Fresh log 3 (dup)'],
];

$merged = $cache->mergeAndDeduplicateResults($cached, $fresh);
if (count($merged) === 3) {
  echo "✅ PASS: Deduplicated 3 + 3 = 3 unique results\n";
} else {
  echo "❌ FAIL: Expected 3 unique results, got " . count($merged) . "\n";
}
echo "\n";

// Test 5: Empty arrays
echo "Test 5: Edge case - empty arrays\n";
$merged = $cache->mergeAndDeduplicateResults([], []);
if (count($merged) === 0) {
  echo "✅ PASS: Empty + empty = empty\n";
} else {
  echo "❌ FAIL: Expected 0 results, got " . count($merged) . "\n";
}

$merged = $cache->mergeAndDeduplicateResults([['id' => '1']], []);
if (count($merged) === 1) {
  echo "✅ PASS: Cached + empty = cached\n";
} else {
  echo "❌ FAIL: Expected 1 result, got " . count($merged) . "\n";
}

$merged = $cache->mergeAndDeduplicateResults([], [['id' => '1']]);
if (count($merged) === 1) {
  echo "✅ PASS: Empty + fresh = fresh\n";
} else {
  echo "❌ FAIL: Expected 1 result, got " . count($merged) . "\n";
}
echo "\n";

// Test 6: Large dataset performance
echo "Test 6: Performance with large dataset\n";
$largeCached = [];
$largeFresh = [];

for ($i = 1; $i <= 5000; $i++) {
  $largeCached[] = ['id' => (string) $i, 'message' => "Cached $i"];
}

for ($i = 4501; $i <= 5500; $i++) { // 500 duplicates, 500 new
  $largeFresh[] = ['id' => (string) $i, 'message' => "Fresh $i"];
}

$start = microtime(TRUE);
$merged = $cache->mergeAndDeduplicateResults($largeCached, $largeFresh);
$elapsed = microtime(TRUE) - $start;

$expected = 5500; // 5000 cached + 500 new (500 duplicates removed)
if (count($merged) === $expected) {
  echo "✅ PASS: Large merge: 5000 + 1000 = 5500 unique (500 duplicates removed)\n";
  echo "   Time: " . round($elapsed * 1000, 2) . "ms\n";
} else {
  echo "❌ FAIL: Expected $expected results, got " . count($merged) . "\n";
}
echo "\n";

echo "=== All Merge/Dedupe Tests Complete ===\n";
