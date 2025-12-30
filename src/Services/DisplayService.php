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
 * - Bot verification: Verified (green), Spoofed (red), Unknown (yellow)
 * - Respects NO_COLOR environment variable for accessibility
 *
 * **Grouping and Formatting:**
 * - Intelligent result grouping by specified dimensions
 * - Automatic regrouping when display options change
 * - Configurable minimum count thresholds
 * - Path truncation with configurable segment limits
 *
 * **Multiple Display Formats:**
 * - Simple field extraction (country, IP, bot name, etc.)
 * - Host-based grouping with path details
 * - Status code analysis with color coding
 * - User agent analysis with bot verification status
 * - Time-based grouping (hourly breakdown)
 *
 * @section display_options Display Options
 *
 * The service supports these display dimensions:
 * - --status: HTTP status codes with color coding
 * - --host: Originating hosts with site-based coloring
 * - --path[=N]: Request paths (optionally truncated to N segments)
 * - --ua: User agents
 * - --ip: IP addresses
 * - --bot_name: Bot name extracted from user agent
 * - --verified: Bot verification status (verified/spoofed/unverified)
 * - --country: Geographic country information
 * - --region: Geographic region information
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
  /**
   * Constructor.
   *
   * @param ConfigurationService $config Configuration service for site mappings
   */
  public function __construct(protected ConfigurationService $config)
  {
  }

  /**
   * Create a standardized progress bar.
   *
   * All progress bars should use this method to ensure consistent formatting
   * across the application (equals signs instead of blocks).
   *
   * @param SymfonyStyle $io Symfony console I/O helper
   * @param int $total Total number of items (0 for unknown)
   * @return \Symfony\Component\Console\Helper\ProgressBar Configured progress bar
   */
  public function createProgressBar(SymfonyStyle $io, int $total = 0): \Symfony\Component\Console\Helper\ProgressBar
  {
    $progressBar = $io->createProgressBar($total);
    // Custom format: "count/total [bar] percent / elapsed"
    $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% / %elapsed%');
    $progressBar->setBarCharacter('=');
    $progressBar->setEmptyBarCharacter('-');
    $progressBar->setProgressCharacter('>');
    return $progressBar;
  }

  /**
   * Finish a progress bar and add consistent newlines.
   *
   * @param \Symfony\Component\Console\Helper\ProgressBar $progressBar Progress bar to finish
   * @param SymfonyStyle $io Output interface for newlines
   * @return void
   */
  public function finishProgressBar(\Symfony\Component\Console\Helper\ProgressBar $progressBar, SymfonyStyle $io): void
  {
    $progressBar->finish();
    $io->newLine(2);
  }

  /**
   * Colorize unknown/missing data values in red for consistent styling.
   *
   * @param string $value Value to colorize if unknown
   * @return string Colorized value or original value
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
   * Substitute variable placeholders in message with their values.
   *
   * Replaces Drupal-style placeholders like %name, %choice, @message with
   * their actual values from the variables array.
   *
   * @param string $message The message template with placeholders
   * @param array $variables Associative array of variable name => value
   * @return string The message with substituted values
   */
  protected function substituteVariables(string $message, array $variables): string
  {
    if (empty($variables)) {
      return $message;
    }

    // Build replacement array for strtr().
    $replacements = [];
    foreach ($variables as $key => $value) {
      // Skip @message as it's the message template itself.
      if ($key === '@message') {
        continue;
      }

      // Convert value to string if it's not already.
      if (is_array($value)) {
        $value = json_encode($value);
      }
      elseif (!is_string($value)) {
        $value = (string) $value;
      }

      // Drupal variables come in different formats:
      // - Already prefixed: "%name", "@name"
      // - Unprefixed: "name"
      // Handle both cases.
      if ($key[0] === '%' || $key[0] === '@') {
        // Already prefixed - use as-is.
        $replacements[$key] = $value;
      }
      else {
        // Not prefixed - add both % and @ versions.
        $replacements["%$key"] = $value;
        $replacements["@$key"] = $value;
      }
    }

    return strtr($message, $replacements);
  }

  /**
   * Get all display column headers mapping.
   *
   * @return array Column name to header label mappings
   */
  protected function getDisplayColumnHeaders(): array
  {
    return [
      'host' => 'Host',
      'status' => 'Status',
      'path' => 'Path',
      'ip' => 'IP',
      'bot_name' => 'Bot Name',
      'verified' => 'Verified',
      'ua' => 'User Agent',
      'country' => 'Country',
      'region' => 'Region',
      'last_seen' => 'Last Seen',
    ];
  }

  /**
   * Get list of enabled display columns from display options.
   *
   * Respects user-specified column order from --cols option if available.
   *
   * @param array $displayOptions Display configuration array
   * @return array List of enabled column names
   */
  protected function getEnabledDisplayColumns(array $displayOptions): array
  {
    // If user specified column order via --cols, use that order.
    if (!empty($displayOptions['_order'])) {
      return $displayOptions['_order'];
    }

    // Otherwise, use default hardcoded order.
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
   *
   * @param string $column Column name
   * @return string Display header label
   */
  protected function getDisplayColumnHeader(string $column): string
  {
    return $this->getDisplayColumnHeaders()[$column];
  }

  /**
   * Extract and format display column value from log entry.
   *
   * @param string $column Column name to extract
   * @param array $log Log entry data
   * @param array $displayOptions Display configuration
   * @param string|null $searchTerm Optional search term for highlighting
   * @return string Formatted column value
   */
  protected function extractDisplayColumnValue(string $column, array $log, array $displayOptions, ?string $searchTerm = NULL): string
  {
    switch ($column) {
      case 'host':
        $host = $log['site'] ?? $log['orig_host'] ?? $log['host'] ?? 'unknown';
        return $this->shortenHostname($host);

      case 'status':
        $status = $log['resp_status'] ?? $log['status'] ?? $log['response_status'] ?? 'unknown';
        return $this->colorizeStatus($status);

      case 'path':
        $uri = $log['req_uri'] ?? $log['uri'] ?? $log['request_uri'] ?? $log['path'] ?? $log['location'] ?? '/';
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
        // Use STORED column (extracted during sync with fallback chain)
        $country = $log['country'] ?? 'unknown';
        return $this->colorizeUnknownValue($country);

      case 'region':
        // Use STORED column (extracted during sync with fallback chain)
        $region = $log['region'] ?? 'unknown';
        return $this->colorizeUnknownValue($region);

      case 'bot_name':
        return $log['bot_name'] ?? 'Unknown';

      case 'verified':
        $verified = $log['bot_verified'] ?? 'unknown';
        return $this->colorizeBotVerificationStatus($verified);

      case 'last_seen':
        $timestamp = $log['timestamp'] ?? $log['@timestamp'] ?? NULL;
        if ($timestamp) {
          return gmdate('m-d H:i:s', strtotime($timestamp));
        }
        return 'unknown';

      default:
        return 'unknown';
    }
  }
  /**
   * Format results for JSON output.
   *
   * Applies same grouping and filtering logic as displayResults() but returns
   * structured data instead of rendering to console.
   *
   * @param array $logs Raw log entries
   * @param array $displayOptions Display dimension options (host, status, etc.)
   * @param array $filters Filter options including min_count and no_group
   * @param string|null $searchTerm Optional search term for context
   * @return array Structured data ready for JSON encoding
   */
  public function formatResultsForJson(array $logs, array $displayOptions, array $filters = [], ?string $searchTerm = NULL): array
  {
    if (empty($logs)) {
      return [
        'items' => [],
        'grouping' => [],
        'totals' => ['count' => 0, 'groups' => 0]
      ];
    }

    // If no specific display options are set, return raw logs.
    if (empty(array_filter($displayOptions))) {
      return [
        'items' => $logs,
        'grouping' => [],
        'totals' => ['count' => count($logs), 'groups' => 0]
      ];
    }

    // Special handling for Drupal/PHP error format.
    if (!empty($displayOptions['drupal'])) {
      return $this->formatDrupalErrorsForJson($logs, $displayOptions, $filters);
    }

    // Group and format based on display options.
    $grouped = $this->groupResults($logs, $displayOptions);

    // Smart auto-regrouping: if only one group, regroup by time buckets (unless disabled).
    if (count($grouped) === 1 && !$filters['no_group']) {
      return $this->formatAutoTimeRegroupingForJson($logs, $displayOptions, $grouped, $filters);
    }

    return $this->formatGroupedResultsForJson($grouped, $displayOptions, $filters);
  }

  /**
   * Format grouped results for JSON output.
   *
   * @param array $grouped Grouped results
   * @param array $displayOptions Display configuration
   * @param array $filters Filter options
   * @return array JSON-formatted results structure
   */
  protected function formatGroupedResultsForJson(array $grouped, array $displayOptions, array $filters = []): array
  {
    $enabledColumns = $this->getEnabledDisplayColumns($displayOptions);
    $items = [];
    $minCount = $filters['min_count'] ?? 1;
    $excludedCountries = $filters['excluded_countries'] ?? [];

    foreach ($grouped as $key => $data) {
      // Apply min_count filter.
      if ($data['count'] < $minCount) {
        continue;
      }

      $log = $data['sample'];

      // Apply country exclusion filter if grouping by country.
      if (in_array('country', $enabledColumns) && !empty($excludedCountries)) {
        $country = $this->extractDisplayColumnValue('country', $log, $displayOptions, NULL);
        // Strip ANSI codes and tags for comparison.
        $country = preg_replace('/\033\[[0-9;]*m/', '', $country);
        $country = preg_replace('/<\/?[a-z]+(=[^>]+)?>/i', '', $country);
        $country = strtoupper(trim($country));

        if (in_array($country, $excludedCountries)) {
          continue;
        }
      }

      $item = ['count' => $data['count']];

      // Add values for enabled columns (without color codes).
      foreach ($enabledColumns as $column) {
        // Special handling for IP column - show prefix and all IPs when IPv6 grouped.
        if ($column === 'ip' && isset($data['logs']) && count($data['logs']) > 0) {
          $uniqueIps = $this->getUniqueIpsFromGroup($data['logs']);
          if (count($uniqueIps) > 1) {
            $firstIp = $uniqueIps[0];
            if (strpos($firstIp, ':') !== FALSE) {
              // IPv6 addresses - provide prefix and full list.
              $normalizedPrefix = $this->normalizeIpv6Prefix($firstIp);
              $item[$column] = [
                'prefix' => $normalizedPrefix,
                'ips' => $uniqueIps,
                'count' => count($uniqueIps),
              ];
            }
            else {
              // IPv4 addresses - provide as array.
              $item[$column] = $uniqueIps;
            }
          }
          else {
            $value = $this->extractDisplayColumnValue($column, $log, $displayOptions, NULL);
            $value = preg_replace('/\033\[[0-9;]*m/', '', $value);
            $value = preg_replace('/<\/?[a-z]+(=[^>]+)?>/i', '', $value);
            $item[$column] = $value;
          }
        }
        // Special handling for UA column - show all unique UAs as array.
        elseif ($column === 'ua' && isset($data['logs']) && count($data['logs']) > 1) {
          $uniqueUAs = [];
          foreach ($data['logs'] as $groupLog) {
            $ua = $groupLog['req_user_agent'] ?? $groupLog['user_agent'] ?? $groupLog['useragent'] ?? 'unknown';
            $uniqueUAs[$ua] = TRUE;
          }
          $item[$column] = array_keys($uniqueUAs);
        }
        else {
          $value = $this->extractDisplayColumnValue($column, $log, $displayOptions, NULL);
          // Strip ANSI color codes and Symfony console tags for JSON output.
          $value = preg_replace('/\033\[[0-9;]*m/', '', $value);
          $value = preg_replace('/<\/?[a-z]+(=[^>]+)?>/i', '', $value);
          $item[$column] = $value;
        }
      }

      // Add time range.
      $item['time_range'] = [
        'first_seen' => $data['first_seen'],
        'last_seen' => $data['last_seen'],
      ];

      $items[] = $item;
    }

    return [
      'items' => $items,
      'grouping' => $enabledColumns,
      'totals' => [
        'count' => array_sum(array_column($items, 'count')),
        'groups' => count($items)
      ]
    ];
  }

  /**
   * Format auto time-regrouped results for JSON output.
   *
   * @param array $logs Log entries
   * @param array $displayOptions Display configuration
   * @param array $grouped Grouped results
   * @param array $filters Filter options
   * @return array JSON-formatted results structure
   */
  protected function formatAutoTimeRegroupingForJson(array $logs, array $displayOptions, array $grouped, array $filters = []): array
  {
    // For now, return the single group without time regrouping.
    // Time regrouping logic can be added later if needed.
    return $this->formatGroupedResultsForJson($grouped, $displayOptions, $filters);
  }

  /**
   * Format Drupal errors for JSON output.
   *
   * @param array $logs Log entries
   * @param array $displayOptions Display configuration
   * @param array $filters Filter options
   * @return array JSON-formatted Drupal error results
   */
  protected function formatDrupalErrorsForJson(array $logs, array $displayOptions, array $filters = []): array
  {
    // Group and parse Drupal errors same way as displayDrupalErrors().
    $grouped = [];
    $minCount = $filters['min_count'] ?? 1;
    $varsOption = $displayOptions['vars'] ?? FALSE;

    foreach ($logs as $log) {
      // Parse the log message to get the JSON structure.
      $parsedLog = $this->parseLogMessage($log);

      // Extract Drupal watchdog specific fields.
      $severity = $parsedLog['severity'] ?? 'Unknown';
      $type = $parsedLog['type'] ?? 'Unknown';
      $variables = $parsedLog['variables'] ?? [];

      // Extract message - handle both @message and direct message.
      $message = $variables['@message'] ?? $parsedLog['message'] ?? 'No message';

      // Handle cases where message field contains nested JSON.
      if (is_string($message) && strlen($message) > 0 && ($message[0] === '{' || $message[0] === '[')) {
        $nestedData = json_decode($message, TRUE);
        if (json_last_error() === JSON_ERROR_NONE && is_array($nestedData)) {
          if (isset($nestedData['message'])) {
            $message = $nestedData['message'];
          }
          elseif (isset($nestedData['msg'])) {
            $message = $nestedData['msg'];
          }
          elseif (isset($nestedData['type'])) {
            $message = ucwords(str_replace('_', ' ', $nestedData['type']));
          }
          else {
            $message = 'Log entry';
          }
        }
      }

      // Apply variable substitution if requested.
      if (!empty($filters['substitute_vars']) && !empty($variables)) {
        $message = $this->substituteVariables($message, $variables);
      }

      // Create grouping key based on type and message.
      $key = "$type:" . md5($message);

      if (!empty($displayOptions['host'])) {
        $host = $parsedLog['site'] ?? $parsedLog['orig_host'] ?? $parsedLog['host'] ?? 'unknown';
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

    // Build JSON items.
    $items = [];
    foreach ($grouped as $key => $data) {
      // Apply min_count filter.
      if ($data['count'] < $minCount) {
        continue;
      }

      $item = [
        'count' => $data['count'],
        'severity' => $data['severity'],
        'type' => $data['type'],
        'message' => $data['message'],
      ];

      // Add variables based on varsOption or default to common variables.
      if ($varsOption === TRUE) {
        // Show all variables.
        $item['variables'] = $data['variables'];
      }
      elseif (is_array($varsOption)) {
        // Show specific variables.
        foreach ($varsOption as $varName) {
          $cleanName = ltrim($varName, '%@');
          // Check if this is a deep array reference (contains a dot).
          if (strpos($varName, '.') !== FALSE) {
            // Use nested value extraction from the full parsed log.
            $item[$cleanName] = $this->getNestedValue($data['sample'], $varName);
          }
          else {
            // Try both % and @ prefixes for Drupal variables.
            $item[$cleanName] = $data['variables']['%' . ltrim($varName, '%@')] ??
                                $data['variables']['@' . ltrim($varName, '%@')] ??
                                $data['variables'][$varName] ??
                                NULL;
          }
        }
      }
      else {
        // Default: don't include variable fields (matches regular display behavior)
        // Variables are only shown when --vars or --vars=field1,field2 is explicitly used
      }

      // Add optional display columns if enabled.
      $enabledColumns = $this->getEnabledDisplayColumns($displayOptions);
      $log = $data['sample'];
      foreach ($enabledColumns as $column) {
        $value = $this->extractDisplayColumnValue($column, $log, $displayOptions, NULL);
        // Strip ANSI color codes and Symfony console tags for JSON output.
        $value = preg_replace('/\033\[[0-9;]*m/', '', $value);
        $value = preg_replace('/<\/?[a-z]+(=[^>]+)?>/i', '', $value);
        $item[$column] = $value;
      }

      // Add time range.
      $item['time_range'] = [
        'first_seen' => $data['first_seen'],
        'last_seen' => $data['last_seen'],
      ];

      $items[] = $item;
    }

    return [
      'items' => $items,
      'grouping' => ['drupal'],
      'totals' => [
        'count' => array_sum(array_column($items, 'count')),
        'groups' => count($items)
      ]
    ];
  }

  /**
   * Display results based on display options.
   *
   * Main entry point for result formatting and display.
   *
   * @param array $logs Log entries to display
   * @param array $displayOptions Display configuration
   * @param SymfonyStyle $io Symfony console I/O helper
   * @param bool $debugMode Enable debug output
   * @param array $filters Filter options
   * @param string|null $searchTerm Optional search term for highlighting
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
   * Display raw JSON output.
   *
   * @param array $logs Log entries to display
   * @param SymfonyStyle $io Symfony console I/O helper
   */
  protected function displayRawJson(array $logs, SymfonyStyle $io): void
  {
    foreach ($logs as $log) {
      $io->writeln(json_encode($log, JSON_PRETTY_PRINT));
    }
  }

  /**
   * Group results based on display options.
   *
   * @param array $logs Log entries to group
   * @param array $displayOptions Display configuration
   * @return array Grouped results with counts and samples
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
        'sample' => $parsedLog,
        'logs' => []
      ];

      $grouped[$key]['count']++;
      $grouped[$key]['last_seen'] = $log['time'] ?? 'unknown';
      $grouped[$key]['logs'][] = $parsedLog;
    }

    // Sort by count (descending).
    uasort($grouped, function($a, $b) {
      return $b['count'] <=> $a['count'];
    });

    return $grouped;
  }

  /**
   * Parse the log data field if it contains JSON data.
   *
   * Phase 1 Optimization: STORED columns (client_ip, resp_status, req_user_agent,
   * req_uri, orig_host, country) are now available at top-level in $log array
   * from DatabaseService queries. This eliminates JSON parsing for these fields.
   *
   * @param array $log Log entry with potential JSON data field
   * @return array Parsed log entry with merged data
   */
  protected function parseLogMessage(array $log): array
  {
    // If 'data' is already an array, it's been parsed - return as-is.
    if (isset($log['data']) && is_array($log['data'])) {
      return $log;
    }

    // If there's a data field with JSON string, parse it once.
    if (isset($log['data']) && is_string($log['data'])) {
      $data = json_decode($log['data'], TRUE);
      if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        // Merge the parsed JSON data with the outer log data.
        // IMPORTANT: $log takes precedence to preserve STORED column values.
        // Phase 1 fields (client_ip, resp_status, etc.) are at top level in $log.
        $merged = array_merge($data, $log);

        // Replace the JSON string with the parsed array to cache the parse.
        // This prevents re-parsing if parseLogMessage() is called again.
        $merged['data'] = $data;

        // Preserve enrichment fields added by commands (e.g., bot_verified, bot_name).
        if (isset($log['bot_verified'])) {
          $merged['bot_verified'] = $log['bot_verified'];
        }
        if (isset($log['bot_name'])) {
          $merged['bot_name'] = $log['bot_name'];
        }

        return $merged;
      }
    }

    return $log;
  }

  /**
   * Build grouping key based on display options.
   *
   * @param array $log Log entry to generate key for
   * @param array $displayOptions Display configuration
   * @return string Grouping key string
   */
  protected function buildGroupingKey(array $log, array $displayOptions): string
  {
    $keyParts = [];

    if (!empty($displayOptions['host'])) {
      // Use site field first, then fall back to orig_host or host.
      $host = $log['site'] ?? $log['orig_host'] ?? $log['host'] ?? 'unknown';
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
      $uri = $log['req_uri'] ?? $log['uri'] ?? $log['request_uri'] ?? $log['path'] ?? $log['location'] ?? '/';
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
      // Normalize IPv6 addresses to /48 prefix for campaign grouping.
      $normalizedIp = $this->normalizeIpv6Prefix($ip);
      $keyParts[] = 'ip:' . $normalizedIp;
    }

    if (!empty($displayOptions['bot_name'])) {
      $botName = $log['bot_name'] ?? 'Unknown';
      $keyParts[] = 'bot_name:' . $botName;
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
      // Use STORED column (extracted during sync with fallback chain)
      $country = $log['country'] ?? 'unknown';
      $keyParts[] = 'country:' . $country;
    }

    if (!empty($displayOptions['region'])) {
      // Use STORED column (extracted during sync with fallback chain)
      $region = $log['region'] ?? 'unknown';
      $keyParts[] = 'region:' . $region;
    }

    if (!empty($displayOptions['verified'])) {
      // Use bot_verified field added by BotCommand.
      $verified = $log['bot_verified'] ?? 'unknown';
      $keyParts[] = 'verified:' . $verified;
    }

    if (!empty($displayOptions['last_seen'])) {
      // last_seen doesn't participate in grouping (it's calculated from time range).
      // We just need it in displayOptions to trigger column display.
    }

    return empty($keyParts) ? 'all' : implode('|', $keyParts);
  }

  /**
   * Display grouped results in a table format.
   *
   * @param array $grouped Grouped results array
   * @param array $displayOptions Display configuration
   * @param SymfonyStyle $io Symfony console I/O helper
   * @param array $filters Filter options
   * @param string|null $searchTerm Optional search term for highlighting
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
    $excludedCountries = $filters['excluded_countries'] ?? [];

    foreach ($grouped as $key => $data) {
      // Apply min_count filter.
      if ($data['count'] < $minCount) {
        continue;
      }

      $log = $data['sample'];

      // Apply country exclusion filter if grouping by country.
      if (in_array('country', $enabledColumns) && !empty($excludedCountries)) {
        $country = $this->extractDisplayColumnValue('country', $log, $displayOptions, NULL);
        // Strip ANSI codes and tags for comparison.
        $country = preg_replace('/\033\[[0-9;]*m/', '', $country);
        $country = preg_replace('/<\/?[a-z]+(=[^>]+)?>/i', '', $country);
        $country = strtoupper(trim($country));

        if (in_array($country, $excludedCountries)) {
          continue;
        }
      }

      $row = [$data['count']];

      // Add values for enabled columns.
      foreach ($enabledColumns as $column) {
        // Special handling for IP column - show prefix when IPv6 grouped.
        if ($column === 'ip' && isset($data['logs']) && count($data['logs']) > 0) {
          $uniqueIps = $this->getUniqueIpsFromGroup($data['logs']);
          if (count($uniqueIps) > 1) {
            // Multiple IPs - check if they're IPv6 addresses that were normalized.
            $firstIp = $uniqueIps[0];
            if (strpos($firstIp, ':') !== FALSE) {
              // IPv6 addresses - show normalized prefix.
              $normalizedPrefix = $this->normalizeIpv6Prefix($firstIp);
              $row[] = $normalizedPrefix . ' (' . count($uniqueIps) . ' IPs)';
            }
            else {
              // IPv4 addresses - show all on separate lines.
              $row[] = implode("\n", $uniqueIps);
            }
          }
          else {
            // Single IP - use normal extraction.
            $row[] = $this->extractDisplayColumnValue($column, $log, $displayOptions, $searchTerm);
          }
        }
        // Special handling for UA column - show all unique UAs on separate lines.
        elseif ($column === 'ua' && isset($data['logs']) && count($data['logs']) > 1) {
          $uniqueUAs = [];
          foreach ($data['logs'] as $groupLog) {
            $ua = $groupLog['req_user_agent'] ?? $groupLog['user_agent'] ?? $groupLog['useragent'] ?? 'unknown';
            if (strlen($ua) > 100) {
              $ua = substr($ua, 0, 97) . '...';
            }
            $uniqueUAs[$ua] = TRUE;
          }
          $row[] = implode("\n", array_keys($uniqueUAs));
        }
        else {
          $row[] = $this->extractDisplayColumnValue($column, $log, $displayOptions, $searchTerm);
        }
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
   *
   * @param array $logs Log entries
   * @param array $displayOptions Display configuration
   * @param SymfonyStyle $io Symfony console I/O helper
   * @param array $singleGroup Single group data
   * @param string $groupKey Group identifier
   * @param array $filters Filter options
   * @param string|null $searchTerm Optional search term for highlighting
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
   *
   * @param string $timestamp ISO timestamp string
   * @return string Day in YYYY-MM-DD format or 'unknown'
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
   *
   * @param string $timestamp ISO timestamp string
   * @return string Hour in HH:00 format or 'unknown'
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
   *
   * @param string $timestamp ISO timestamp string
   * @return string Minute in HH:MM format or 'unknown'
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
   *
   * @param string $host Hostname to map
   * @return string Display label or original hostname
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
   *
   * @param string $host Hostname to shorten and colorize
   * @return string Colorized short hostname
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
   *
   * @param string $status HTTP status code
   * @return string Colorized status code
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
   * Colorize bot verification status.
   *
   * @param string $verified Verification status: 'verified', 'spoofed', or 'unverified'
   * @return string Colorized verification status
   */
  protected function colorizeBotVerificationStatus(string $verified): string
  {
    switch ($verified) {
      case 'verified':
        return "<fg=green>✓ Verified</fg=green>";

      case 'spoofed':
        return "<fg=red>✗ SPOOFED</fg=red>";

      case 'unverified':
        return "<fg=yellow>? Unknown</fg=yellow>";

      default:
        return $this->colorizeUnknownValue($verified);
    }
  }

  /**
   * Highlight search term in user agent strings.
   *
   * @param string $ua User agent string
   * @param string|null $searchTerm Search term to highlight
   * @return string User agent with highlighted search term
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
   *
   * @param string $timestamp Unix timestamp (integer stored as string)
   * @return string Formatted timestamp or 'unknown'
   */
  protected function formatTimestamp(string $timestamp): string
  {
    return is_numeric($timestamp) ? date('m-d H:i:s', (int) $timestamp) : 'unknown';
  }

  /**
   * Format time range for display (combines first and last seen).
   *
   * @param string $firstSeen First timestamp (Unix timestamp as string)
   * @param string $lastSeen Last timestamp (Unix timestamp as string)
   * @return string Formatted time range
   */
  protected function formatTimeRange(string $firstSeen, string $lastSeen): string
  {
    $first = is_numeric($firstSeen) ? (int) $firstSeen : NULL;
    $last = is_numeric($lastSeen) ? (int) $lastSeen : NULL;

    // Neither valid.
    if ($first === NULL && $last === NULL) {
      return 'unknown';
    }

    // Only one valid - show it.
    if ($first === NULL) {
      return date('m-d H:i:s', $last);
    }
    if ($last === NULL || $first === $last) {
      return date('m-d H:i:s', $first);
    }

    // Same day - show "m-d H:i:s - H:i:s".
    if (date('m-d', $first) === date('m-d', $last)) {
      return date('m-d H:i:s', $first) . ' - ' . date('H:i:s', $last);
    }

    // Different days - show full range.
    return date('m-d H:i:s', $first) . ' - ' . date('m-d H:i:s', $last);
  }

  /**
   * Display Drupal/PHP watchdog errors with file:line grouping.
   *
   * @param array $logs Log entries
   * @param array $displayOptions Display configuration
   * @param SymfonyStyle $io Symfony console I/O helper
   * @param array $filters Filter options
   * @param string|null $searchTerm Optional search term for highlighting
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

      // Apply variable substitution if requested.
      if (!empty($filters['substitute_vars']) && !empty($variables)) {
        $message = $this->substituteVariables($message, $variables);
      }

      // Create grouping key based on type and message.
      // If --host is specified, include host in grouping key so each site is grouped separately.
      $key = "$type:" . md5($message);

      if (!empty($displayOptions['host'])) {
        $host = $parsedLog['site'] ?? $parsedLog['orig_host'] ?? $parsedLog['host'] ?? 'unknown';
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

      // Get the sample log for this group (used for variable and column extraction).
      $log = $data['sample'];

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
          // Check if this is a deep array reference (contains a dot).
          if (strpos($varName, '.') !== FALSE) {
            // Use nested value extraction from the full parsed log.
            $value = $this->getNestedValue($log, $varName) ?? '-';
          }
          else {
            // Try both % and @ prefixes for Drupal variables.
            $value = $data['variables']['%' . ltrim($varName, '%@')] ??
                     $data['variables']['@' . ltrim($varName, '%@')] ??
                     $data['variables'][$varName] ??
                     '-';
          }
          $row[] = $value;
        }
      }

      // Add optional display column values (--host, --ip, etc.).
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
   *
   * @param string $severity Drupal severity level
   * @return string Colorized severity level
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

  /**
   * Get nested array value using dot notation path.
   *
   * Supports paths like "post.name" to access $data['post']['name'].
   *
   * @param array $data The array to traverse.
   * @param string $path Dot-separated path to the value.
   * @return string|null The value at the path, or NULL if not found.
   */
  protected function getNestedValue(array $data, string $path): ?string
  {
    $keys = explode('.', $path);
    $current = $data;

    foreach ($keys as $key) {
      if (!is_array($current) || !isset($current[$key])) {
        return NULL;
      }
      $current = $current[$key];
    }

    // Convert to string if we found a value.
    if ($current === NULL) {
      return NULL;
    }

    return is_array($current) ? json_encode($current) : (string) $current;
  }

  /**
   * Normalize IPv6 address to /48 prefix for campaign grouping.
   *
   * IPv6 /48 prefixes are typically allocated to a single customer/location,
   * making them useful for identifying coordinated campaigns from the same source.
   *
   * @param string $ip IP address (IPv4 or IPv6)
   * @return string Normalized IP or original IPv4
   */
  protected function normalizeIpv6Prefix(string $ip): string
  {
    // Check if it's an IPv6 address.
    if (strpos($ip, ':') === FALSE) {
      // IPv4 - return as-is.
      return $ip;
    }

    // Expand IPv6 to full format for prefix extraction.
    $expanded = inet_pton($ip);
    if ($expanded === FALSE) {
      // Invalid IP, return as-is.
      return $ip;
    }

    // Convert to binary string and extract first 48 bits (6 bytes).
    $binary = unpack('C*', $expanded);
    // Re-index array to start at 0.
    $binary = array_values($binary);

    // Convert first 6 bytes back to IPv6 notation.
    $prefixHex = sprintf(
      '%02x%02x:%02x%02x:%02x%02x',
      $binary[0], $binary[1],
      $binary[2], $binary[3],
      $binary[4], $binary[5]
    );

    // Return in human-readable format: 2600:1900:0:*
    return $prefixHex . ':*';
  }

  /**
   * Get all unique IPs from a group for display.
   *
   * When IPs are normalized to prefixes, this extracts all original IPs.
   *
   * @param array $logs All logs in the group
   * @return array Unique IP addresses
   */
  protected function getUniqueIpsFromGroup(array $logs): array
  {
    $ips = [];
    foreach ($logs as $log) {
      $ip = $log['client_ip'] ?? $log['remote_addr'] ?? $log['ip'] ?? $log['remote_ip'] ?? 'unknown';
      $ips[$ip] = TRUE;
    }
    return array_keys($ips);
  }

}
