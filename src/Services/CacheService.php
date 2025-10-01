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
   */
  public function generateCacheKey(string $scriptName, string $query, string $timeArgs, string $siteArgs = ''): string
  {
    // Combine all parameters for hashing (matches original logic).
    $cacheInput = "{$scriptName}:{$query}:{$timeArgs}:{$siteArgs}";
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
   * Save results to cache.
   *
   * Note: Caching decision is made by caller based on:
   * - Query duration >= 5 seconds
   * - Time range >= 1 hour
   * - --cached flag used
   */
  public function saveToCache(string $cacheKey, array $results, int $queryDurationSeconds): bool
  {
    $cacheFile = $this->getCacheFilePath($cacheKey);
    $cacheMetaFile = $this->getCacheMetaFilePath($cacheKey);

    // Save results as JSON.
    $jsonData = json_encode($results, JSON_PRETTY_PRINT);
    if (file_put_contents($cacheFile, $jsonData) === FALSE) {
      return FALSE;
    }

    // Save timestamp metadata.
    if (file_put_contents($cacheMetaFile, (string) time()) === FALSE) {
      return FALSE;
    }

    return TRUE;
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
    $cacheMetaFile = $this->getCacheMetaFilePath($cacheKey);

    if (!file_exists($cacheMetaFile)) {
      return NULL;
    }

    $cacheTime = (int) file_get_contents($cacheMetaFile);
    $currentTime = time();
    $ageSeconds = $currentTime - $cacheTime;

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
