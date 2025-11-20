#!/usr/bin/env php
<?php

/**
 * Test script for cross-timeframe cache reuse
 *
 * Tests the scenario: --1d, wait, --2d, wait, --1w
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\CacheService;

echo "=== Cross-Timeframe Cache Reuse Test ===\n\n";

// Create isolated test environment
$testCacheDir = '/tmp/solarwinds-cache-crosstime-test-' . uniqid();
@mkdir($testCacheDir, 0755, TRUE);
echo "Test cache directory: $testCacheDir\n\n";

// Mock API service
class MockApiService
{
  private int $callCount = 0;
  private array $queryLog = [];

  public function searchLogs(string $query, string $startTime, string $endTime, ?callable $progressCallback = NULL, ?callable $debugCallback = NULL): array
    {
      $this->callCount++;
      $this->queryLog[] = [
          'query' => $query,
          'start' => $startTime,
          'end' => $endTime,
          'call' => $this->callCount,
      ];

      // Generate fake log entries based on time range
      $start = strtotime($startTime);
      $end = strtotime($endTime);
      $duration = $end - $start;
      $numEntries = (int) ($duration / 3600); // 1 entry per hour

      $results = [];
      for ($i = 0; $i < $numEntries; $i++) {
          $timestamp = date('Y-m-d\TH:i:s\Z', $start + ($i * 3600));
          $results[] = [
              'id' => "log-{$timestamp}",
              'message' => "Mock log entry at {$timestamp}",
              'time' => $timestamp,
          ];
      }

      return $results;
  }

  public function getCallCount(): int
    {
      return $this->callCount;
  }

  public function getQueryLog(): array
    {
      return $this->queryLog;
  }

  public function resetCallCount(): void
    {
      $this->callCount = 0;
      $this->queryLog = [];
  }
}

// Create services
$cacheService = new CacheService($testCacheDir);
$mockApi = new MockApiService();

// Simulate query
function simulateQuery(
    MockApiService $api,
    CacheService $cache,
    string $query,
    string $startTime,
    string $endTime,
    string $label
): array {
    echo "--- {$label}: Query from {$startTime} to {$endTime} ---\n";

    $scriptName = 'test-command';
    $cacheKey = $cache->generateCacheKey($scriptName, $query, '');

    $timeRangeSeconds = strtotime($endTime) - strtotime($startTime);

    // Try incremental update
    $useIncremental = $cache->shouldUseIncrementalUpdate($cacheKey, $startTime, $endTime, $timeRangeSeconds);

  if ($useIncremental) {
      echo "[CACHE] Incremental update opportunity detected\n";

      $cachedResults = $cache->loadFromCache($cacheKey);
      $gaps = $cache->calculateGapQueries($cacheKey, $startTime, $endTime);

      $freshResults = [];
    if ($gaps['before'] !== NULL) {
        $gapStart = strtotime($gaps['before']['start_time']);
        $gapEnd = strtotime($gaps['before']['end_time']);
        $gapHours = ($gapEnd - $gapStart) / 3600;
        echo "[CACHE] Fetching before gap: {$gapHours}h ({$gaps['before']['start_time']} to {$gaps['before']['end_time']})\n";
        $beforeResults = $api->searchLogs($query, $gaps['before']['start_time'], $gaps['before']['end_time']);
        $freshResults = array_merge($freshResults, $beforeResults);
    }
    if ($gaps['after'] !== NULL) {
        $gapStart = strtotime($gaps['after']['start_time']);
        $gapEnd = strtotime($gaps['after']['end_time']);
        $gapHours = ($gapEnd - $gapStart) / 3600;
        echo "[CACHE] Fetching after gap: {$gapHours}h ({$gaps['after']['start_time']} to {$gaps['after']['end_time']})\n";
        $afterResults = $api->searchLogs($query, $gaps['after']['start_time'], $gaps['after']['end_time']);
        $freshResults = array_merge($freshResults, $afterResults);
    }

      $results = $cache->mergeAndDeduplicateResults($cachedResults, $freshResults);
      echo "[CACHE] Merged " . count($cachedResults) . " cached + " . count($freshResults) . " fresh = " . count($results) . " total\n";
  } else {
      echo "[API] Full query (no cache or cache not applicable)\n";
      $results = $api->searchLogs($query, $startTime, $endTime);
      echo "[API] Fetched " . count($results) . " results\n";
  }

    // Save to cache
    $cache->saveToCache(
        $cacheKey,
        $results,
        0,
        $startTime,
        $endTime,
        $timeRangeSeconds,
        md5($query),
        $scriptName
    );
    echo "[CACHE] Saved to cache\n";

    return $results;
}

// Simulate time progression
$now = time();

// TEST 1: Run --1d query
echo "=== TEST 1: Initial --1d Query ===\n";
$oneDayAgo = $now - 86400;
$startTime1d = date('Y-m-d\TH:i:s\Z', $oneDayAgo);
$endTime1d = date('Y-m-d\TH:i:s\Z', $now);

$results1d = simulateQuery($mockApi, $cacheService, 'test query', $startTime1d, $endTime1d, '--1d query');
$apiCalls1d = $mockApi->getCallCount();

echo "\nResult: {$apiCalls1d} API call(s), " . count($results1d) . " results\n";
if ($apiCalls1d === 1) {
    echo "✅ PASS: First --1d made 1 API call (full query)\n";
} else {
    echo "❌ FAIL: Expected 1 API call, got {$apiCalls1d}\n";
}
echo "\n";

// Simulate 2 minutes passing
sleep(1);
$mockApi->resetCallCount();

// Manually age the cache to simulate 2 minutes
$cacheKey = $cacheService->generateCacheKey('test-command', 'test query', '');
$metadata = $cacheService->loadMetadata($cacheKey);
$twoMinutesAgo = $now - 120;
$metadata['created_at'] = $twoMinutesAgo;
$cacheService->saveMetadata($cacheKey, $metadata);

// TEST 2: Run --2d query (should fetch 1d older data + 2min recent data)
echo "=== TEST 2: Subsequent --2d Query (2 minutes later) ===\n";
$newNow = $now + 120;
$twoDaysAgo = $newNow - (2 * 86400);
$startTime2d = date('Y-m-d\TH:i:s\Z', $twoDaysAgo);
$endTime2d = date('Y-m-d\TH:i:s\Z', $newNow);

$results2d = simulateQuery($mockApi, $cacheService, 'test query', $startTime2d, $endTime2d, '--2d query');
$apiCalls2d = $mockApi->getCallCount();

echo "\nResult: {$apiCalls2d} API call(s), " . count($results2d) . " results\n";

// Should make 2 API calls: one for older data (1d), one for recent data (2min)
$queryLog = $mockApi->getQueryLog();
$hasBeforeGap = FALSE;
$hasAfterGap = FALSE;

foreach ($queryLog as $q) {
    $duration = strtotime($q['end']) - strtotime($q['start']);
    $hours = $duration / 3600;

  if ($hours >= 23 && $hours <= 25) {
      $hasBeforeGap = TRUE;
      echo "✅ PASS: Found before gap query (~24h for older data)\n";
  }
  if ($hours < 1) {
      $hasAfterGap = TRUE;
      echo "✅ PASS: Found after gap query (<1h for recent data)\n";
  }
}

if ($apiCalls2d === 2 && $hasBeforeGap && $hasAfterGap) {
    echo "✅ PASS: --2d made 2 gap queries (before + after)\n";
} else {
    echo "❌ FAIL: Expected 2 gap queries (before + after), got {$apiCalls2d}\n";
}

// Verify total results
$expected2d = 48; // 48 hours
if (count($results2d) === $expected2d) {
    echo "✅ PASS: Got {$expected2d} results for --2d\n";
} else {
    echo "❌ FAIL: Expected {$expected2d} results, got " . count($results2d) . "\n";
}
echo "\n";

// Simulate more time passing
sleep(1);
$mockApi->resetCallCount();

// Age cache more
$metadata = $cacheService->loadMetadata($cacheKey);
$tenMinutesAgo = $now - 600;
$metadata['created_at'] = $tenMinutesAgo;
$cacheService->saveMetadata($cacheKey, $metadata);

// TEST 3: Run --1w query (should fetch 5d older data + 10min recent data)
echo "=== TEST 3: Subsequent --1w Query (10 minutes later) ===\n";
$newNow = $now + 600;
$oneWeekAgo = $newNow - (7 * 86400);
$startTime1w = date('Y-m-d\TH:i:s\Z', $oneWeekAgo);
$endTime1w = date('Y-m-d\TH:i:s\Z', $newNow);

$results1w = simulateQuery($mockApi, $cacheService, 'test query', $startTime1w, $endTime1w, '--1w query');
$apiCalls1w = $mockApi->getCallCount();

echo "\nResult: {$apiCalls1w} API call(s), " . count($results1w) . " results\n";

// Should make 2 API calls: one for older 5d, one for recent 10min
$queryLog = $mockApi->getQueryLog();
$hasBeforeGap = FALSE;
$hasAfterGap = FALSE;

foreach ($queryLog as $q) {
    $duration = strtotime($q['end']) - strtotime($q['start']);
    $hours = $duration / 3600;
    $days = $duration / 86400;

  if ($days >= 4.9 && $days <= 5.1) {
      $hasBeforeGap = TRUE;
      echo "✅ PASS: Found before gap query (~5d for older data)\n";
  }
  if ($hours < 1) {
      $hasAfterGap = TRUE;
      echo "✅ PASS: Found after gap query (<1h for recent data)\n";
  }
}

if ($apiCalls1w === 2 && $hasBeforeGap && $hasAfterGap) {
    echo "✅ PASS: --1w made 2 gap queries (before + after)\n";
} else {
    echo "❌ FAIL: Expected 2 gap queries (before + after), got {$apiCalls1w}\n";
}

// Verify total results
$expected1w = 168; // 168 hours
if (count($results1w) === $expected1w) {
    echo "✅ PASS: Got {$expected1w} results for --1w\n";
} else {
    echo "❌ FAIL: Expected {$expected1w} results, got " . count($results1w) . "\n";
}
echo "\n";

// Cleanup
echo "=== Cleanup ===\n";
$files = glob($testCacheDir . '/*');
foreach ($files as $file) {
    unlink($file);
}
rmdir($testCacheDir);
echo "Cleaned up test directory\n\n";

echo "=== Cross-Timeframe Test Complete ===\n";
