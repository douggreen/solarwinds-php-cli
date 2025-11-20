#!/usr/bin/env php
<?php

/**
 * Test script for cache metadata backwards compatibility
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\CacheService;

// Create test cache directory
$testCacheDir = '/tmp/solarwinds-cache-test-' . uniqid();
@mkdir($testCacheDir, 0755, TRUE);

echo "Test Cache Directory: $testCacheDir\n\n";

$cache = new CacheService($testCacheDir);

// Test 1: Create and read legacy format
echo "=== Test 1: Legacy Format Compatibility ===\n";
$legacyCacheKey = 'test_legacy_' . uniqid();
$legacyMetaFile = $testCacheDir . '/' . $legacyCacheKey . '.meta';

// Write legacy format (plain timestamp)
$timestamp = time() - 3600; // 1 hour ago
file_put_contents($legacyMetaFile, (string) $timestamp);
echo "Created legacy metadata file with timestamp: $timestamp\n";

// Read it back
$metadata = $cache->loadMetadata($legacyCacheKey);
if ($metadata === NULL) {
  echo "❌ FAIL: Could not load legacy metadata\n";
} elseif ($metadata['version'] !== 1) {
  echo "❌ FAIL: Legacy metadata version should be 1, got {$metadata['version']}\n";
} elseif ($metadata['created_at'] !== $timestamp) {
  echo "❌ FAIL: Legacy timestamp mismatch. Expected $timestamp, got {$metadata['created_at']}\n";
} elseif ($metadata['query_start_time'] !== NULL) {
  echo "❌ FAIL: Legacy format should have NULL query_start_time\n";
} else {
  echo "✅ PASS: Legacy metadata loaded correctly\n";
  echo "   Version: {$metadata['version']}\n";
  echo "   Created: {$metadata['created_at']}\n";
}
echo "\n";

// Test 2: Create and read new format
echo "=== Test 2: New Format (Version 2) ===\n";
$newCacheKey = 'test_new_' . uniqid();
$newResults = [
  ['id' => '1', 'message' => 'Test log 1'],
  ['id' => '2', 'message' => 'Test log 2'],
];

$success = $cache->saveToCache(
  $newCacheKey,
  $newResults,
  5,
  '2024-11-15T14:00:00Z',
  '2024-11-16T14:00:00Z',
  86400,
  'test_query_hash',
  'test_command'
);

if (!$success) {
  echo "❌ FAIL: Could not save new format cache\n";
} else {
  echo "✅ Saved new format cache\n";
}

// Read it back
$metadata = $cache->loadMetadata($newCacheKey);
if ($metadata === NULL) {
  echo "❌ FAIL: Could not load new metadata\n";
} elseif ($metadata['version'] !== 2) {
  echo "❌ FAIL: New metadata version should be 2, got {$metadata['version']}\n";
} elseif ($metadata['query_start_time'] !== '2024-11-15T14:00:00Z') {
  echo "❌ FAIL: query_start_time mismatch\n";
} elseif ($metadata['query_end_time'] !== '2024-11-16T14:00:00Z') {
  echo "❌ FAIL: query_end_time mismatch\n";
} elseif ($metadata['time_range_seconds'] !== 86400) {
  echo "❌ FAIL: time_range_seconds should be 86400, got {$metadata['time_range_seconds']}\n";
} elseif ($metadata['query_hash'] !== 'test_query_hash') {
  echo "❌ FAIL: query_hash mismatch\n";
} elseif ($metadata['script_name'] !== 'test_command') {
  echo "❌ FAIL: script_name mismatch\n";
} else {
  echo "✅ PASS: New metadata loaded correctly\n";
  echo "   Version: {$metadata['version']}\n";
  echo "   Query start: {$metadata['query_start_time']}\n";
  echo "   Query end: {$metadata['query_end_time']}\n";
  echo "   Time range: {$metadata['time_range_seconds']}s\n";
  echo "   Query hash: {$metadata['query_hash']}\n";
  echo "   Script: {$metadata['script_name']}\n";
}
echo "\n";

// Test 3: Backwards compatibility - save without enhanced metadata
echo "=== Test 3: Backwards Compatibility (Legacy Save) ===\n";
$legacySaveCacheKey = 'test_legacy_save_' . uniqid();
$legacySaveResults = [
  ['id' => '3', 'message' => 'Legacy save test'],
];

$success = $cache->saveToCache(
  $legacySaveCacheKey,
  $legacySaveResults,
  5
  // No enhanced metadata parameters
);

if (!$success) {
  echo "❌ FAIL: Could not save legacy format\n";
} else {
  echo "✅ Saved legacy format cache\n";
}

// Read it back
$metadata = $cache->loadMetadata($legacySaveCacheKey);
if ($metadata === NULL) {
  echo "❌ FAIL: Could not load legacy saved metadata\n";
} elseif ($metadata['version'] !== 1) {
  echo "❌ FAIL: Legacy saved metadata should be version 1, got {$metadata['version']}\n";
} else {
  echo "✅ PASS: Legacy save creates version 1 metadata\n";
}
echo "\n";

// Test 4: Corrupted metadata handling
echo "=== Test 4: Corrupted Metadata Handling ===\n";
$corruptedKey = 'test_corrupted_' . uniqid();
$corruptedMetaFile = $testCacheDir . '/' . $corruptedKey . '.meta';

// Write invalid JSON
file_put_contents($corruptedMetaFile, '{invalid json}');

$metadata = $cache->loadMetadata($corruptedKey);
if ($metadata === NULL) {
  echo "✅ PASS: Corrupted metadata returns NULL\n";
} else {
  echo "❌ FAIL: Corrupted metadata should return NULL\n";
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

echo "\n=== All Tests Complete ===\n";
