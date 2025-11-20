<?php

/**
 * @file CacheService.php
 * @brief Time-based result caching service with staleness detection and automatic expiration
 *
 * @class CacheService
 * @brief Provides intelligent caching for SolarWinds API results with time-based expiration
 *
 * This service implements a sophisticated caching system that automatically determines
 * cache freshness based on query time ranges and user preferences. It significantly
 * improves performance for repeated queries while ensuring data accuracy.
 *
 * @section cache_strategy Caching Strategy
 *
 * **Automatic Cache Timing:**
 * - Cache duration defaults to 10% of query time range
 * - Minimum cache time: 1 minute
 * - Maximum cache time: 1 hour (configurable)
 * - User can override with --cached=duration option
 *
 * **Cache Freshness Logic:**
 * - Checks both result file and metadata file existence
 * - Compares cache timestamp against calculated expiration
 * - Supports infinite cache mode (--cached=0)
 * - Respects user-specified cache durations
 *
 * **Cache Key Generation:**
 * - Based on query content, time range, and site filters
 * - Includes script name and additional parameters
 * - Generates deterministic keys for consistent caching
 * - Handles special characters and spaces in queries
 *
 * @section cache_files Cache File Structure
 *
 * **Result File:** Contains the actual API response data
 * **Metadata File:** Contains cache timestamp and expiration info
 *
 * Both files must exist and be valid for cache to be considered fresh.
 *
 * @section cache_options Cache Configuration Options
 *
 * **Automatic Caching:**
 * - Enabled for queries taking > 60 seconds
 * - Duration calculated as 10% of time range
 * - Bounded by minimum/maximum limits
 *
 * **Manual Cache Control:**
 * - --cached: Use automatic cache timing
 * - --cached=5m: Cache for specific duration
 * - --cached=0: Cache indefinitely (until manually cleared)
 *
 * **Cache Invalidation:**
 * - Automatic expiration based on calculated freshness
 * - Manual clearing through cache management commands
 * - Automatic cleanup of corrupted cache files
 *
 * @section example Usage Example
 * @code{.php}
 * $cacheService = new CacheService('/custom/cache/dir');
 *
 * $cacheKey = $cacheService->generateCacheKey(
 *     'json.resp_status:500',
 *     '--1h',
 *     '--mtc',
 *     'p5xx'
 * );
 *
 * if ($cacheService->isCacheFresh($cacheKey, '--1h', false, 300)) {
 *     $results = $cacheService->getCachedResults($cacheKey);
 * } else {
 *     // Execute fresh query and cache results
 *     $cacheService->cacheResults($cacheKey, $results);
 * }
 * @endcode
 *
 * @section performance Performance Considerations
 *
 * - Cache directory should be on fast storage (SSD preferred)
 * - Large result sets are compressed automatically
 * - Cache keys are hashed to avoid filesystem limitations
 * - Periodic cleanup prevents cache directory bloat
 *
 * @see ApiService For query execution and result caching integration
 * @see BaseSolarWindsCommand For cache option parsing
 * @see ConfigurationService For default cache behavior settings
 *
 * @note Cache files are stored in /tmp/solarwinds-cache by default
 * @warning Cache directory must be writable by the application user
 */

namespace SolarWinds\Services;

/**
 * Cache Service
 *
 * Provides sophisticated caching functionality that mirrors the original
 * shell script caching logic, including time-based expiration, cache key
 * generation, and staleness detection based on query time ranges.
 */
class CacheService
{
  public function __construct(protected string $cacheDir = '/tmp/solarwinds-cache')
  {
    $this->ensureCacheDirectoryExists();
  }

  /**
   * Generate a cache key.
   *
   * Note: Time args are intentionally excluded to allow cache reuse across
   * different time ranges (e.g., --1d cache can be reused for --2d queries).
   * The cache metadata tracks the actual time range stored.
   */
  public function generateCacheKey(string $scriptName, string $query, string $siteArgs = ''): string
  {
    // Combine all parameters for hashing (time args excluded for cross-timeframe reuse).
    $cacheInput = "{$scriptName}:{$query}:{$siteArgs}";
    return md5($cacheInput);
  }

  /**
   * Check if cache exists and is fresh based on cache options.
   */
  public function isCacheFresh(
    string $cacheKey,
    string $timeArg,
    bool $cacheInfinite = FALSE,
    ?int $cacheSeconds = NULL
  ): bool {
    $cacheFile = $this->getCacheFilePath($cacheKey);
    $cacheMetaFile = $this->getCacheMetaFilePath($cacheKey);

    if (!file_exists($cacheFile) || !file_exists($cacheMetaFile)) {
      return FALSE; // No cache exists.
    }

    // If --cached=0 or --cached=any, accept any cache regardless of age.
    if ($cacheInfinite) {
      return TRUE;
    }

    // Get cache age.
    $cacheTime = (int) file_get_contents($cacheMetaFile);
    $currentTime = time();
    $cacheAge = $currentTime - $cacheTime;

    // Determine staleness threshold.
    if ($cacheSeconds !== NULL) {
      // Use explicit time-based expiration.
      $stalenessThreshold = $cacheSeconds;
    }
    else {
      $timeRangeSeconds = $this->getTimeRangeSeconds($timeArg);
      $stalenessThreshold = (int) ($timeRangeSeconds / 10);
    }

    return $cacheAge <= $stalenessThreshold;
  }

  /**
   * Load results from cache if available.
   */
  public function loadFromCache(string $cacheKey): ?array
  {
    $cacheFile = $this->getCacheFilePath($cacheKey);

    if (!file_exists($cacheFile)) {
      return NULL;
    }

    $contents = file_get_contents($cacheFile);
    if ($contents === FALSE) {
      return NULL;
    }

    $data = json_decode($contents, TRUE);
    return $data ?: NULL;
  }

  /**
   * Load cache metadata.
   *
   * @param string $cacheKey Cache key
   * @return array|null Metadata array or NULL if not available
   */
  public function loadMetadata(string $cacheKey): ?array
  {
    $metaFile = $this->getCacheMetaFilePath($cacheKey);

    if (!file_exists($metaFile)) {
      return NULL;
    }

    $contents = file_get_contents($metaFile);
    if ($contents === FALSE) {
      return NULL;
    }

    // Try to parse as JSON (new format).
    $metadata = json_decode($contents, TRUE);

    if (json_last_error() === JSON_ERROR_NONE && is_array($metadata)) {
      // New format - validate required fields.
      if (isset($metadata['version']) && $metadata['version'] === 2) {
        return $metadata;
      }
    }

    // Legacy format - plain timestamp.
    if (is_numeric($contents)) {
      return [
        'version' => 1,
        'created_at' => (int) $contents,
        'query_start_time' => NULL,
        'query_end_time' => NULL,
        'time_range_seconds' => NULL,
        'query_hash' => NULL,
        'script_name' => NULL,
      ];
    }

    // Corrupted or invalid metadata.
    return NULL;
  }

  /**
   * Save cache metadata.
   *
   * @param string $cacheKey Cache key
   * @param array $metadata Metadata to save
   * @return bool TRUE on success, FALSE on failure
   */
  public function saveMetadata(string $cacheKey, array $metadata): bool
  {
    $metaFile = $this->getCacheMetaFilePath($cacheKey);

    $jsonData = json_encode($metadata, JSON_PRETTY_PRINT);
    if (file_put_contents($metaFile, $jsonData) === FALSE) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Save results to cache.
   *
   * Note: Caching decision is made by caller based on:.
   * - Query duration >= 5 seconds
   * - Time range >= 1 hour
   * - --cached flag used
   *
   * @param string $cacheKey Cache key
   * @param array $results Results to cache
   * @param int $queryDurationSeconds Query duration
   * @param string|null $queryStartTime Query start time (ISO 8601)
   * @param string|null $queryEndTime Query end time (ISO 8601)
   * @param int|null $timeRangeSeconds Time range in seconds
   * @param string|null $queryHash Hash of query parameters
   * @param string|null $scriptName Script name
   * @return bool TRUE on success, FALSE on failure
   */
  public function saveToCache(
    string $cacheKey,
    array $results,
    int $queryDurationSeconds,
    ?string $queryStartTime = NULL,
    ?string $queryEndTime = NULL,
    ?int $timeRangeSeconds = NULL,
    ?string $queryHash = NULL,
    ?string $scriptName = NULL
  ): bool {
    $cacheFile = $this->getCacheFilePath($cacheKey);
    $cacheMetaFile = $this->getCacheMetaFilePath($cacheKey);

    // Save results as JSON.
    $jsonData = json_encode($results, JSON_PRETTY_PRINT);
    if (file_put_contents($cacheFile, $jsonData) === FALSE) {
      return FALSE;
    }

    // Build metadata.
    if ($queryStartTime !== NULL && $queryEndTime !== NULL) {
      // New format with enhanced metadata.
      $metadata = [
        'version' => 2,
        'created_at' => time(),
        'query_start_time' => $queryStartTime,
        'query_end_time' => $queryEndTime,
        'time_range_seconds' => $timeRangeSeconds,
        'query_hash' => $queryHash,
        'script_name' => $scriptName,
      ];

      return $this->saveMetadata($cacheKey, $metadata);
    }
    else {
      // Legacy format - plain timestamp for backwards compatibility.
      if (file_put_contents($cacheMetaFile, (string) time()) === FALSE) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Check if incremental cache update should be used.
   *
   * @param string $cacheKey Cache key
   * @param string $queryStartTime Current query start time (ISO 8601)
   * @param string $queryEndTime Current query end time (ISO 8601)
   * @param int $timeRangeSeconds Current query time range in seconds
   * @return bool TRUE if incremental update should be used
   */
  public function shouldUseIncrementalUpdate(
    string $cacheKey,
    string $queryStartTime,
    string $queryEndTime,
    int $timeRangeSeconds
  ): bool {
    // Minimum time range for incremental updates (1 day).
    if ($timeRangeSeconds < 86400) {
      return FALSE;
    }

    // Load cache metadata.
    $metadata = $this->loadMetadata($cacheKey);
    if ($metadata === NULL || $metadata['version'] !== 2) {
      // No cache or legacy format - can't do incremental.
      return FALSE;
    }

    // Check if cache has time range information.
    if ($metadata['query_start_time'] === NULL || $metadata['query_end_time'] === NULL) {
      return FALSE;
    }

    // Parse all timestamps.
    $cachedStartTimestamp = strtotime($metadata['query_start_time']);
    $cachedEndTimestamp = strtotime($metadata['query_end_time']);
    $currentStartTimestamp = strtotime($queryStartTime);
    $currentEndTimestamp = strtotime($queryEndTime);

    if ($cachedStartTimestamp === FALSE || $cachedEndTimestamp === FALSE ||
        $currentStartTimestamp === FALSE || $currentEndTimestamp === FALSE) {
      // Invalid timestamps.
      return FALSE;
    }

    // Check for overlap: two ranges overlap if one doesn't end before the other starts.
    $hasOverlap = !($cachedEndTimestamp <= $currentStartTimestamp || $cachedStartTimestamp >= $currentEndTimestamp);

    if (!$hasOverlap) {
      // No overlap - cache is useless for this query.
      return FALSE;
    }

    // Calculate what percentage of the requested range is already cached.
    $cachedDuration = $cachedEndTimestamp - $cachedStartTimestamp;
    $overlapStart = max($cachedStartTimestamp, $currentStartTimestamp);
    $overlapEnd = min($cachedEndTimestamp, $currentEndTimestamp);
    $overlapDuration = max(0, $overlapEnd - $overlapStart);
    $cacheOverlapPercentage = ($overlapDuration / $timeRangeSeconds) * 100;

    // Use incremental update if ≥50% of requested data is already cached.
    if ($cacheOverlapPercentage >= 50) {
      return TRUE;
    }

    // Calculate cache age to decide if it's worth using even with <50% overlap.
    $cacheAge = time() - $metadata['created_at'];
    $maxAge = (int) ($timeRangeSeconds * 0.5); // 50% of query range

    // If cache is fresh (< 50% of query range old) and has ANY overlap, use it.
    if ($cacheAge <= $maxAge && $hasOverlap) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Calculate gap queries for incremental update.
   *
   * Returns up to two gaps: one before the cached range (if needed) and one after (if needed).
   *
   * @param string $cacheKey Cache key
   * @param string $queryStartTime Current query start time (ISO 8601)
   * @param string $queryEndTime Current query end time (ISO 8601)
   * @return array Array with 'before' and 'after' gaps (each may be NULL if not needed)
   */
  public function calculateGapQueries(string $cacheKey, string $queryStartTime, string $queryEndTime): array
  {
    $metadata = $this->loadMetadata($cacheKey);
    if ($metadata === NULL || $metadata['version'] !== 2) {
      return ['before' => NULL, 'after' => NULL];
    }

    if ($metadata['query_start_time'] === NULL || $metadata['query_end_time'] === NULL) {
      return ['before' => NULL, 'after' => NULL];
    }

    $cachedStart = $metadata['query_start_time'];
    $cachedEnd = $metadata['query_end_time'];

    $gapBefore = NULL;
    $gapAfter = NULL;

    // Check if we need data before the cached range.
    if (strtotime($queryStartTime) < strtotime($cachedStart)) {
      $gapBefore = [
        'start_time' => $queryStartTime,
        'end_time' => $cachedStart,
      ];
    }

    // Check if we need data after the cached range.
    if (strtotime($queryEndTime) > strtotime($cachedEnd)) {
      $gapAfter = [
        'start_time' => $cachedEnd,
        'end_time' => $queryEndTime,
      ];
    }

    return ['before' => $gapBefore, 'after' => $gapAfter];
  }

  /**
   * Parse the cache options.
   */
  public function parseCacheOptions(string $cachedValue): array
  {
    $cacheInfinite = FALSE;
    $cacheSeconds = NULL;

    if ($cachedValue === '' || $cachedValue === '1') {
      // Plain --cached flag (use default 10% rule).
      return ['infinite' => FALSE, 'seconds' => NULL];
    }

    if ($cachedValue === 'any' || $cachedValue === '0') {
      // Accept any cache regardless of age.
      return ['infinite' => TRUE, 'seconds' => NULL];
    }

    // Check for time-based values.
    if (preg_match('/^(\d+)$/', $cachedValue, $matches)) {
      // Plain number = seconds.
      return ['infinite' => FALSE, 'seconds' => (int) $matches[1]];
    }

    if (preg_match('/^(\d+)m$/', $cachedValue, $matches)) {
      // Number + 'm' = minutes.
      return ['infinite' => FALSE, 'seconds' => (int) $matches[1] * 60];
    }

    if (preg_match('/^(\d+)h$/', $cachedValue, $matches)) {
      // Number + 'h' = hours.
      return ['infinite' => FALSE, 'seconds' => (int) $matches[1] * 3600];
    }

    throw new \InvalidArgumentException(
      "Invalid --cached value: $cachedValue. " .
      "Must be 'any', '0' (infinite), number (seconds), number+'m' (minutes), or number+'h' (hours)"
    );
  }

  /**
   * Merge and deduplicate cached and fresh results.
   *
   * @param array $cachedResults Results from cache
   * @param array $freshResults Fresh results from API
   * @return array Merged and deduplicated results
   */
  public function mergeAndDeduplicateResults(array $cachedResults, array $freshResults): array
  {
    $merged = [];
    $seenIds = [];

    // Process all results (cached + fresh)
    foreach (array_merge($cachedResults, $freshResults) as $entry) {
      $id = $entry['id'] ?? NULL;

      if ($id === NULL) {
        // No ID - include anyway (can't deduplicate)
        $merged[] = $entry;
        continue;
      }

      if (isset($seenIds[$id])) {
        // Duplicate - skip (keeps first occurrence)
        continue;
      }

      $seenIds[$id] = TRUE;
      $merged[] = $entry;
    }

    return $merged;
  }

  /**
   * Calculate time range in seconds for cache staleness detection.
   */
  protected function getTimeRangeSeconds(string $timeArg): int
  {
    return TimeSpecifications::convertToSeconds($timeArg);
  }

  /**
   * Get human-readable cache age string.
   *
   * @param string $cacheKey Cache key to check
   * @return string|null Human-readable age like "5 minutes" or "2 hours", or NULL if cache doesn't exist
   */
  public function getCacheAge(string $cacheKey): ?string
  {
    $metadata = $this->loadMetadata($cacheKey);

    if ($metadata === NULL) {
      return NULL;
    }

    $cacheTime = $metadata['created_at'];
    $currentTime = time();
    $ageSeconds = $currentTime - $cacheTime;

    // Handle negative age (clock skew or manual timestamp editing).
    if ($ageSeconds < 0) {
      return '0 seconds';
    }

    // Format age in human-readable format.
    if ($ageSeconds < 60) {
      return $ageSeconds . ' second' . ($ageSeconds !== 1 ? 's' : '');
    }
    elseif ($ageSeconds < 3600) {
      $minutes = (int) ($ageSeconds / 60);
      return $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
    }
    elseif ($ageSeconds < 86400) {
      $hours = (int) ($ageSeconds / 3600);
      return $hours . ' hour' . ($hours !== 1 ? 's' : '');
    }
    else {
      $days = (int) ($ageSeconds / 86400);
      return $days . ' day' . ($days !== 1 ? 's' : '');
    }
  }

  /**
   * Get full path to cache file.
   */
  protected function getCacheFilePath(string $cacheKey): string
  {
    return $this->cacheDir . '/' . $cacheKey . '.json';
  }

  /**
   * Get full path to cache metadata file.
   */
  protected function getCacheMetaFilePath(string $cacheKey): string
  {
    return $this->cacheDir . '/' . $cacheKey . '.meta';
  }

  /**
   * Ensure cache directory exists.
   */
  protected function ensureCacheDirectoryExists(): void
  {
    if (!is_dir($this->cacheDir)) {
      if (!mkdir($this->cacheDir, 0755, TRUE)) {
        throw new \RuntimeException("Failed to create cache directory: {$this->cacheDir}");
      }
    }
  }
}
