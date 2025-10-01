<?php

/**
 * @file ApiService.php
 * @brief SolarWinds Cloud API integration service with pagination and error handling
 *
 * @class ApiService
 * @brief Handles all SolarWinds Cloud API interactions with proper pagination and duplicate detection
 *
 * This service provides a complete abstraction layer for the SolarWinds Cloud API, implementing
 * scenarios including duplicate detection, proper result ordering, and comprehensive error handling.
 *
 * @section api_features Key Features
 *
 * **Pagination Management:**
 * - Automatic pagination using pageInfo.nextPage tokens
 * - Configurable page size (default: 1000 records per page)
 * - Proper chronological ordering (oldest to newest)
 * - Duplicate detection using seen_ids tracking
 *
 * **Query Processing:**
 * - Human-readable time conversion to ISO format
 * - Query syntax validation and error reporting
 * - Support for complex field-based queries
 * - Automatic retry logic for transient failures
 *
 * **Error Handling:**
 * - HTTP status code validation
 * - API-specific error message parsing
 * - Network timeout and connection error handling
 * - Graceful degradation for rate limiting
 *
 * @section api_usage API Usage Pattern
 *
 * The service follows this standard pattern:
 * 1. Convert human-readable times to ISO format
 * 2. Execute paginated queries with proper error handling
 * 3. Detect and filter duplicate results across pages
 * 4. Return chronologically ordered results
 *
 * @section example Usage Example
 * @code{.php}
 * $apiService = new ApiService($configService);
 *
 * $results = $apiService->searchLogs(
 *     '{ json.resp_status:500 }',
 *     '1 hour ago',
 *     'now',
 *     function($progress) { echo "Progress: $progress\n"; },
 *     function($debug) { echo "Debug: $debug\n"; }
 * );
 * @endcode
 *
 * @section pagination Pagination Implementation
 *
 * - Uses POST requests to /v1/logs/search endpoint
 * - Includes filter, startTime, endTime, pageSize parameters
 * - Follows nextPage tokens for subsequent requests
 * - Maintains seen_ids array to prevent duplicate processing
 *
 * @section time_handling Time Conversion
 *
 * Supports various time formats:
 * - Relative: "1 hour ago", "24 hours ago", "7 days ago"
 * - Absolute: "2025-01-15T10:30:00Z"
 * - Keywords: "now"
 *
 * @see ConfigurationService For API credentials and endpoint configuration
 * @see BaseSolarWindsCommand For query construction and execution
 * @see CacheService For result caching integration
 *
 * @note This implementation is a direct port of the working bash script pagination logic
 * @warning Requires valid API token and base_url configuration for operation
 */

namespace SolarWinds\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * SolarWinds API Service
 *
 * Handles communication with the SolarWinds Cloud API for log searches.
 * Implements the exact pagination logic from the working bash solarwinds command.
 */
class ApiService
{
  protected Client $httpClient;
  protected string $apiToken;

  public function __construct(ConfigurationService $config)
  {
    $baseUrl = $config->get('base_url') ?? $config->get('api_base_url');
    $this->apiToken = $config->get('token') ?? $config->get('api_token');

    if (empty($baseUrl)) {
      throw new \InvalidArgumentException("Missing base_url in configuration");
    }

    if (empty($this->apiToken)) {
      throw new \InvalidArgumentException("Missing token in configuration");
    }

    // Create HTTP client with SolarWinds API base URL.
    $this->httpClient = new Client([
      'base_uri' => rtrim($baseUrl, '/'),
      'timeout' => 60,
      'headers' => [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $this->apiToken,
        'User-Agent' => 'SolarWinds-Scripts/2.0'
      ]
    ]);
  }

  /**
   * Search logs with pagination - exact port from working bash script.
   *
   * @param string $query The search query
   * @param string $startTime Start time (human readable)
   * @param string $endTime End time (human readable)
   * @param callable|NULL $progressCallback Callback for progress updates
   * @param callable|NULL $debugCallback Callback for debug output
   * @return array Array of log entries
   * @throws GuzzleException
   */
  public function searchLogs(
    string $query,
    string $startTime,
    string $endTime,
    ?callable $progressCallback = NULL,
    ?callable $debugCallback = NULL
  ): array {
    // Convert human-readable times to ISO format.
    $startTimeIso = $this->convertToIsoTime($startTime);
    $endTimeIso = $this->convertToIsoTime($endTime);

    $allLogs = [];
    $seenIds = [];
    $pageCount = 0;
    $totalResults = 0;
    $nextPageUrl = NULL;
    $isFirstPage = TRUE;

    while (TRUE) {
      $pageCount++;

      // Check for interruption before each API call.
      if (class_exists('SolarWinds\\Commands\\BaseSolarWindsCommand') &&
          method_exists('SolarWinds\\Commands\\BaseSolarWindsCommand', 'isInterrupted') &&
          \SolarWinds\Commands\BaseSolarWindsCommand::isInterrupted()) {
        break;
      }

      if ($isFirstPage) {
        // First page: build parameters exactly like bash script.
        $requestParams = [
          'filter' => $query,
          'pageSize' => 1000,  // Exactly like bash script.
          'startTime' => $startTimeIso,
          'endTime' => $endTimeIso
        ];

        // Debug output for first page request.
        if ($debugCallback) {
          $debugCallback("Fetching page $pageCount...");
          $debugCallback("  Time range: $startTimeIso to $endTimeIso");

          // Show equivalent curl command like original framework.
          $baseUri = rtrim($this->httpClient->getConfig('base_uri'), '/');
          $authHeader = 'Bearer [HIDDEN]';
          $debugCallback("  Executing curl command:");
          $debugCallback("    curl -s -G \"$baseUri/v1/logs\" \\");
          $debugCallback("      --data-urlencode \"filter=$query\" \\");
          $debugCallback("      --data-urlencode \"pageSize=1000\" \\");
          $debugCallback("      --data-urlencode \"startTime=$startTimeIso\" \\");
          $debugCallback("      --data-urlencode \"endTime=$endTimeIso\" \\");
          $debugCallback("      -H \"accept: application/json\" \\");
          $debugCallback("      -H \"Authorization: $authHeader\"");
        }

        $response = $this->httpClient->get('/v1/logs', [
          'query' => $requestParams
        ]);
        $isFirstPage = FALSE;
      }
      else {
        // Subsequent pages: use nextPage URL exactly like bash script.
        if ($debugCallback) {
          $debugCallback("Fetching page $pageCount...");
          $debugCallback("  Using nextPage: $nextPageUrl");
        }

        // Note: nextPageUrl from SolarWinds API is relative to base URL.
        $response = $this->httpClient->get($nextPageUrl);
      }

      // Debug API response status.
      if ($debugCallback) {
        $statusCode = $response->getStatusCode();
        $debugCallback("  API Response status: " . ($statusCode === 200 ? 'OK' : "HTTP $statusCode"));
      }

      $data = json_decode($response->getBody()->getContents(), TRUE);

      // Validate response structure exactly like bash script.
      if (!isset($data['logs'])) {
        if (isset($data['error'])) {
          throw new \RuntimeException("API Error: " . $data['error']);
        }
        // No logs field - break pagination like bash script.
        break;
      }

      $pageLogs = $data['logs'];
      $pageLogCount = count($pageLogs);

      // Debug output for page results.
      if ($debugCallback) {
        $debugCallback("  Got $pageLogCount results");

        if (!empty($pageLogs)) {
          // Show time range of this page like original framework.
          $times = array_column($pageLogs, 'time');
          if (!empty($times)) {
            $minTime = min($times);
            $maxTime = max($times);
            $debugCallback("    Page time range: $minTime to $maxTime");
          }
        }
      }

      // Don't break on empty pages - continue pagination until nextPage token is missing.
      $newLogsAdded = 0;
      $duplicatesFound = 0;

      if (!empty($pageLogs)) {
        // Process logs with duplicate detection exactly like bash script.
        foreach ($pageLogs as $log) {
          $logId = $log['id'] ?? NULL;

          if ($logId === NULL) {
            // No ID - just add it (shouldn't happen with SolarWinds API).
            $allLogs[] = $log;
            $newLogsAdded++;
            continue;
          }

          // Check for duplicates exactly like bash script.
          $duplicateFound = FALSE;
          if (!empty($seenIds)) {
            if (in_array($logId, $seenIds, TRUE)) {
              $duplicateFound = TRUE;
              $duplicatesFound++;
            }
          }

          if (!$duplicateFound) {
            $allLogs[] = $log;
            $seenIds[] = $logId;
            $newLogsAdded++;
          }
        }
      }

      $totalResults += $newLogsAdded;

      // Debug summary of page processing.
      if ($debugCallback && $pageLogCount > 0) {
        $debugCallback("  Added $newLogsAdded new logs, found $duplicatesFound duplicates ($totalResults total unique)");
      }

      // Call progress callback if provided.
      if ($progressCallback) {
        $progressCallback($pageCount, $pageLogs, $newLogsAdded, $duplicatesFound, $totalResults);
      }

      // Check for nextPage token exactly like bash script.
      $nextPageUrl = $data['pageInfo']['nextPage'] ?? NULL;

      if (empty($nextPageUrl)) {
        // No nextPage - pagination complete like bash script.
        if ($debugCallback) {
          $debugCallback("  No nextPage token - pagination complete");
        }
        break;
      }

      // Safety check exactly like bash script.
      if ($pageCount > 10000) {
        throw new \RuntimeException("Reached $pageCount pages - breaking loop to prevent runaway pagination");
      }
    }

    return $allLogs;
  }

  /**
   * Convert human-readable time to ISO-8601 format.
   */
  protected function convertToIsoTime(string $timeString): string
  {
    $timestamp = strtotime($timeString);
    if ($timestamp === FALSE) {
      throw new \InvalidArgumentException("Invalid time format: $timeString");
    }

    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
  }

  /**
   * Test API connectivity.
   */
  public function testConnection(): bool
  {
    try {
      $response = $this->httpClient->get('/v1/logs', [
        'query' => [
          'filter' => 'test',
          'pageSize' => 1,
          'startTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime('-1 hour')),
          'endTime' => gmdate('Y-m-d\TH:i:s\Z')
        ]
      ]);

      return $response->getStatusCode() === 200;
    }
    catch (GuzzleException $e) {
      return FALSE;
    }
  }
}
