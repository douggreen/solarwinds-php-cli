<?php

/**
 * @file BaseSolarWindsCommand.php
 * @brief Abstract base command providing shared functionality for SolarWinds
 *        log analysis
 *
 * @class BaseSolarWindsCommand
 * @brief Abstract base class providing shared functionality for all SolarWinds
 *        commands
 *
 * This base class provides common
 * functionality while requiring child classes to implement only 3 abstract
 * methods. This eliminates code duplication across commands.
 *
 * @section abstract_methods Required Abstract Methods
 *
 * Child classes must implement these abstract methods:
 * - buildSearchQuery(array $options): string - Construct command-specific query
 * - parseScriptSpecificOptions(InputInterface $input): array - Parse arguments
 * - validateQuery(string $query, array $options): void - Validate logic
 *
 * @section shared_functionality Shared Functionality Provided
 *
 * - **Time Parsing**: Comprehensive time option handling (--5m, --1h, --1d,
 *   --yesterday, etc.)
 * - **Site Filtering**: YAML-configured site mappings and filtering
 * - **Display Options**: Standardized display formatting (--status, --host,
 *   --country, etc.)
 * - **API Integration**: Complete SolarWinds API interaction with pagination
 *   and caching
 * - **Signal Handling**: Graceful interruption handling (Ctrl+C)
 * - **Progress Reporting**: Real-time progress bars for long-running queries
 * - **Input Validation**: Comprehensive argument validation and error handling
 *
 * @section architecture Command Architecture
 *
 * The command execution flow:
 * 1. Parse common arguments (time, site, display options)
 * 2. Call parseScriptSpecificOptions() for command-specific parsing
 * 3. Call buildSearchQuery() to construct the query
 * 4. Call validateQuery() for command-specific validation
 * 5. Execute query through ApiService with caching
 * 6. Format and display results through DisplayService
 *
 * @section example Implementation Example
 * @code{.php}
 * class MyCommand extends BaseSolarWindsCommand
 * {
 *     protected string $defaultTime = '1h';
 *     protected array $defaultDisplayOptions = ['host'];
 *
 *     protected function buildSearchQuery(array $options): string
 *     {
 *         return "{ json.field:value }";
 *     }
 *
 *     protected function parseScriptSpecificOptions(InputInterface $input): array
 *     {
 *         return ['custom_arg' => $input->getArgument('custom_arg')];
 *     }
 *
 *     protected function validateQuery(string $query, array $options): void
 *     {
 *         // Command-specific validation logic
 *     }
 * }
 * @endcode
 *
 * @see ConfigurationService For site mapping and configuration
 * @see ApiService For SolarWinds API integration
 * @see DisplayService For result formatting and display
 * @see TimeSpecifications For time parsing specifications
 *
 * @note This class automatically handles signal registration for graceful interruption
 * @warning Child classes should not override execute() - use the abstract methods instead
 */

namespace SolarWinds\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\ApiService;
use SolarWinds\Services\DisplayService;
use SolarWinds\Services\DatabaseService;
use SolarWinds\Services\TimeSpecifications;

/**
 * Base class for all SolarWinds commands
 *
 * Provides common functionality including argument parsing, API integration,
 * configuration management, and display formatting.
 */
abstract class BaseSolarWindsCommand extends Command
{
  protected ConfigurationService $config;
  protected ApiService $apiService;
  protected DisplayService $displayService;
  protected DatabaseService $databaseService;
  protected SymfonyStyle $io;

  // Default values that child classes can override.
  protected string $defaultTime = '1h';
  protected array $defaultDisplayOptions = [];

  // Site mapping loaded dynamically from configuration.
  protected array $siteHosts = [];

  // Time mapping for user-friendly time options (dynamically generated).
  protected static ?array $timeMappings = NULL;

  // Signal handling for graceful interruption.
  protected static bool $interrupted = FALSE;

  // JSON output mode.
  protected bool $jsonMode = FALSE;

  /**
   * Constructor.
   *
   * Initializes configuration service and parent command, then sets up
   * API, display, and database services for command execution.
   */
  public function __construct()
  {
    // Initialize config FIRST, before parent constructor which calls configure().
    $this->config = new ConfigurationService();

    parent::__construct();

    $this->apiService = new ApiService($this->config);
    $this->displayService = new DisplayService($this->config);
    $this->databaseService = new DatabaseService($this->config);
  }

  /**
   * Get time mappings for all supported time options.
   *
   * @return array Time mappings array
   */
  protected static function getTimeMappings(): array
  {
    if (self::$timeMappings === NULL) {
      self::$timeMappings = [];

      // Get all available time options from centralized specifications.
      $allTimeOptions = TimeSpecifications::getAllTimeOptions();

      // Generate mappings for each option.
      foreach ($allTimeOptions as $timeOption) {
        $mapping = TimeSpecifications::convertToTimeRange($timeOption);
        if ($mapping !== NULL) {
          self::$timeMappings[$timeOption] = $mapping;
        }
      }
    }

    return self::$timeMappings;
  }

  /**
   * Configure common options for all SolarWinds commands.
   *
   * Sets up all standard command options including time ranges, site filters,
   * display options, and global filters. Called automatically by Symfony Console.
   */
  protected function configure(): void
  {
    // Load site mappings from configuration (must happen here after config is initialized).
    $this->siteHosts = $this->config->getSiteHostMappings();

    // Get dynamic time mappings.
    $timeMappings = self::getTimeMappings();

    // Add all time options dynamically.
    foreach ($timeMappings as $timeKey => $timeValue) {
      [$start, $end] = $timeValue;

      // Create appropriate descriptions.
      if (str_ends_with($timeKey, 'D')) {
        if ($timeKey === '1D' || $timeKey === 'yesterday') {
          $description = 'Yesterday (full day)';
        }
        else {
          $days = str_replace('D', '', $timeKey);
          $description = "$days days ago (full day)";
        }
      } elseif ($timeKey === 'hour' || $timeKey === 'day' || $timeKey === 'week') {
        $description = "Last 1 " . $timeKey;
      }
      else {
        $description = "Last $timeKey";
      }

      $this->addOption($timeKey, NULL, InputOption::VALUE_NONE, $description);
    }

    $this
      // Alternative time specification.
      ->addOption('time', 't', InputOption::VALUE_REQUIRED,
        'Time range (alternative to --1h, --1d flags)', NULL)
      ->addOption('since', NULL, InputOption::VALUE_REQUIRED,
        'Start time (e.g., "2 hours ago")')
      ->addOption('until', NULL, InputOption::VALUE_REQUIRED,
        'End time (e.g., "now")', 'now')

      // Site options.
    ;
    foreach ($this->siteHosts as $option => $siteData) {
      $this->addOption($option, NULL, InputOption::VALUE_NONE, $siteData['description']);
    }

    // Display options.
    $displayOptions = [
      'status' => 'Show HTTP status codes',
      'host' => 'Show originating hosts',
      'ua' => 'Show user agents',
      'ip' => 'Show IP addresses',
      'country' => 'Show country information',
      'region' => 'Show region information',
      'drupal' => 'Format PHP/Drupal watchdog errors with file and line grouping',
      'vars' => 'Show variable replacements (all variables, or specify: user,ip,post.name). Supports deep array references with dot notation (e.g., post.name, geoip.country_code2)'
    ];
    foreach ($displayOptions as $option => $description) {
      if ($option === 'vars') {
        $this->addOption($option, NULL, InputOption::VALUE_OPTIONAL, $description, FALSE);
      }
      else {
        $this->addOption($option, NULL, InputOption::VALUE_NONE, $description);
      }
    }

    $this
      ->addOption('path', NULL, InputOption::VALUE_OPTIONAL, 'Show request paths (optionally specify segments)', FALSE)

      // Other options.
      ->addOption('min-count', NULL, InputOption::VALUE_REQUIRED, 'Minimum count threshold', 1)
      ->addOption('cached', NULL, InputOption::VALUE_OPTIONAL, 'Use cached results (optionally specify max age)', FALSE)
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Maximum number of results', 1000)
      ->addOption('debug', NULL, InputOption::VALUE_NONE, 'Enable debug output')
      ->addOption('json', NULL, InputOption::VALUE_NONE, 'Output results as JSON (suppresses progress and interactive messages)')
      ->addOption('no-group', NULL, InputOption::VALUE_NONE, 'Disable automatic time-based regrouping for single result groups')
      ->addOption('substitute-vars', NULL, InputOption::VALUE_NONE, 'Substitute variable placeholders in messages (e.g., %name, %choice) with their values')

      // Global filter options.
      ->addOption('country-filter', NULL, InputOption::VALUE_REQUIRED, 'Filter by country code (e.g., US, GB, FR)')
      ->addOption('city-filter', NULL, InputOption::VALUE_REQUIRED, 'Filter by city name (e.g., London, Paris)')
      ->addOption('status-code-filter', NULL, InputOption::VALUE_REQUIRED, 'Filter by HTTP status code (e.g., 404, 500)')
      ->addOption('user-agent-filter', NULL, InputOption::VALUE_REQUIRED, 'Filter by user agent pattern (e.g., bot, mobile)')
      ->addOption('path-filter', NULL, InputOption::VALUE_OPTIONAL, 'Filter by request path (e.g., /, /api, /admin)', FALSE)
      ->addOption('ip-filter', NULL, InputOption::VALUE_REQUIRED, 'Filter by IP address (single IP or comma-separated list)')

      // Shortcuts for common filter options.
      ->addOption('status-code', NULL, InputOption::VALUE_REQUIRED, 'Shortcut for --status-code-filter')
      ->addOption('code', NULL, InputOption::VALUE_REQUIRED, 'Shortcut for --status-code-filter')

      // Client-side filtering options.
      ->addOption('filter', NULL, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        'Client-side regex filter in format "field:regex" (URL-decoded before matching). Example: --filter="req_uri:\?.*<script". Multiple filters are AND\'d together')
    ;
  }

  /**
   * Execute the command - template method that child classes customize.
   *
   * Implements the main execution flow: parse arguments, build query,
   * validate, and execute search with results display.
   *
   * @param InputInterface $input Command input interface
   * @param OutputInterface $output Command output interface
   * @return int Exit code (Command::SUCCESS or Command::FAILURE)
   */
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $this->io = new SymfonyStyle($input, $output);
    $this->jsonMode = $input->getOption('json');

    try {
      // Parse and validate arguments.
      $queryOptions = $this->parseArguments($input);

      // Build the search query (implemented by child classes).
      $query = $this->buildSearchQuery($queryOptions);

      // Apply site filtering automatically (base class handles this).
      $query = $this->applySiteFiltering($query, $queryOptions);

      // Validate the query and options.
      $this->validateQuery($query, $queryOptions);

      // Execute the search and display results.
      return $this->executeSearch($query, $queryOptions);

    }
    catch (\Exception $e) {
      if ($this->jsonMode) {
        $this->outputJson('error', NULL, [
          'error' => [
            'message' => $e->getMessage(),
            'type' => get_class($e),
          ]
        ]);
      }
      else {
        $this->io->error($e->getMessage());
      }
      return Command::FAILURE;
    }
  }

  /**
   * Parse all input arguments and options into a structured array.
   *
   * Consolidates time options, site filters, display preferences, and
   * command-specific options into a unified structure.
   *
   * @param InputInterface $input Command input interface
   * @return array Structured options array with keys: time, sites, display, filters, script_specific
   */
  protected function parseArguments(InputInterface $input): array
  {
    $options = [
      'time' => $this->parseTimeOptions($input),
      'sites' => $this->parseSiteOptions($input),
      'display' => $this->parseDisplayOptions($input),
      'filters' => $this->parseFilterOptions($input),
      'script_specific' => $this->parseScriptSpecificOptions($input),
    ];

    return $options;
  }

  /**
   * Parse time-related options.
   *
   * Resolves time range from --since/--until, time flags (--1h, --1d),
   * or --time option, with fallback to command default.
   *
   * @param InputInterface $input Command input interface
   * @return array Time options with keys: start_time, end_time, human_readable
   * @throws \InvalidArgumentException If no valid time option is specified
   */
  protected function parseTimeOptions(InputInterface $input): array
  {
    $since = $input->getOption('since');
    $until = $input->getOption('until');
    $timeOption = $input->getOption('time');

    // Check for custom since/until first.
    if ($since) {
      return [
        'start_time' => $since,
        'end_time' => $until,
        'human_readable' => "$since to $until"
      ];
    }

    // Check for individual time flags using dynamic mappings.
    $timeMappings = self::getTimeMappings();

    foreach ($timeMappings as $flag => $times) {
      if ($input->getOption($flag)) {
        [$start, $end] = $times;
        return [
          'start_time' => $start,
          'end_time' => $end,
          'human_readable' => "last $flag"
        ];
      }
    }

    // Fall back to --time option if provided.
    if ($timeOption && isset($timeMappings[$timeOption])) {
      [$start, $end] = $timeMappings[$timeOption];
      return [
        'start_time' => $start,
        'end_time' => $end,
        'human_readable' => "last $timeOption"
      ];
    }

    // Use default time if nothing specified.
    $defaultFlag = $this->defaultTime;
    if (isset($timeMappings[$defaultFlag])) {
      [$start, $end] = $timeMappings[$defaultFlag];
      return [
        'start_time' => $start,
        'end_time' => $end,
        'human_readable' => "last $defaultFlag (default)"
      ];
    }

    throw new \InvalidArgumentException("No valid time option specified");
  }

  /**
   * Parse site filtering options.
   *
   * Checks all configured site flags and returns active site names.
   *
   * @param InputInterface $input Command input interface
   * @return array Array of active site names
   */
  protected function parseSiteOptions(InputInterface $input): array
  {
    $sites = [];
    foreach (array_keys($this->siteHosts) as $site) {
      if ($input->getOption($site)) {
        $sites[] = $site;
      }
    }
    return $sites;
  }

  /**
   * Parse display formatting options.
   *
   * Builds display configuration from default options and explicit user flags,
   * tracking which options were explicitly set.
   *
   * @param InputInterface $input Command input interface
   * @return array Display options with _explicit tracking sub-array
   */
  protected function parseDisplayOptions(InputInterface $input): array
  {
    $display = [];
    $explicitOptions = []; // Track which options were explicitly set by user.

    // Apply default display options first.
    foreach ($this->defaultDisplayOptions as $option) {
      $display[$option] = TRUE;
    }

    // Then check for explicit options and track them.
    $displayFlags = ['status', 'host', 'ua', 'ip', 'country', 'region', 'drupal'];
    foreach ($displayFlags as $flag) {
      if ($input->getOption($flag)) {
        $display[$flag] = TRUE;
        $explicitOptions[$flag] = TRUE;
      }
    }

    // Handle path option (can have a value).
    $pathOption = $input->getOption('path');
    if ($pathOption !== FALSE) {
      $display['path'] = $pathOption === NULL ? 1 : (int) $pathOption;
      $explicitOptions['path'] = TRUE;
    }

    // Handle vars option (can have a value).
    $varsOption = $input->getOption('vars');
    if ($varsOption !== FALSE) {
      if ($varsOption === NULL || $varsOption === '' || $varsOption === TRUE) {
        // --vars with no value = show all variables
        $display['vars'] = TRUE;
      }
      else {
        // --vars=file,line,function = show specific variables as columns
        $display['vars'] = array_map('trim', explode(',', $varsOption));
      }
      $explicitOptions['vars'] = TRUE;
    }

    // Add tracking of explicit options to the display array.
    $display['_explicit'] = $explicitOptions;

    return $display;
  }

  /**
   * Parse filtering and other options.
   *
   * Extracts all filter options including min_count, cache settings,
   * global filters, and client-side filters.
   *
   * @param InputInterface $input Command input interface
   * @return array Filter options array
   */
  protected function parseFilterOptions(InputInterface $input): array
  {
    $cachedOption = $input->getOption('cached');
    $useCached = $cachedOption !== FALSE;

    return [
      'min_count' => (int) $input->getOption('min-count'),
      'use_cached' => $useCached,
      'limit' => (int) $input->getOption('limit'),
      'debug' => $input->getOption('debug'),
      'json' => $input->getOption('json'),
      'no_group' => $input->getOption('no-group'),
      'substitute_vars' => $input->getOption('substitute-vars'),
      'country_filter' => $input->getOption('country-filter'),
      'city_filter' => $input->getOption('city-filter'),
      'status_code_filter' => $input->getOption('status-code-filter') ?: $input->getOption('status-code') ?: $input->getOption('code'),
      'user_agent_filter' => $input->getOption('user-agent-filter'),
      'path_filter' => $input->getOption('path-filter') !== FALSE ? ($input->getOption('path-filter') ?: '/') : NULL,
      'ip_filter' => $input->getOption('ip-filter'),
      'client_side_filters' => $input->getOption('filter') ?: [],
    ];
  }

  /**
   * Apply site filtering to query automatically (base class handles this).
   *
   * Adds site-based filtering conditions to the search query using
   * configured site host mappings.
   *
   * @param string $query Base search query
   * @param array $options Parsed command options including sites array
   * @return string Modified query with site filtering applied
   */
  protected function applySiteFiltering(string $query, array $options): string
  {
    if (!empty($options['sites'])) {
      $siteConditions = [];
      foreach ($options['sites'] as $site) {
        $host = $this->siteHosts[$site]['host'];
        // Check both json.site and json.orig_host fields to support different log formats.
        $siteConditions[] = "( { json.site:$host } OR { json.orig_host:$host } )";
      }
      $siteFilter = '( ' . implode(' OR ', $siteConditions) . ' )';
      $query = "($query) AND $siteFilter";
    }
    return $query;
  }

  /**
   * Apply global filter options to a query.
   *
   * Adds filtering conditions for country, city, status code, user agent,
   * path, and IP address based on active filter options.
   *
   * @param string $baseQuery Base search query
   * @param array $options Parsed command options
   * @param array $excludeFilters Filter types to exclude from application
   * @return string Modified query with filters applied
   */
  protected function applyGlobalFilters(string $baseQuery, array $options, array $excludeFilters = []): string
  {
    $query = $baseQuery;

    // Apply country filter.
    if (!in_array('country', $excludeFilters) && !empty($options['filters']['country_filter'])) {
      $country = $options['filters']['country_filter'];
      $query .= " { json.geoip.country_code2:$country }";
    }

    // Apply city filter.
    if (!in_array('city', $excludeFilters) && !empty($options['filters']['city_filter'])) {
      $city = $options['filters']['city_filter'];
      $query .= " { json.geoip.city_name:$city }";
    }

    // Apply status code filter.
    if (!in_array('status', $excludeFilters) && !empty($options['filters']['status_code_filter'])) {
      $statusCode = $options['filters']['status_code_filter'];
      $query .= " { json.resp_status:$statusCode }";
    }

    // Apply user agent filter.
    if (!in_array('user_agent', $excludeFilters) && !empty($options['filters']['user_agent_filter'])) {
      $agent = $options['filters']['user_agent_filter'];
      $query .= " { json.req_user_agent:$agent }";
    }

    // Apply path filter with correct Papertrail JSON syntax.
    if (!in_array('path', $excludeFilters) && !empty($options['filters']['path_filter'])) {
      $path = $options['filters']['path_filter'];
      if ($path === '/') {
        // Exact match for root path only.
        $query .= ' { json.req_uri:"/" }';
      }
      else {
        // Substring match (without quotes) for other paths.
        $query .= " { json.req_uri:$path }";
      }
    }

    // Apply IP filter.
    if (!in_array('ip', $excludeFilters) && !empty($options['filters']['ip_filter'])) {
      $ipFilter = $options['filters']['ip_filter'];
      // Handle multiple IPs separated by commas or spaces.
      if (preg_match('/[, ]/', $ipFilter)) {
        // Convert various separators to OR syntax for SolarWinds API.
        $ipQuery = preg_replace('/[, ]+/', ' OR ', $ipFilter);
        $query .= " { json.client_ip:$ipQuery }";
      }
      else {
        // Single IP.
        $query .= " { json.client_ip:$ipFilter }";
      }
    }

    return $query;
  }

  /**
   * Apply client-side regex filters to results.
   *
   * Filters logs based on --filter options in format "field:regex".
   * Multiple filters are AND'd together (all must match).
   * Field names can be specified with or without 'json.' prefix.
   *
   * @param array $logs Array of log entries to filter
   * @param array $options Parsed command options including filters array
   * @return array Filtered log entries
   * @throws \InvalidArgumentException If filter format is invalid or regex is malformed
   */
  protected function filterResults(array $logs, array $options): array
  {
    $filters = $options['filters']['client_side_filters'] ?? [];

    if (empty($filters)) {
      return $logs;
    }

    $filtered = [];
    $debugMode = $options['filters']['debug'] ?? FALSE;

    if ($debugMode && !empty($filters)) {
      $this->debugOutput('');
      $this->debugOutput('=== Client-Side Filtering ===');
      $this->debugOutput("Applying filters:");
      foreach ($filters as $filter) {
        $this->debugOutput("  - $filter");
      }
      if (!empty($logs)) {
        $this->debugOutput("Sample log fields: " . implode(', ', array_keys($logs[0])));
      }
      $this->debugOutput('');
    }

    foreach ($logs as $log) {
      $matchesAll = TRUE;
      $failedFilter = NULL;
      $fieldValue = NULL;

      // AND logic - all filters must match.
      foreach ($filters as $filter) {
        // Parse filter in format "field:regex".
        if (strpos($filter, ':') === FALSE) {
          throw new \InvalidArgumentException("Invalid filter format: '$filter'. Expected 'field:regex'");
        }

        [$field, $regex] = explode(':', $filter, 2);

        // Normalize field name - strip 'json.' prefix if present.
        $field = preg_replace('/^json\./', '', $field);

        // Get field value - check top level first, then parse JSON message.
        $value = '';
        if (isset($log[$field])) {
          $value = $log[$field];
        }
        elseif (isset($log['message'])) {
          // Parse JSON message to get nested fields.
          $messageData = json_decode($log['message'], TRUE);
          if (is_array($messageData) && isset($messageData[$field])) {
            $value = $messageData[$field];
          }
        }

        // URL-decode the value to catch encoded attacks (e.g., %3Cscript%3E = <script>).
        $decodedValue = urldecode($value);

        // Apply regex filter on decoded value.
        // Use # as delimiter and escape any # in the pattern to avoid conflicts.
        $escapedRegex = str_replace('#', '\#', $regex);
        if (@preg_match('#' . $escapedRegex . '#', $decodedValue) === FALSE) {
          throw new \InvalidArgumentException("Invalid regex in filter: '$regex'");
        }

        if (!preg_match('#' . $escapedRegex . '#', $decodedValue)) {
          $matchesAll = FALSE;
          $failedFilter = $filter;
          $fieldValue = $value;  // Keep original value for display.
          break;
        }
      }

      if ($matchesAll) {
        $filtered[] = $log;

        // Show what was kept (matched all filters).
        if ($debugMode) {
          $messageData = isset($log['message']) ? json_decode($log['message'], TRUE) : [];
          $host = $messageData['orig_host'] ?? $log['hostname'] ?? 'unknown';
          $uri = $messageData['req_uri'] ?? 'unknown';
          $this->debugOutput("MATCHED: $host - $uri");

          // Show which field value matched for verification.
          foreach ($filters as $filter) {
            [$field, $regex] = explode(':', $filter, 2);
            $field = preg_replace('/^json\./', '', $field);

            $value = '';
            if (isset($log[$field])) {
              $value = $log[$field];
            }
            elseif (isset($messageData[$field])) {
              $value = $messageData[$field];
            }

            $decodedValue = urldecode($value);
            $this->debugOutput("  Filter: $filter");
            $this->debugOutput("  Value: " . substr($value, 0, 200));
            if ($decodedValue !== $value) {
              $this->debugOutput("  Decoded: " . substr($decodedValue, 0, 200));
            }
          }
          $this->debugOutput('');
        }
      }
    }

    if ($debugMode && !empty($filters)) {
      $this->debugOutput("Client-side filtering: " . count($logs) . " results -> " . count($filtered) . " results after filters");
      $this->debugOutput('');
    }

    return $filtered;
  }

  /**
   * Execute search using database-backed universal sync.
   *
   * This simplified implementation fetches data via searchLogsWithProgress()
   * which automatically handles database storage and API syncing.
   *
   * @param string $query Search query string
   * @param array $options Parsed command options
   * @return int Command exit code (Command::SUCCESS)
   */
  protected function executeSearch(string $query, array $options): int
  {
    // Register signal handlers for graceful interruption.
    $this->registerSignalHandlers();

    // Fetch data (from database or API via universal sync).
    if (!$this->jsonMode) {
      $this->io->section('Searching SolarWinds Logs');
      $this->io->text("Time range: {$options['time']['human_readable']}");
      $this->io->text("Query: $query");
      $this->io->newLine();
    }

    $results = $this->searchLogsWithProgress($query, $options);

    // Apply client-side filters.
    $originalCount = count($results);
    $results = $this->filterResults($results, $options);
    $filteredCount = count($results);

    // Extract search term for highlighting.
    $searchTerm = $this->extractSearchTerm($options);

    // Display results.
    if ($this->jsonMode) {
      // JSON output mode.
      $formattedResults = $this->displayService->formatResultsForJson(
        $results,
        $options['display'],
        $options['filters'],
        $searchTerm
      );
      $this->outputJson('success', $formattedResults, [
        'query' => $query,
        'time_range' => $options['time'],
        'filters' => [
          'original_count' => $originalCount,
          'filtered_count' => $filteredCount,
        ]
      ]);
    }
    else {
      // Regular display mode.
      $this->displayService->displayResults(
        $results,
        $options['display'],
        $this->io,
        $options['filters']['debug'] || $this->config->isDebugEnabled(),
        $options['filters'],
        $searchTerm
      );

      // Show filtered count if filtering was applied.
      if (!empty($options['filters']['client_side_filters']) && $originalCount !== $filteredCount) {
        $this->io->success("Found $filteredCount results (filtered from $originalCount results)");
      }
      else {
        $this->io->success("Found $filteredCount results");
      }
    }

    return Command::SUCCESS;
  }

  /**
   * Handle interrupt signals (SIGINT/SIGTERM).
   *
   * Sets interrupted flag on first signal, forces exit on second signal.
   *
   * @param int $signo Signal number received
   */
  public static function handleSignal(int $signo): void
  {
    if (self::$interrupted) {
      // Second signal - force exit immediately.
      exit(1);
    }
    self::$interrupted = TRUE;
  }

  /**
   * Check if execution has been interrupted.
   *
   * Processes pending signals and returns interrupt status.
   *
   * @return bool TRUE if interrupted, FALSE otherwise
   */
  public static function isInterrupted(): bool
  {
    // Process any pending signals.
    if (function_exists('pcntl_signal_dispatch')) {
      pcntl_signal_dispatch();
    }
    return self::$interrupted;
  }

  /**
   * Register signal handlers for graceful interruption.
   *
   * Sets up SIGINT and SIGTERM handlers if pcntl extension is available.
   */
  protected function registerSignalHandlers(): void
  {
    if (function_exists('pcntl_signal')) {
      pcntl_signal(SIGINT, [self::class, 'handleSignal']);
      pcntl_signal(SIGTERM, [self::class, 'handleSignal']);
    }
  }

  /**
   * Create a progress bar for search operations.
   *
   * @param array $options Query options containing time range
   * @return ProgressBar|null Progress bar instance or NULL if disabled
   */
  protected function createSearchProgressBar(array $options): ?ProgressBar
  {
    if (!$this->config->isProgressEnabled() || $this->jsonMode) {
      return NULL;
    }

    $totalSeconds = strtotime($options['time']['end_time']) - strtotime($options['time']['start_time']);
    $progressBar = new ProgressBar($this->io, $totalSeconds);
    $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %message%');
    $progressBar->setMessage('');
    $progressBar->start();

    return $progressBar;
  }

  /**
   * Get standard progress callback for API search.
   *
   * @param ProgressBar|null $progressBar Progress bar to update
   * @param array $options Query options
   * @return callable|null Progress callback function
   */
  protected function getProgressCallback(?ProgressBar $progressBar, array $options): ?callable
  {
    if (!$progressBar) {
      return NULL;
    }

    return function($pageNum, $pageLogs, $newLogsAdded, $duplicatesFound, $totalResults) use ($progressBar, $options) {
      if (!empty($pageLogs)) {
        $oldestTime = $pageLogs[0]['time'] ?? NULL;
        if ($oldestTime) {
          $currentEpoch = strtotime($oldestTime);
          $endEpoch = strtotime($options['time']['end_time']);
          $coveredSeconds = $endEpoch - $currentEpoch;
          $progressBar->setProgress($coveredSeconds);
        }
      }
      else {
        $progressBar->advance();
      }
      $progressBar->setMessage("($totalResults results)");
    };
  }

  /**
   * Finish progress bar display.
   *
   * @param ProgressBar|null $progressBar Progress bar to finish
   */
  protected function finishProgressBar(?ProgressBar $progressBar): void
  {
    if ($progressBar) {
      $progressBar->finish();
      $this->io->newLine();
    }
  }

  /**
   * Execute searchLogs with automatic progress bar and database storage.
   *
   * Implements universal sync: fetches ALL logs for time range from database or API,
   * with automatic gap detection and incremental updates.
   *
   * @param string $query The search query (unused in universal sync, kept for compatibility)
   * @param array $options Query options containing time range
   * @param bool $showCacheMessage Whether to show database hit message (default: TRUE)
   * @return array Array of log entries
   */
  protected function searchLogsWithProgress(string $query, array $options, bool $showCacheMessage = TRUE): array
  {
    // Convert time range to ISO 8601 for database queries.
    $startTime = gmdate('Y-m-d\TH:i:s\Z', strtotime($options['time']['start_time']));
    $endTime = gmdate('Y-m-d\TH:i:s\Z', strtotime($options['time']['end_time']));

    // Detect gaps in database coverage.
    $gapAnalysis = $this->databaseService->detectGaps($startTime, $endTime);

    // No gaps - return existing database data.
    if (empty($gapAnalysis['gaps'])) {
      $results = $this->databaseService->getLogs($startTime, $endTime);
      if ($showCacheMessage && !$this->jsonMode) {
        $this->io->note("Using database results (" . count($results) . " logs)");
      }
      return $results;
    }

    // We have gaps - fetch missing data from API.
    $gapResults = [];
    foreach ($gapAnalysis['gaps'] as $gap) {
      if (!$this->jsonMode && $showCacheMessage) {
        $this->io->writeln("<comment>Fetching gap: {$gap['start']} to {$gap['end']} ({$gap['reason']})</comment>");
      }

      $progressBar = $this->createSearchProgressBar($options);
      $gapData = $this->apiService->searchLogs(
        $gap['start'],
        $gap['end'],
        $this->getProgressCallback($progressBar, $options)
      );
      $this->finishProgressBar($progressBar);

      // Save gap data to database.
      $this->databaseService->insertLogs($gapData);

      $gapResults = array_merge($gapResults, $gapData);
    }

    // Get complete dataset from database (now includes gap data).
    $results = $this->databaseService->getLogs($startTime, $endTime);

    if ($showCacheMessage && !$this->jsonMode && $gapAnalysis['has_data']) {
      $this->io->note("Merged " . count($gapResults) . " new logs with existing database data (total: " . count($results) . " logs)");
    }

    return $results;
  }


  /**
   * Build the search query (must be implemented by child classes).
   *
   * @param array $options Parsed command options
   * @return string Search query string for SolarWinds API
   */
  abstract protected function buildSearchQuery(array $options): string;

  /**
   * Parse script-specific options (default implementation returns empty array).
   *
   * Child classes override this to extract command-specific arguments and options.
   *
   * @param InputInterface $input Command input interface
   * @return array Command-specific options
   */
  protected function parseScriptSpecificOptions(InputInterface $input): array
  {
    return [];
  }

  /**
   * Validate the query and options (default implementation does no validation).
   *
   * Child classes override this to implement command-specific validation logic.
   *
   * @param string $query Built search query
   * @param array $options Parsed command options
   * @throws \InvalidArgumentException If validation fails
   */
  protected function validateQuery(string $query, array $options): void
  {
    // Default implementation performs no additional validation.
  }

  /**
   * Extract search term from command options for highlighting.
   *
   * Attempts to identify a simple search term from command-specific options
   * for result highlighting purposes.
   *
   * @param array $options Parsed command options
   * @return string|null Search term for highlighting, or NULL if none applicable
   */
  protected function extractSearchTerm(array $options): ?string
  {
    $scriptSpecific = $options['script_specific'] ?? [];

    // BotCommand: use the agent argument
    if (isset($scriptSpecific['agent'])) {
      return $scriptSpecific['agent'];
    }

    // SearchCommand: use the query if it's simple text (not JSON)
    if (isset($scriptSpecific['query'])) {
      $query = $scriptSpecific['query'];

      // Skip highlighting for complex JSON queries
      if (strpos($query, '{') !== FALSE || strpos($query, '}') !== FALSE) {
        return NULL;
      }

      // Return simple text queries for highlighting
      return $query;
    }

    return NULL;
  }

  /**
   * Output debug information to stderr.
   *
   * Debug output should always go to stderr to avoid polluting stdout,
   * especially when using --json mode where stdout must be valid JSON.
   *
   * @param string $message Debug message to output
   */
  protected function debugOutput(string $message): void
  {
    fwrite(STDERR, $message . "\n");
  }

  /**
   * Output results as JSON to stdout.
   *
   * @param string $status Status: "success" or "error"
   * @param mixed $data Data to output (for success) or NULL (for error)
   * @param array $metadata Additional metadata including error info for failures
   */
  protected function outputJson(string $status, $data, array $metadata = []): void
  {
    $output = ['status' => $status];

    if ($status === 'error') {
      $output['error'] = $metadata['error'] ?? ['message' => 'Unknown error'];
    }
    else {
      $output['data'] = $data;
      if (!empty($metadata)) {
        $output['metadata'] = $metadata;
      }
    }

    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
  }

}
