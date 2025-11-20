#!/usr/bin/env php
<?php

/**
 * Integration test for incremental caching with mocked API
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\CacheService;
use SolarWinds\Services\ApiService;

echo "=== Incremental Cache Integration Test ===\n\n";

// Create isolated test environment
$testCacheDir = '/tmp/solarwinds-cache-integration-test-' . uniqid();
@mkdir($testCacheDir, 0755, TRUE);
echo "Test cache directory: $testCacheDir\n\n";

// Mock API service that returns predictable data
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

// Simulate command execution without full Symfony setup
function simulateQuery(
    MockApiService $api,
    CacheService $cache,
    string $query,
    string $startTime,
    string $endTime,
    int $runNumber
): array {
    echo "--- Run #{$runNumber}: Query from {$startTime} to {$endTime} ---\n";

    $scriptName = 'test-command';
    $timeArg = '--1d';
    $cacheKey = $cache->generateCacheKey($scriptName, $query, $timeArg, '');

    $timeRangeSeconds = strtotime($endTime) - strtotime($startTime);

    // Try incremental update
    $useIncremental = $cache->shouldUseIncrementalUpdate($cacheKey, $startTime, $endTime, $timeRangeSeconds);

  if ($useIncremental) {
      echo "[CACHE] Incremental update opportunity detected\n";

      // Load cached results
      $cachedResults = $cache->loadFromCache($cacheKey);
      $gap = $cache->calculateGapQuery($cacheKey, $endTime);

      echo "[CACHE] Fetching gap: {$gap['start_time']} to {$gap['end_time']}\n";

      // Fetch gap
      $freshResults = $api->searchLogs($query, $gap['start_time'], $gap['end_time']);

      // Merge
      $results = $cache->mergeAndDeduplicateResults($cachedResults, $freshResults);

      echo "[CACHE] Merged " . count($cachedResults) . " cached + " . count($freshResults) . " fresh = " . count($results) . " total\n";
  } else {
      // Check if cache exists and is fresh (standard cache hit)
      $metadata = $cache->loadMetadata($cacheKey);
    if ($metadata !== NULL && $metadata['version'] === 2) {
        $cacheAge = time() - $metadata['created_at'];
        $maxAge = (int) ($timeRangeSeconds * 0.5);

      if ($cacheAge <= $maxAge) {
        echo "[CACHE] Using fresh cache (age: {$cacheAge}s, max: {$maxAge}s)\n";
        $results = $cache->loadFromCache($cacheKey);
        echo "[CACHE] Loaded " . count($results) . " results from cache\n";
        return $results;
      }
    }

      // Full query
      echo "[API] Full query (no cache or cache too old)\n";
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

// TEST 1: First run - should do full query
echo "=== TEST 1: Initial Query (Full API Call) ===\n";
$now = time();
$oneDayAgo = $now - 86400;
$startTime1 = date('Y-m-d\TH:i:s\Z', $oneDayAgo);
$endTime1 = date('Y-m-d\TH:i:s\Z', $now);

$results1 = simulateQuery($mockApi, $cacheService, 'test query', $startTime1, $endTime1, 1);

$apiCalls1 = $mockApi->getCallCount();
echo "\nResult: {$apiCalls1} API call(s), " . count($results1) . " results\n";

if ($apiCalls1 === 1) {
    echo "✅ PASS: First run made 1 API call (full query)\n";
} else {
    echo "❌ FAIL: Expected 1 API call, got {$apiCalls1}\n";
}
echo "\n";

// Wait a moment and modify cache timestamp to simulate 10 hours passing
sleep(1);
$mockApi->resetCallCount();

// TEST 2: Second run 10 hours later - should use incremental
echo "=== TEST 2: Query 10 Hours Later (Incremental Update) ===\n";

// Manually adjust cache timestamp to simulate 10 hours ago
$cacheKey = $cacheService->generateCacheKey('test-command', 'test query', '--1d', '');
$metadata = $cacheService->loadMetadata($cacheKey);
$tenHoursAgo = $now - (10 * 3600);
$metadata['created_at'] = $tenHoursAgo;
$metadata['query_end_time'] = date('Y-m-d\TH:i:s\Z', $now); // Still ends at "now" from first query
$cacheService->saveMetadata($cacheKey, $metadata);

// New query: 10 hours have passed, so we want the last 24 hours from "new now"
$newNow = $now + (10 * 3600); // Simulate 10 hours passing
$newStart = $newNow - 86400;
$startTime2 = date('Y-m-d\TH:i:s\Z', $newStart);
$endTime2 = date('Y-m-d\TH:i:s\Z', $newNow);

$results2 = simulateQuery($mockApi, $cacheService, 'test query', $startTime2, $endTime2, 2);

$apiCalls2 = $mockApi->getCallCount();
$queryLog = $mockApi->getQueryLog();

echo "\nResult: {$apiCalls2} API call(s), " . count($results2) . " results\n";

if ($apiCalls2 === 1) {
    echo "✅ PASS: Incremental update made 1 API call (gap only)\n";

    // Verify the gap query was for ~10 hours
    $gapQuery = $queryLog[0];
    $gapStart = strtotime($gapQuery['start']);
    $gapEnd = strtotime($gapQuery['end']);
    $gapHours = ($gapEnd - $gapStart) / 3600;

  if ($gapHours >= 9 && $gapHours <= 11) {
      echo "✅ PASS: Gap query was for ~10 hours ({$gapHours}h)\n";
  } else {
      echo "❌ FAIL: Expected ~10h gap, got {$gapHours}h\n";
  }

    // Verify we got more results than the gap (because we merged)
  if (count($results2) > 10) {
      echo "✅ PASS: Merged results (" . count($results2) . ") > gap results (10)\n";
  } else {
      echo "❌ FAIL: Expected merged results > 10, got " . count($results2) . "\n";
  }
} else {
    echo "❌ FAIL: Expected 1 API call for gap, got {$apiCalls2}\n";
}
echo "\n";

// TEST 3: Third run immediately after - should use cache (no API call)
$mockApi->resetCallCount();
echo "=== TEST 3: Query Immediately After (Cache Hit) ===\n";

$results3 = simulateQuery($mockApi, $cacheService, 'test query', $startTime2, $endTime2, 3);

$apiCalls3 = $mockApi->getCallCount();
echo "\nResult: {$apiCalls3} API call(s), " . count($results3) . " results\n";

if ($apiCalls3 === 0) {
    echo "✅ PASS: Cache hit made 0 API calls\n";
} else {
    echo "❌ FAIL: Expected 0 API calls (cache hit), got {$apiCalls3}\n";
}
echo "\n";

// TEST 4: Fourth run with cache too old - should do full refresh
$mockApi->resetCallCount();
echo "=== TEST 4: Query With Stale Cache (Full Refresh) ===\n";

// Manually age the cache to 15 hours (> 50% of 24h)
$metadata = $cacheService->loadMetadata($cacheKey);
$fifteenHoursAgo = $now - (15 * 3600);
$metadata['created_at'] = $fifteenHoursAgo;
$cacheService->saveMetadata($cacheKey, $metadata);

$results4 = simulateQuery($mockApi, $cacheService, 'test query', $startTime1, $endTime1, 4);

$apiCalls4 = $mockApi->getCallCount();
echo "\nResult: {$apiCalls4} API call(s), " . count($results4) . " results\n";

if ($apiCalls4 === 1) {
    echo "✅ PASS: Stale cache triggered full refresh (1 API call)\n";

    // Verify it was a full 24h query, not a gap
    $fullQuery = $mockApi->getQueryLog()[0];
    $fullDuration = strtotime($fullQuery['end']) - strtotime($fullQuery['start']);
    $fullHours = $fullDuration / 3600;

  if ($fullHours >= 23 && $fullHours <= 25) {
      echo "✅ PASS: Full query was for ~24 hours ({$fullHours}h)\n";
  } else {
      echo "❌ FAIL: Expected ~24h full query, got {$fullHours}h\n";
  }
} else {
    echo "❌ FAIL: Expected 1 API call for full refresh, got {$apiCalls4}\n";
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

echo "=== Integration Test Complete ===\n";
echo "Summary:\n";
echo "  - Test 1 (Initial query): " . ($apiCalls1 === 1 ? "✅ PASS" : "❌ FAIL") . "\n";
echo "  - Test 2 (Incremental): " . ($apiCalls2 === 1 ? "✅ PASS" : "❌ FAIL") . "\n";
echo "  - Test 3 (Cache hit): " . ($apiCalls3 === 0 ? "✅ PASS" : "❌ FAIL") . "\n";
echo "  - Test 4 (Stale cache): " . ($apiCalls4 === 1 ? "✅ PASS" : "❌ FAIL") . "\n";
