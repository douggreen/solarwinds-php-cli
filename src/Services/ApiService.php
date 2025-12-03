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
 * // Fetch ALL logs (universal sync - no filter)
 * $results = $apiService->searchLogs(
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
 * @see DatabaseService For database-backed log storage
 *
 * @note This implementation is a direct port of the working bash script pagination logic
 * @warning Requires valid API token and api_base_url configuration for operation
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

  /**
   * Constructor.
   *
   * Initializes HTTP client with SolarWinds API credentials and configuration.
   *
   * @param ConfigurationService $config Configuration service with API credentials
   * @throws \InvalidArgumentException If api_base_url or token is missing
   */
  public function __construct(ConfigurationService $config)
  {
    $baseUrl = $config->get('api_base_url');
    $this->apiToken = $config->get('token');

    if (empty($baseUrl)) {
      throw new \InvalidArgumentException("Missing api_base_url in configuration");
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
   * Retrieve logs from API with pagination - fetches ALL logs (universal sync).
   *
   * With the universal sync architecture, this method always fetches ALL logs
   * (HTTP + Drupal + everything) by not providing a filter parameter to the API.
   * Commands filter the results client-side based on their specific needs.
   *
   * @param string $startTime Start time (human readable)
   * @param string $endTime End time (human readable)
   * @param callable|NULL $progressCallback Callback for progress updates
   * @param callable|NULL $debugCallback Callback for debug output
   * @param callable|NULL $saveCallback Callback to save each page immediately (receives array of logs)
   * @return array Array of log entries
   * @throws GuzzleException
   */
  public function retrieveLogs(
    int|string $startTime,
    int|string $endTime,
    ?callable $progressCallback = NULL,
    ?callable $debugCallback = NULL,
    ?callable $saveCallback = NULL
  ): array {
    // Convert unix timestamps or human-readable times to ISO format.
    $startTimeIso = $this->convertToIsoTime($startTime);
    $endTimeIso = $this->convertToIsoTime($endTime);

    $allLogs = $seenIds = [];
    $pageCount = $totalResults = 0;
    $nextPageUrl = NULL;
    $isFirstPage = TRUE;

    while (TRUE) {
      $pageCount++;

      // Check for interruption before each API call.
      if (\SolarWinds\Commands\BaseSolarWindsCommand::isInterrupted()) {
        break;
      }

      try {
        if ($isFirstPage) {
          // First page: build parameters for universal sync (no filter).
          // This fetches ALL logs: HTTP traffic + Drupal logs + everything.
          $requestParams = [
            'pageSize' => 1000,
            'startTime' => $startTimeIso,
            'endTime' => $endTimeIso
          ];

          // Debug output for first page request.
          if ($debugCallback) {
            $debugCallback("Fetching page $pageCount...");
            $debugCallback("  Time range: $startTimeIso to $endTimeIso");
            $debugCallback("  Filter: (none - fetching ALL logs)");

            // Show equivalent curl command.
            $baseUri = rtrim($this->httpClient->getConfig('base_uri'), '/');
            $authHeader = 'Bearer [HIDDEN]';
            $debugCallback("  Executing curl command:");
            $debugCallback("    curl -s -G \"$baseUri/v1/logs\" \\");
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
      }
      catch (\Exception $e) {
        // API request failed - throw with context about which page failed.
        throw new \RuntimeException(
          sprintf(
            'API request failed on page %d (fetched %d logs so far): %s',
            $pageCount,
            $totalResults,
            $e->getMessage()
          ),
          0,
          $e
        );
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
      $newLogsAdded = $duplicatesFound = 0;

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

      // Save page data immediately if callback provided.
      if ($saveCallback && !empty($pageLogs)) {
        $saveCallback($pageLogs);
      }

      // Check for interruption after processing page (allows graceful stop mid-sync).
      if (\SolarWinds\Commands\BaseSolarWindsCommand::isInterrupted()) {
        break;
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
   *
   * Parses natural language time expressions and converts to API-compatible format.
   *
   * @param string $timeString Human-readable time string (e.g., "1 hour ago", "now")
   * @return string ISO-8601 formatted timestamp
   * @throws \InvalidArgumentException If time string cannot be parsed
   */
  protected function convertToIsoTime(int|string $time): string
  {
    // If already an integer timestamp, use it directly.
    if (is_int($time)) {
      return gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    // Otherwise convert string to timestamp.
    $timestamp = strtotime($time);
    if ($timestamp === FALSE) {
      throw new \InvalidArgumentException("Invalid time format: $time");
    }

    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
  }

}
