<?php

/**
 * @file DisplayService.php
 * @brief Result formatting and display service with color coding and grouping capabilities
 *
 * @class DisplayService
 * @brief Handles all result formatting, display options, and visual presentation
 *
 * This service provides comprehensive result formatting capabilities, including color-coded
 * output, intelligent grouping, and multiple display formats. It consolidates the formatting
 *
 * @section display_features Display Features
 *
 * **Color Coding:**
 * - HTTP status codes: Green (2xx), Yellow (3xx), Orange (4xx), Red (5xx)
 * - Host names: Color-coded based on site configuration
 * - Bot highlighting: Automatic detection and highlighting of bot-related terms
 * - Respects NO_COLOR environment variable for accessibility
 *
 * **Grouping and Formatting:**
 * - Intelligent result grouping by specified dimensions
 * - Automatic regrouping when display options change
 * - Configurable minimum count thresholds
 * - Path truncation with configurable segment limits
 *
 * **Multiple Display Formats:**
 * - Simple field extraction (country, IP, etc.)
 * - Host-based grouping with path details
 * - Status code analysis with color coding
 * - User agent analysis with bot highlighting
 * - Time-based grouping (hourly breakdown)
 *
 * @section display_options Display Options
 *
 * The service supports these display dimensions:
 * - --status: HTTP status codes with color coding
 * - --host: Originating hosts with site-based coloring
 * - --path[=N]: Request paths (optionally truncated to N segments)
 * - --ua: User agents with bot term highlighting
 * - --ip: IP addresses
 * - --country: Geographic country information
 * - --region: Geographic region information
 * - --cache: Cache status information
 * - --by-hour: Hourly time-based grouping
 *
 * @section format_resolution Format Resolution
 *
 * The service automatically resolves the best display format based on:
 * 1. Explicit display options specified by user
 * 2. Command-specific default display preferences
 * 3. Fallback to appropriate format for result type
 * 4. Automatic regrouping when complex combinations requested
 *
 * @section example Usage Example
 * @code{.php}
 * $displayService = new DisplayService($configService);
 *
 * $displayService->formatAndDisplay(
 *     $results,
 *     'host-status-recent',
 *     1,  // minimum count
 *     2,  // path segments
 *     'search-term-to-highlight'
 * );
 * @endcode
 *
 * @see ConfigurationService For site-based color mappings
 * @see BaseSolarWindsCommand For display option parsing
 * @see ApiService For result data structure
 *
 * @note Color output automatically disabled when NO_COLOR environment variable is set
 * @warning Large result sets may require increased minimum count thresholds for readability
 */

namespace SolarWinds\Services;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Display Service
 *
 * Handles formatting and displaying log results based on display options.
 */
class DisplayService
{
  public function __construct(protected ConfigurationService $config)
  {
  }

  /**
   * Colorize unknown/missing data values in red for consistent styling.
   */
  protected function colorizeUnknownValue(string $value): string
  {
    $unknownValues = ['unknown', 'Unknown', 'NO REGION', 'null', 'NULL', '', '?'];

    if (in_array($value, $unknownValues, TRUE) || trim($value) === '') {
      return "\033[31m" . ($value ?: 'Unknown') . "\033[0m"; // Red color with reset
    }

    return $value;
  }

  /**
   * Get all display column headers mapping.
   */
  protected function getDisplayColumnHeaders(): array
  {
    return [
      'host' => 'Host',
      'status' => 'Status',
      'path' => 'Path',
      'ip' => 'IP',
      'ua' => 'User Agent',
      'country' => 'Country',
      'region' => 'Region',
    ];
  }

  /**
   * Get list of enabled display columns from display options.
   */
  protected function getEnabledDisplayColumns(array $displayOptions): array
  {
    $columns = [];

    foreach (array_keys($this->getDisplayColumnHeaders()) as $column) {
      if (!empty($displayOptions[$column])) {
        $columns[] = $column;
      }
    }

    return $columns;
  }

  /**
   * Get display header name for a column.
   */
  protected function getDisplayColumnHeader(string $column): string
  {
    return $this->getDisplayColumnHeaders()[$column];
  }

  /**
   * Extract and format display column value from log entry.
   */
  protected function extractDisplayColumnValue(string $column, array $log, array $displayOptions, ?string $searchTerm = NULL): string
  {
    switch ($column) {
      case 'host':
        $host = $log['site'] ?? $log['orig_host'] ?? $log['hostname'] ?? $log['host'] ?? 'unknown';
        return $this->shortenHostname($host);

      case 'status':
        $status = $log['resp_status'] ?? $log['status'] ?? $log['response_status'] ?? 'unknown';
        return $this->colorizeStatus($status);

      case 'path':
        $uri = $log['req_uri'] ?? $log['uri'] ?? $log['request_uri'] ?? $log['path'] ?? '/';
        $segments = (int) $displayOptions['path'];
        if ($segments > 1) {
          $pathParts = array_slice(explode('/', trim($uri, '/')), 0, $segments);
          $uri = '/' . implode('/', $pathParts);
        }
        if (strlen($uri) > 100) {
          $uri = substr($uri, 0, 97) . '...';
        }
        return $uri;

      case 'ip':
        $ip = $log['client_ip'] ?? $log['remote_addr'] ?? $log['ip'] ?? $log['remote_ip'] ?? 'unknown';
        return $this->colorizeUnknownValue($ip);

      case 'ua':
        $ua = $log['req_user_agent'] ?? $log['user_agent'] ?? $log['useragent'] ?? 'unknown';
        if (strlen($ua) > 100) {
          $ua = substr($ua, 0, 97) . '...';
        }
        return $this->highlightSearchTermInUserAgent($ua, $searchTerm);

      case 'country':
        $country = $log['geoip']['country_code2'] ??
          $log['geoip']['country_name'] ??
          $log['country'] ??
          $log['geo']['country'] ??
          'unknown';
        return $this->colorizeUnknownValue($country);

      case 'region':
        $region = $log['geoip']['region_name'] ??
          $log['region'] ??
          $log['geo']['region'] ??
          'unknown';
        return $this->colorizeUnknownValue($region);

      default:
        return 'unknown';
    }
  }
  /**
   * Display results based on display options.
   */
  public function displayResults(array $logs, array $displayOptions, SymfonyStyle $io, bool $debugMode = FALSE, array $filters = [], ?string $searchTerm = NULL): void
  {
    if (empty($logs)) {
      $io->warning('No results found.');
      return;
    }

    // Debug: show first log structure if country option is enabled and debug mode is on.
    if ($debugMode && !empty($displayOptions['country']) && !empty($logs)) {
      $firstLog = $this->parseLogMessage($logs[0]);
      $io->section('DEBUG: First log structure (looking for country fields)');
      $io->text('Available top-level fields: ' . implode(', ', array_keys($firstLog)));

      // Check for geoip-related fields.
      if (isset($firstLog['geoip'])) {
        $io->text('geoip fields: ' . implode(', ', array_keys($firstLog['geoip'])));
      }
      else {
        $io->text('No geoip field found');
      }

      if (isset($firstLog['geo'])) {
        $io->text('geo fields: ' . implode(', ', array_keys($firstLog['geo'])));
      }
      else {
        $io->text('No geo field found');
      }

      // Show any fields that might contain location data.
      $locationFields = array_filter(array_keys($firstLog), function($key) {
        return stripos($key, 'geo') !== FALSE ||
               stripos($key, 'country') !== FALSE ||
               stripos($key, 'location') !== FALSE ||
               stripos($key, 'ip') !== FALSE;
      });

      if ($locationFields) {
        $io->text('Potential location-related fields: ' . implode(', ', $locationFields));
      }

      $io->newLine();
    }

    // If no specific display options are set, show raw JSON.
    if (empty(array_filter($displayOptions))) {
      $this->displayRawJson($logs, $io);
      return;
    }

    // Special handling for Drupal/PHP error format.
    if (!empty($displayOptions['drupal'])) {
      $this->displayDrupalErrors($logs, $displayOptions, $io, $filters, $searchTerm);
      return;
    }

    // Group and format based on display options.
    $grouped = $this->groupResults($logs, $displayOptions);

    // Smart auto-regrouping: if only one group, regroup by time buckets (unless disabled).
    if (count($grouped) === 1 && !$filters['no_group']) {
      $groupKey = array_keys($grouped)[0];
      $groupData = array_values($grouped)[0];
      $this->handleAutoTimeRegrouping($logs, $displayOptions, $io, $groupData, $groupKey, $filters, $searchTerm);
      return;
    }

    $this->displayGroupedResults($grouped, $displayOptions, $io, $filters, $searchTerm);
  }

  /**
   * Check if we're getting unknown fields for the requested display options.
   */
  protected function hasUnknownFields(array $log, array $displayOptions): bool
  {
    if (!empty($displayOptions['host'])) {
      $host = $log['orig_host'] ?? $log['host'] ?? $log['hostname'] ?? NULL;
      if ($host === NULL) return TRUE;
    }

    if (!empty($displayOptions['status'])) {
      $status = $log['resp_status'] ?? $log['status'] ?? $log['response_status'] ?? NULL;
      if ($status === NULL) return TRUE;
    }

    if (!empty($displayOptions['path'])) {
      $path = $log['req_uri'] ?? $log['uri'] ?? $log['request_uri'] ?? $log['path'] ?? NULL;
      if ($path === NULL) return TRUE;
    }

    return FALSE;
  }

  /**
   * Display raw JSON output.
   */
  protected function displayRawJson(array $logs, SymfonyStyle $io): void
  {
    foreach ($logs as $log) {
      $io->writeln(json_encode($log, JSON_PRETTY_PRINT));
    }
  }

  /**
   * Group results based on display options.
   */
  protected function groupResults(array $logs, array $displayOptions): array
  {
    $grouped = [];

    foreach ($logs as $log) {
      // Parse the actual log data from the message field if it's JSON.
      $parsedLog = $this->parseLogMessage($log);

      $key = $this->buildGroupingKey($parsedLog, $displayOptions);

      $grouped[$key] ??= [
        'count' => 0,
        'first_seen' => $log['time'] ?? 'unknown',
        'last_seen' => $log['time'] ?? 'unknown',
        'sample' => $parsedLog
      ];

      $grouped[$key]['count']++;
      $grouped[$key]['last_seen'] = $log['time'] ?? 'unknown';
    }

    // Sort by count (descending).
    uasort($grouped, function($a, $b) {
      return $b['count'] <=> $a['count'];
    });

    return $grouped;
  }

  /**
   * Parse the log message field if it contains JSON data.
   */
  protected function parseLogMessage(array $log): array
  {
    // If there's a message field with JSON data, parse it.
    if (isset($log['message']) && is_string($log['message'])) {
      $messageData = json_decode($log['message'], TRUE);
      if (json_last_error() === JSON_ERROR_NONE && is_array($messageData)) {
        // Merge the outer log data with the parsed message data.
        // The message data takes precedence for conflicts.
        return array_merge($log, $messageData);
      }
    }

    return $log;
  }

  /**
   * Build grouping key based on display options.
   */
  protected function buildGroupingKey(array $log, array $displayOptions): string
  {
    $keyParts = [];

    if (!empty($displayOptions['host'])) {
      // Use site field first, then fall back to orig_host, hostname, or host.
      $host = $log['site'] ?? $log['orig_host'] ?? $log['hostname'] ?? $log['host'] ?? 'unknown';
      // Get the display label for this host so multiple hostnames (e.g., bayren.org, bayren2) group together.
      $displayHost = $this->getDisplayLabelForHost($host);
      $keyParts[] = 'host:' . $displayHost;
    }

    if (!empty($displayOptions['status'])) {
      // Use resp_status from parsed message data.
      $status = $log['resp_status'] ?? $log['status'] ?? $log['response_status'] ?? 'unknown';
      $keyParts[] = 'status:' . $status;
    }

    if (!empty($displayOptions['path'])) {
      // Use req_uri from parsed message data.
      $uri = $log['req_uri'] ?? $log['uri'] ?? $log['request_uri'] ?? $log['path'] ?? '/';
      $segments = (int) $displayOptions['path'];
      if ($segments > 1) {
        $pathParts = array_slice(explode('/', trim($uri, '/')), 0, $segments);
        $uri = '/' . implode('/', $pathParts);
      }

      // Truncate long paths at 100 characters for grouping.
      if (strlen($uri) > 100) {
        $uri = substr($uri, 0, 97) . '...';
      }

      $keyParts[] = 'path:' . $uri;
    }

    if (!empty($displayOptions['ip'])) {
      // Use client_ip from parsed message data.
      $ip = $log['client_ip'] ?? $log['remote_addr'] ?? $log['ip'] ?? $log['remote_ip'] ?? 'unknown';
      $keyParts[] = 'ip:' . $ip;
    }

    if (!empty($displayOptions['ua'])) {
      // Use req_user_agent from parsed message data.
      $ua = $log['req_user_agent'] ?? $log['user_agent'] ?? $log['useragent'] ?? 'unknown';
      // Truncate long user agents at 100 characters for grouping.
      if (strlen($ua) > 100) {
        $ua = substr($ua, 0, 97) . '...';
      }
      $keyParts[] = 'ua:' . $ua;
    }

    if (!empty($displayOptions['country'])) {
      // Use the actual SolarWinds geoip field structure based on debug output.
      $country = $log['geoip']['country_code2'] ??
        $log['geoip']['country_name'] ?? // Fallback in case some logs have full names.
        $log['country'] ??
        $log['geo']['country'] ??
        'unknown';
      $keyParts[] = 'country:' . $country;
    }

    if (!empty($displayOptions['region'])) {
      // Use the actual SolarWinds geoip field structure based on debug output.
      $region = $log['geoip']['region_name'] ??
        $log['region'] ??
        $log['geo']['region'] ??
        'unknown';
      $keyParts[] = 'region:' . $region;
    }

    return empty($keyParts) ? 'all' : implode('|', $keyParts);
  }

  /**
   * Display grouped results in a table format.
   */
  protected function displayGroupedResults(array $grouped, array $displayOptions, SymfonyStyle $io, array $filters = [], ?string $searchTerm = NULL): void
  {
    $headers = ['Count'];
    $enabledColumns = $this->getEnabledDisplayColumns($displayOptions);

    // Add headers for enabled columns.
    foreach ($enabledColumns as $column) {
      $headers[] = $this->getDisplayColumnHeader($column);
    }

    // Add combined timestamp column at the end.
    $headers[] = 'Time Range';

    $rows = [];
    $minCount = $filters['min_count'] ?? 1;

    foreach ($grouped as $key => $data) {
      // Apply min_count filter.
      if ($data['count'] < $minCount) {
        continue;
      }

      $row = [$data['count']];
      $log = $data['sample'];

      // Add values for enabled columns.
      foreach ($enabledColumns as $column) {
        $row[] = $this->extractDisplayColumnValue($column, $log, $displayOptions, $searchTerm);
      }

      // Add combined timestamp column at the end.
      $row[] = $this->formatTimeRange($data['first_seen'], $data['last_seen']);

      $rows[] = $row;
    }

    // Create table without restrictions - let it wrap naturally.
    $io->table($headers, $rows);
  }

  /**
   * Handle automatic time-based regrouping when only one group is found.
   */
  protected function handleAutoTimeRegrouping(array $logs, array $displayOptions, SymfonyStyle $io, array $singleGroup, string $groupKey = 'all', array $filters = [], ?string $searchTerm = NULL): void
  {
    // Determine time range from logs.
    $timestamps = [];
    foreach ($logs as $log) {
      $timestamp = $log['time'] ?? $log['timestamp'] ?? $log['solarwinds_time'] ?? '';
      if (!empty($timestamp)) {
        $timestamps[] = strtotime($timestamp);
      }
    }

    if (empty($timestamps)) {
      // No timestamps found, display normal grouped results.
      $this->displayGroupedResults([$groupKey => $singleGroup], $displayOptions, $io, $filters, $searchTerm);
      return;
    }

    sort($timestamps);
    $timeSpan = end($timestamps) - $timestamps[0];
    $isLessThanHour = $timeSpan < 3600;  // 1 hour in seconds
    $isLessThanDay = $timeSpan < 86400;  // 24 hours in seconds

    $groupDescription = $groupKey === 'all' ? 'all data' : str_replace('|', ', ', $groupKey);

    // Determine bucket type and inform user with original group details.
    if ($isLessThanHour) {
      $bucketType = 'minute';
      $io->note("Single result group detected: $groupDescription ({$singleGroup['count']} results). Auto-regrouping by minute for time range analysis. Use --no-group to see original results.");
    }
    elseif ($isLessThanDay) {
      $bucketType = 'hour';
      $io->note("Single result group detected: $groupDescription ({$singleGroup['count']} results). Auto-regrouping by hour for time range analysis. Use --no-group to see original results.");
    }
    else {
      $bucketType = 'day';
      $io->note("Single result group detected: $groupDescription ({$singleGroup['count']} results). Auto-regrouping by day for time range analysis. Use --no-group to see original results.");
    }

    // Regroup by time buckets.
    $timeBuckets = [];
    foreach ($logs as $log) {
      $timestamp = $log['time'] ?? $log['timestamp'] ?? $log['solarwinds_time'] ?? '';
      if ($bucketType === 'minute') {
        $bucket = $this->extractMinuteFromTimestamp($timestamp);
      }
      elseif ($bucketType === 'hour') {
        $bucket = $this->extractHourFromTimestamp($timestamp);
      }
      else {
        $bucket = $this->extractDayFromTimestamp($timestamp);
      }

      if (!isset($timeBuckets[$bucket])) {
        $timeBuckets[$bucket] = 0;
      }
      $timeBuckets[$bucket]++;
    }

    // Sort buckets.
    ksort($timeBuckets);

    // Display time bucket results.
    $rows = [];
    foreach ($timeBuckets as $bucket => $count) {
      $rows[] = [$count, $bucket];
    }

    $bucketLabel = $bucketType === 'minute' ? 'Time (HH:MM)' : ($bucketType === 'hour' ? 'Hour' : 'Day');
    $io->table(['Count', $bucketLabel], $rows);
  }

  /**
   * Extract day from timestamp in YYYY-MM-DD format.
   */
  protected function extractDayFromTimestamp(string $timestamp): string
  {
    if (empty($timestamp)) {
      return 'unknown';
    }

    // Handle ISO format timestamps (2024-01-01T15:30:45Z).
    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $timestamp, $matches)) {
      return $matches[1];
    }

    // Try to parse with DateTime.
    try {
      $dt = new \DateTime($timestamp);
      return $dt->format('Y-m-d');
    }
    catch (\Exception $e) {
      return 'unknown';
    }
  }

  /**
   * Extract hour from timestamp in HH:00 format.
   */
  protected function extractHourFromTimestamp(string $timestamp): string
  {
    if (empty($timestamp)) {
      return 'unknown';
    }

    // Handle ISO format timestamps (2024-01-01T15:30:45Z).
    if (preg_match('/T(\d{2}):\d{2}:\d{2}/', $timestamp, $matches)) {
      return $matches[1] . ':00';
    }

    // Handle other common formats.
    if (preg_match('/(\d{2}):\d{2}:\d{2}/', $timestamp, $matches)) {
      return $matches[1] . ':00';
    }

    return 'unknown';
  }

  /**
   * Extract minute from timestamp in HH:MM format.
   */
  protected function extractMinuteFromTimestamp(string $timestamp): string
  {
    if (empty($timestamp)) {
      return 'unknown';
    }

    // Handle ISO format timestamps (2024-01-01T15:30:45Z).
    if (preg_match('/T(\d{2}):(\d{2}):\d{2}/', $timestamp, $matches)) {
      return $matches[1] . ':' . $matches[2];
    }

    // Handle other common formats.
    if (preg_match('/(\d{2}):(\d{2}):\d{2}/', $timestamp, $matches)) {
      return $matches[1] . ':' . $matches[2];
    }

    return 'unknown';
  }

  /**
   * Get display label for a hostname (for grouping purposes).
   */
  protected function getDisplayLabelForHost(string $host): string
  {
    // Handle unknown hosts first.
    if ($host === 'unknown' || trim($host) === '') {
      return 'unknown';
    }

    $cleanHost = preg_replace('/^www\./', '', $host);

    // Get host mappings from configuration.
    $hostMappings = $this->config->getHostDisplayMappings();

    // If we have an exact match, return the display label.
    if (isset($hostMappings[$cleanHost])) {
      return $hostMappings[$cleanHost];
    }

    // No match - return original hostname.
    return $host;
  }

  /**
   * Shorten hostname using configured display mappings and colorize.
   */
  protected function shortenHostname(string $host): string
  {
    // Handle unknown hosts first.
    if ($host === 'unknown' || trim($host) === '') {
      return $this->colorizeUnknownValue($host);
    }

    $cleanHost = preg_replace('/^www\./', '', $host);

    // Get host mappings from configuration.
    $hostMappings = $this->config->getHostDisplayMappings();

    // If we have an exact match, return it in green.
    if (isset($hostMappings[$cleanHost])) {
      return "<fg=green>" . $hostMappings[$cleanHost] . "</fg=green>";
    }

    // No match - return original hostname.
    return $host;
  }

  /**
   * Colorize HTTP status codes based on response type.
   */
  protected function colorizeStatus(string $status): string
  {
    if ($status === 'unknown') {
      return $this->colorizeUnknownValue($status);
    }

    $statusCode = (int) $status;

    // 2xx = green, 3xx = yellow, 4xx = bright red (orange), 5xx = red
    if ($statusCode >= 200 && $statusCode < 300) {
      return "<fg=green>$status</fg=green>";           // 2xx - green
    } elseif ($statusCode >= 300 && $statusCode < 400) {
      return "<fg=yellow>$status</fg=yellow>";         // 3xx - yellow
    } elseif ($statusCode >= 400 && $statusCode < 500) {
      return "<fg=bright-red>$status</fg=bright-red>"; // 4xx - bright red (orange effect)
    } elseif ($statusCode >= 500) {
      return "<fg=red>$status</fg=red>";               // 5xx - red
    }

    return $status;
  }

  /**
   * Highlight search term in user agent strings.
   */
  protected function highlightSearchTermInUserAgent(string $ua, ?string $searchTerm = NULL): string
  {
    // Handle unknown user agents first.
    if ($ua === 'unknown' || trim($ua) === '') {
      return $this->colorizeUnknownValue($ua);
    }

    // Default to "bot" if no search term provided.
    if (empty($searchTerm)) {
      $searchTerm = 'bot';
    }

    // Case-insensitive highlight of search term anywhere in user agent with green color.
    // Use preg_quote to escape special regex characters in the search term.
    // Remove word boundaries to allow partial word matches (e.g., "claude" matches "ClaudeBot").
    $escapedTerm = preg_quote($searchTerm, '/');
    return preg_replace('/(' . $escapedTerm . ')/i', '<fg=green>$1</fg=green>', $ua);
  }

  /**
   * Format timestamp for display using compact m-d H:i:s format.
   */
  protected function formatTimestamp(string $timestamp): string
  {
    try {
      $dt = new \DateTime($timestamp);
      return $dt->format('m-d H:i:s');
    }
    catch (\Exception $e) {
      return $timestamp;
    }
  }

  /**
   * Format time range for display (combines first and last seen).
   */
  protected function formatTimeRange(string $firstSeen, string $lastSeen): string
  {
    $first = $this->formatTimestamp($firstSeen);
    $last = $this->formatTimestamp($lastSeen);

    // If they're the same, just show one timestamp.
    if ($first === $last) {
      return $first;
    }

    // If they're on the same day, show "m-d H:i:s - H:i:s".
    try {
      $firstDt = new \DateTime($firstSeen);
      $lastDt = new \DateTime($lastSeen);

      if ($firstDt->format('m-d') === $lastDt->format('m-d')) {
        return $firstDt->format('m-d H:i:s') . ' - ' . $lastDt->format('H:i:s');
      }
    }
    catch (\Exception $e) {
      // Fall through to default format.
    }

    // Different days, show full range.
    return "$first - $last";
  }

  /**
   * Display Drupal/PHP watchdog errors with file:line grouping.
   */
  protected function displayDrupalErrors(array $logs, array $displayOptions, SymfonyStyle $io, array $filters = [], ?string $searchTerm = NULL): void
  {
    $grouped = [];
    $minCount = $filters['min_count'] ?? 1;
    $varsOption = $displayOptions['vars'] ?? FALSE;
    $shouldTruncate = ($varsOption === FALSE);

    // Debug vars option
    $debugMode = $filters['debug'] ?? FALSE;
    if ($debugMode) {
      $io->text("DEBUG: vars option = " . var_export($varsOption, TRUE));
    }

    foreach ($logs as $log) {
      // Parse the log message to get the JSON structure.
      $parsedLog = $this->parseLogMessage($log);

      // Extract Drupal watchdog specific fields.
      $severity = $parsedLog['severity'] ?? 'Unknown';
      $type = $parsedLog['type'] ?? 'Unknown';
      $variables = $parsedLog['variables'] ?? [];

      // Extract message - handle both @message and direct message.
      $message = $variables['@message'] ?? $parsedLog['message'] ?? 'No message';

      // Handle cases where message field contains nested JSON (e.g., "access denied" logs).
      if (is_string($message) && strlen($message) > 0 && ($message[0] === '{' || $message[0] === '[')) {
        $nestedData = json_decode($message, TRUE);
        if (json_last_error() === JSON_ERROR_NONE && is_array($nestedData)) {
          // Successfully parsed nested JSON - extract or construct the actual message.
          if (isset($nestedData['message'])) {
            $message = $nestedData['message'];
          }
          elseif (isset($nestedData['msg'])) {
            $message = $nestedData['msg'];
          }
          elseif (isset($nestedData['type'])) {
            // No message field - use type as message (capitalize appropriately).
            $message = ucwords(str_replace('_', ' ', $nestedData['type']));
          }
          else {
            // Last resort: generic message.
            $message = 'Log entry';
          }
        }
      }

      // Create grouping key based on type and message.
      // If --host is specified, include host in grouping key so each site is grouped separately.
      $key = "$type:" . md5($message);

      if (!empty($displayOptions['host'])) {
        $host = $parsedLog['site'] ?? $parsedLog['orig_host'] ?? $parsedLog['hostname'] ?? $parsedLog['host'] ?? 'unknown';
        $displayHost = $this->getDisplayLabelForHost($host);
        $key .= ":host:$displayHost";
      }

      if (!isset($grouped[$key])) {
        $grouped[$key] = [
          'count' => 0,
          'severity' => $severity,
          'type' => $type,
          'message' => $message,
          'variables' => $variables,
          'first_seen' => $log['time'] ?? 'unknown',
          'last_seen' => $log['time'] ?? 'unknown',
          'sample' => $parsedLog,
        ];
      }

      $grouped[$key]['count']++;
      $grouped[$key]['last_seen'] = $log['time'] ?? 'unknown';
    }

    // Sort by count (descending).
    uasort($grouped, function($a, $b) {
      return $b['count'] <=> $a['count'];
    });

    // Build table headers dynamically based on vars option.
    $headers = ['Count', 'Severity', 'Type', 'Message'];

    // Determine which variable columns to show.
    $varColumns = [];
    if ($varsOption === TRUE) {
      // --vars with no value: show all variables in one "Variables" column.
      $headers[] = 'Variables';
    }
    elseif (is_array($varsOption)) {
      // --vars=file,line,function: show specific variables as separate columns.
      foreach ($varsOption as $varName) {
        // Strip % and @ prefixes and capitalize.
        $cleanName = ltrim($varName, '%@');
        $headerName = ucfirst($cleanName);
        $headers[] = $headerName;
        $varColumns[] = $varName;
      }
    }

    // Add optional display column headers (--host, --ip, etc.).
    $enabledColumns = $this->getEnabledDisplayColumns($displayOptions);
    foreach ($enabledColumns as $column) {
      $headers[] = $this->getDisplayColumnHeader($column);
    }

    // Time Range always comes last.
    $headers[] = 'Time Range';

    $rows = [];

    foreach ($grouped as $key => $data) {
      // Apply min_count filter.
      if ($data['count'] < $minCount) {
        continue;
      }

      // Colorize severity like status codes.
      $severityDisplay = $this->colorizeDrupalSeverity($data['severity']);

      // Truncate long messages only if vars not specified.
      $message = $data['message'];
      if ($shouldTruncate && strlen($message) > 80) {
        $message = substr($message, 0, 77) . '...';
      }

      $row = [
        $data['count'],
        $severityDisplay,
        $data['type'],
        $message,
      ];

      // Add variable columns.
      if ($varsOption === TRUE) {
        // Show all variables in one column (newline-separated).
        $allVars = [];
        if (!empty($data['variables'])) {
          foreach ($data['variables'] as $varKey => $varValue) {
            // Skip @message since it's already displayed in the Message column
            if ($varKey === '@message') {
              continue;
            }
            $cleanKey = ltrim($varKey, '%@');
            $allVars[] = "$cleanKey: $varValue";
          }
        }
        // If no variables found, show "-"
        $row[] = !empty($allVars) ? implode("\n", $allVars) : '-';
      }
      elseif (is_array($varsOption)) {
        // Show specific variables as separate columns.
        foreach ($varColumns as $varName) {
          // Try both % and @ prefixes.
          $value = $data['variables']['%' . ltrim($varName, '%@')] ??
                   $data['variables']['@' . ltrim($varName, '%@')] ??
                   $data['variables'][$varName] ??
                   '-';
          $row[] = $value;
        }
      }

      // Add optional display column values (--host, --ip, etc.).
      $log = $data['sample'];
      foreach ($enabledColumns as $column) {
        $row[] = $this->extractDisplayColumnValue($column, $log, $displayOptions, $searchTerm);
      }

      // Time Range always comes last.
      $row[] = $this->formatTimeRange($data['first_seen'], $data['last_seen']);

      $rows[] = $row;
    }

    if (empty($rows)) {
      $io->warning('No Drupal errors found matching criteria.');
      return;
    }

    $io->table($headers, $rows);
  }

  /**
   * Colorize Drupal severity levels.
   */
  protected function colorizeDrupalSeverity(string $severity): string
  {
    // Drupal severity levels: Emergency, Alert, Critical, Error, Warning, Notice, Info, Debug.
    $lower = strtolower($severity);

    if (in_array($lower, ['emergency', 'alert', 'critical'])) {
      return "<fg=red>$severity</fg=red>";           // Red for critical issues.
    }
    elseif ($lower === 'error') {
      return "<fg=bright-red>$severity</fg=bright-red>"; // Bright red for errors.
    }
    elseif ($lower === 'warning') {
      return "<fg=yellow>$severity</fg=yellow>";     // Yellow for warnings.
    }
    elseif (in_array($lower, ['notice', 'info'])) {
      return "<fg=cyan>$severity</fg=cyan>";         // Cyan for informational.
    }
    elseif ($lower === 'debug') {
      return "<fg=gray>$severity</fg=gray>";         // Gray for debug.
    }

    return $severity;
  }

}
