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
use SolarWinds\Services\CacheService;
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
  protected CacheService $cacheService;
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
  protected array $executionMetadata = [];

  /**
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

  public function __construct()
  {
    // Initialize config FIRST, before parent constructor which calls configure().
    $this->config = new ConfigurationService();

    parent::__construct();

    $this->apiService = new ApiService($this->config);
    $this->displayService = new DisplayService($this->config);
    $this->cacheService = new CacheService();
  }

  /**
   * Configure common options for all SolarWinds commands.
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
      'cache' => 'Show cache status',
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
      ->addOption('no-cache', NULL, InputOption::VALUE_NONE, 'Skip cache and force fresh query')
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
    $displayFlags = ['status', 'host', 'ua', 'ip', 'country', 'region', 'cache', 'drupal'];
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
   */
  protected function parseFilterOptions(InputInterface $input): array
  {
    $cachedOption = $input->getOption('cached');
    $useCached = $cachedOption !== FALSE;
    $cacheOptions = ['infinite' => FALSE, 'seconds' => NULL];

    if ($useCached && $cachedOption !== NULL) {
      // Parse cache value using CacheService.
      try {
        $cacheOptions = $this->cacheService->parseCacheOptions((string) $cachedOption);
      }
      catch (\InvalidArgumentException $e) {
        throw new \InvalidArgumentException("Cache option error: " . $e->getMessage());
      }
    }

    return [
      'min_count' => (int) $input->getOption('min-count'),
      'use_cached' => $useCached,
      'cache_infinite' => $cacheOptions['infinite'],
      'cache_seconds' => $cacheOptions['seconds'],
      'limit' => (int) $input->getOption('limit'),
      'debug' => $input->getOption('debug'),
      'json' => $input->getOption('json'),
      'no_group' => $input->getOption('no-group'),
      'no_cache' => $input->getOption('no-cache'),
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
   * Format applied filters for display.
   */
  protected function formatAppliedFilters(array $options): string
  {
    $appliedFilters = [];

    if (!empty($options['filters']['country_filter'])) {
      $appliedFilters[] = "--country-filter=" . $options['filters']['country_filter'];
    }

    if (!empty($options['filters']['city_filter'])) {
      $appliedFilters[] = "--city-filter=" . $options['filters']['city_filter'];
    }

    if (!empty($options['filters']['status_code_filter'])) {
      $appliedFilters[] = "--status-code-filter=" . $options['filters']['status_code_filter'];
    }

    if (!empty($options['filters']['user_agent_filter'])) {
      $appliedFilters[] = "--user-agent-filter=" . $options['filters']['user_agent_filter'];
    }

    if (!empty($options['filters']['path_filter'])) {
      $appliedFilters[] = "--path-filter=" . $options['filters']['path_filter'];
    }

    if (!empty($options['filters']['ip_filter'])) {
      $appliedFilters[] = "--ip-filter=" . $options['filters']['ip_filter'];
    }

    return empty($appliedFilters) ? '' : implode(' ', $appliedFilters);
  }

  /**
   * Apply client-side regex filters to results.
   *
   * Filters logs based on --filter options in format "field:regex".
   * Multiple filters are AND'd together (all must match).
   * Field names can be specified with or without 'json.' prefix.
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
   * Execute the search with progress feedback.
   */
  protected function executeSearch(string $query, array $options): int
  {
    // Register signal handlers for graceful interruption.
    $this->registerSignalHandlers();

    // Check if debug is enabled via config or command line.
    $debugMode = $options['filters']['debug'] || $this->config->isDebugEnabled();
    $progressMode = $this->config->isProgressEnabled();

    // Generate cache key for this query (time-arg agnostic for cross-timeframe reuse).
    $scriptName = $this->getName() ?? 'unknown';
    $siteArgs = implode(',', array_map(fn($site) => "--$site", $options['sites']));
    $cacheKey = $this->cacheService->generateCacheKey($scriptName, $query, $siteArgs);

    if ($debugMode) {
      $this->debugOutput('');
      $this->debugOutput('=== Debug Information ===');
      $this->debugOutput("Query: $query");
      $this->debugOutput("Time range: {$options['time']['human_readable']}");
      $this->debugOutput("Start time: {$options['time']['start_time']}");
      $this->debugOutput("End time: {$options['time']['end_time']}");
      $this->debugOutput("API Base URL: " . $this->config->getApiBaseUrl());
      $this->debugOutput("Progress mode: " . ($progressMode ? 'enabled' : 'disabled'));
      $this->debugOutput("Cache key: $cacheKey");
      $this->debugOutput("Use cached: " . ($options['filters']['use_cached'] ? 'yes' : 'no'));
      $this->debugOutput('');
    }

    // Always check for fresh cache first (unless --no-cache is used).
    if (!$options['filters']['no_cache']) {
      $currentTimeArg = $this->extractTimeArgFromHumanReadable($options['time']['human_readable']);

      // If --cached flag is used, it modifies freshness checking behavior.
      if ($this->cacheService->isCacheFresh(
        $cacheKey,
        $currentTimeArg,
        $options['filters']['cache_infinite'],
        $options['filters']['cache_seconds']
      )) {
        $cacheAge = $this->cacheService->getCacheAge($cacheKey);
        $ageText = $cacheAge ? "($cacheAge old)" : "(age unknown)";

        if (!$this->jsonMode) {
          $this->io->note("Using cached results $ageText - use --no-cache to force fresh query");
        }

        $cachedResults = $this->cacheService->loadFromCache($cacheKey);

        if ($cachedResults !== NULL) {
          // Apply client-side filters.
          $originalCount = count($cachedResults);
          $cachedResults = $this->filterResults($cachedResults, $options);
          $filteredCount = count($cachedResults);

          // Extract search term for highlighting.
          $searchTerm = $this->extractSearchTerm($options);

          if ($this->jsonMode) {
            // JSON output mode.
            $formattedResults = $this->displayService->formatResultsForJson(
              $cachedResults,
              $options['display'],
              $options['filters'],
              $searchTerm
            );
            $this->outputJson('success', $formattedResults, [
              'query' => $query,
              'time_range' => $options['time'],
              'cache' => [
                'used' => TRUE,
                'age' => $cacheAge,
              ],
              'filters' => [
                'original_count' => $originalCount,
                'filtered_count' => $filteredCount,
              ]
            ]);
          }
          else {
            // Regular display mode.
            $this->displayService->displayResults($cachedResults, $options['display'], $this->io, $debugMode, $options['filters'], $searchTerm);

            // Show filtered count if filtering was applied.
            if (!empty($options['filters']['client_side_filters']) && $originalCount !== $filteredCount) {
              $this->io->success("Found $filteredCount results (filtered from $originalCount results, from cache)");
            }
            else {
              $this->io->success("Found $filteredCount results (from cache)");
            }
          }
          return Command::SUCCESS;
        }
      }
    }

    // Suppress interactive output in JSON mode.
    if (!$this->jsonMode) {
      $this->io->section('Searching SolarWinds Logs');
      $this->io->text("Time range: {$options['time']['human_readable']}");
      $this->io->text("Query: $query");

      // Display applied filters if any.
      $appliedFilters = $this->formatAppliedFilters($options);
      if (!empty($appliedFilters)) {
        $this->io->text("Applied filters: $appliedFilters");
      }
    }

    // Create progress bar only if progress is enabled and not in JSON mode.
    $progressBar = NULL;
    if ($progressMode && !$this->jsonMode) {
      $totalSeconds = strtotime($options['time']['end_time']) - strtotime($options['time']['start_time']);
      $progressBar = new ProgressBar($this->io, $totalSeconds);
      $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %message%');
      $progressBar->setMessage('');
      $progressBar->start();
    }
    try {
      $searchStartTime = time();

      // Execute API search with enhanced progress updates.
      $results = $this->apiService->searchLogs(
        $query,
        $options['time']['start_time'],
        $options['time']['end_time'],
        function($pageNum, $pageLogs, $newLogsAdded, $duplicatesFound, $totalResults) use ($progressBar, $debugMode, $options, $query) {
          // Enhanced debug output for first page.
          if ($debugMode && $pageNum === 1) {
            $this->debugOutput('');
            $this->debugOutput('=== API Call Debug ===');
            $this->debugOutput("First page received: " . count($pageLogs) . " logs");
            if (!empty($pageLogs)) {
              $this->debugOutput("Sample log structure: " . json_encode(array_keys($pageLogs[0] ?? []), JSON_PRETTY_PRINT));
            }
            else {
              $this->debugOutput("No logs returned from API");
              $this->debugOutput("This suggests the query may not match any data in the time range");
            }
            $this->debugOutput('');
          }

          if ($progressBar) {
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
          }
          elseif ($debugMode) {
            $this->debugOutput("Page $pageNum: Added $newLogsAdded new logs, found $duplicatesFound duplicates ($totalResults total unique)");
          }
        },
        // Add debug callback for detailed API logging.
        $debugMode ? function(string $message) {
          $this->debugOutput($message);
        } : NULL
      );

      $searchDuration = time() - $searchStartTime;

      // Check if search was interrupted.
      $interrupted = self::isInterrupted();
      if ($interrupted) {
        if ($progressBar) {
          $progressBar->finish();
          $this->io->newLine(2);
        }
        if (!$this->jsonMode) {
          $this->io->error("Search interrupted by user. Displaying partial results (" . count($results) . " found so far).");
        }
        // Continue to display results and return success - we got some data.
      }

      // Clear progress display if it was shown.
      if ($progressBar) {
        $progressBar->finish();
        $this->io->newLine(2);
      }

      // Determine if we should save to cache based on new rules:
      // 1. Query took >= 5 seconds (lowered from 60)
      // 2. Time range >= 1 hour (always cache longer queries)
      // 3. --cached flag was used (explicit user request)
      $shouldCache = FALSE;
      $cacheReason = '';

      if ($searchDuration >= 5) {
        $shouldCache = TRUE;
        $cacheReason = "query took {$searchDuration}s";
      }

      // Check if time range is >= 1 hour (3600 seconds)
      $currentTimeArg = $this->extractTimeArgFromHumanReadable($options['time']['human_readable']);
      $timeRangeSeconds = TimeSpecifications::convertToSeconds($currentTimeArg);
      if ($timeRangeSeconds >= 3600) {
        $shouldCache = TRUE;
        if (!$cacheReason) {
          $cacheReason = "time range >= 1h";
        }
      }

      // Always cache if --cached flag was used
      if ($options['filters']['use_cached']) {
        $shouldCache = TRUE;
        if (!$cacheReason) {
          $cacheReason = "--cached flag used";
        }
      }

      if ($shouldCache) {
        if ($this->cacheService->saveToCache($cacheKey, $results, $searchDuration)) {
          if (!$this->jsonMode) {
            $this->io->note("Results saved to cache ($cacheReason)");
          }
        }
      }

      // Apply client-side filters.
      $originalCount = count($results);
      $results = $this->filterResults($results, $options);
      $filteredCount = count($results);

      // Extract search term for highlighting.
      $searchTerm = $this->extractSearchTerm($options);

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
          'display_options' => array_keys(array_filter($options['display'])),
          'cache' => [
            'used' => FALSE,
            'saved' => $shouldCache,
            'reason' => $cacheReason ?: NULL,
          ],
          'filters' => [
            'original_count' => $originalCount,
            'filtered_count' => $filteredCount,
            'client_side' => $options['filters']['client_side_filters'] ?: [],
          ],
          'execution_time' => $searchDuration,
          'interrupted' => $interrupted,
        ]);
      }
      else {
        // Regular display mode.
        $this->displayService->displayResults($results, $options['display'], $this->io, $debugMode, $options['filters'], $searchTerm);

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
    catch (GuzzleException $e) {
      if ($progressBar) {
        $progressBar->finish();
        $this->io->newLine();
      }

      // Provide user-friendly error messages based on error type.
      $errorMessage = $this->getErrorMessage($e);

      if ($this->jsonMode) {
        $this->outputJson('error', NULL, [
          'error' => [
            'message' => $errorMessage,
            'type' => get_class($e),
          ]
        ]);
      }
      else {
        $this->io->error($errorMessage);
      }
      return Command::FAILURE;
    }
  }

  /**
   * Extract time argument from human readable string for cache key generation.
   */
  protected function extractTimeArgFromHumanReadable(string $humanReadable): string
  {
    // Extract the time part from strings like "last 1h", "last 1d (default)", etc.
    if (preg_match('/last (\w+)/', $humanReadable, $matches)) {
      return $matches[1];
    }

    return '1d'; // Default fallback.
  }

  /**
   * Handle interrupt signals (SIGINT/SIGTERM).
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
   * Execute searchLogs with automatic progress bar and cache handling.
   *
   * This is a convenience wrapper that eliminates boilerplate by automatically:
   * 1. Checking cache for existing results
   * 2. Creating progress bar and fetching from API if cache miss
   * 3. Saving results to cache
   * 4. Displaying cache usage message when appropriate
   *
   * @param string $query The search query
   * @param array $options Query options containing time range
   * @param bool $showCacheMessage Whether to show cache hit message (default: TRUE)
   * @return array Array of log entries
   */
  protected function searchLogsWithProgress(string $query, array $options, bool $showCacheMessage = TRUE): array
  {
    // Try to load from cache first.
    $cacheUsed = FALSE;
    $cacheAge = NULL;
    $results = $this->tryLoadFromCache($query, $options, $cacheUsed, $cacheAge);

    // If cache hit, show message and return.
    if ($results !== NULL) {
      if ($showCacheMessage && !$this->jsonMode) {
        $ageText = $cacheAge ? "($cacheAge old)" : "(age unknown)";
        $this->io->note("Using cached results $ageText - use --no-cache to force fresh query");
      }
      return $results;
    }

    // Check if we can use incremental cache update.
    $incrementalResults = $this->tryIncrementalCacheUpdate($query, $options, $showCacheMessage);
    if ($incrementalResults !== NULL) {
      return $incrementalResults;
    }

    // Cache miss - fetch from API with progress bar.
    $progressBar = $this->createSearchProgressBar($options);
    $results = $this->apiService->searchLogs(
      $query,
      $options['time']['start_time'],
      $options['time']['end_time'],
      $this->getProgressCallback($progressBar, $options)
    );
    $this->finishProgressBar($progressBar);

    // Save to cache.
    $this->saveResultsToCache($query, $options, $results, 0);

    return $results;
  }

  /**
   * Try to use incremental cache update.
   *
   * @param string $query The search query
   * @param array $options Query options
   * @param bool $showCacheMessage Whether to show cache messages
   * @return array|null Merged results or NULL if incremental not applicable
   */
  protected function tryIncrementalCacheUpdate(string $query, array $options, bool $showCacheMessage = TRUE): ?array
  {
    // Skip if --no-cache flag is set.
    if ($options['filters']['no_cache']) {
      return NULL;
    }

    // Generate cache key (time-arg agnostic for cross-timeframe reuse).
    $scriptName = $this->getName() ?? 'unknown';
    $siteArgs = implode(',', array_map(fn($site) => "--$site", $options['sites']));
    $cacheKey = $this->cacheService->generateCacheKey($scriptName, $query, $siteArgs);

    // Calculate time range and convert to ISO 8601.
    $startTimeRaw = $options['time']['start_time'];
    $endTimeRaw = $options['time']['end_time'];

    $startTimeTimestamp = strtotime($startTimeRaw);
    $endTimeTimestamp = strtotime($endTimeRaw);
    $timeRangeSeconds = $endTimeTimestamp - $startTimeTimestamp;

    $startTime = date('Y-m-d\TH:i:s\Z', $startTimeTimestamp);
    $endTime = date('Y-m-d\TH:i:s\Z', $endTimeTimestamp);

    // Check if incremental update should be used.
    if (!$this->cacheService->shouldUseIncrementalUpdate($cacheKey, $startTime, $endTime, $timeRangeSeconds)) {
      return NULL;
    }

    // Load cached results.
    $cachedResults = $this->cacheService->loadFromCache($cacheKey);
    if ($cachedResults === NULL) {
      return NULL;
    }

    // Calculate gap queries (before and after cached range).
    $gaps = $this->cacheService->calculateGapQueries($cacheKey, $startTime, $endTime);
    if ($gaps['before'] === NULL && $gaps['after'] === NULL) {
      // No gaps to fetch - cached range fully covers query.
      return $cachedResults;
    }

    // Fetch gap data.
    $gapResults = [];
    $totalGapSeconds = 0;

    if ($gaps['before'] !== NULL) {
      $gapStart = strtotime($gaps['before']['start_time']);
      $gapEnd = strtotime($gaps['before']['end_time']);
      $totalGapSeconds += ($gapEnd - $gapStart);

      $progressBar = $this->createSearchProgressBar($options);
      $beforeResults = $this->apiService->searchLogs(
        $query,
        $gaps['before']['start_time'],
        $gaps['before']['end_time'],
        $this->getProgressCallback($progressBar, $options)
      );
      $this->finishProgressBar($progressBar);
      $gapResults = array_merge($gapResults, $beforeResults);
    }

    if ($gaps['after'] !== NULL) {
      $gapStart = strtotime($gaps['after']['start_time']);
      $gapEnd = strtotime($gaps['after']['end_time']);
      $totalGapSeconds += ($gapEnd - $gapStart);

      $progressBar = $this->createSearchProgressBar($options);
      $afterResults = $this->apiService->searchLogs(
        $query,
        $gaps['after']['start_time'],
        $gaps['after']['end_time'],
        $this->getProgressCallback($progressBar, $options)
      );
      $this->finishProgressBar($progressBar);
      $gapResults = array_merge($gapResults, $afterResults);
    }

    // Show debug message.
    if ($showCacheMessage && !$this->jsonMode) {
      $cacheAge = $this->cacheService->getCacheAge($cacheKey);

      // Format gap duration appropriately.
      if ($totalGapSeconds < 60) {
        $gapDisplay = $totalGapSeconds . 's';
      } elseif ($totalGapSeconds < 3600) {
        $gapMinutes = round($totalGapSeconds / 60);
        $gapDisplay = $gapMinutes . 'm';
      } else {
        $gapHours = round($totalGapSeconds / 3600, 1);
        $gapDisplay = $gapHours . 'h';
      }

      $gapDesc = [];
      if ($gaps['before'] !== NULL) {
        $gapDesc[] = 'older data';
      }
      if ($gaps['after'] !== NULL) {
        $gapDesc[] = 'recent data';
      }
      $gapDescStr = implode(' + ', $gapDesc);

      $this->io->writeln("<comment>[CACHE] Incremental update: Using cached data ($cacheAge old) + fetching {$gapDisplay} gap ({$gapDescStr})</comment>");
    }

    // Merge and deduplicate (order matters: before + cached + after).
    $mergedResults = $this->cacheService->mergeAndDeduplicateResults($cachedResults, $gapResults);

    // Show merge statistics.
    if ($showCacheMessage && !$this->jsonMode) {
      $cachedCount = count($cachedResults);
      $freshCount = count($gapResults);
      $mergedCount = count($mergedResults);
      $duplicates = ($cachedCount + $freshCount) - $mergedCount;

      $this->io->writeln("<comment>[CACHE] Merged {$cachedCount} cached + {$freshCount} fresh = {$mergedCount} total ({$duplicates} duplicates removed)</comment>");
    }

    // Save merged results back to cache with updated time range.
    $this->saveResultsToCache($query, $options, $mergedResults, 0);

    return $mergedResults;
  }

  /**
   * Try to load results from cache.
   *
   * @param string $query The search query
   * @param array $options Query options
   * @param bool &$cacheUsed Output parameter set to TRUE if cache was used
   * @param string|null &$cacheAge Output parameter set to cache age string
   * @return array|null Cached results or NULL if not available
   */
  protected function tryLoadFromCache(string $query, array $options, bool &$cacheUsed, ?string &$cacheAge): ?array
  {
    // Don't use cache if --no-cache flag is set.
    if ($options['filters']['no_cache']) {
      return NULL;
    }

    // Generate cache key (time-arg agnostic for cross-timeframe reuse).
    $scriptName = $this->getName() ?? 'unknown';
    $siteArgs = implode(',', array_map(fn($site) => "--$site", $options['sites']));
    $cacheKey = $this->cacheService->generateCacheKey($scriptName, $query, $siteArgs);

    // Check if cache is fresh.
    $currentTimeArg = $this->extractTimeArgFromHumanReadable($options['time']['human_readable']);

    if (!$this->cacheService->isCacheFresh(
      $cacheKey,
      $currentTimeArg,
      $options['filters']['cache_infinite'],
      $options['filters']['cache_seconds']
    )) {
      return NULL;
    }

    // Load from cache.
    $results = $this->cacheService->loadFromCache($cacheKey);
    if ($results !== NULL) {
      $cacheUsed = TRUE;
      $cacheAge = $this->cacheService->getCacheAge($cacheKey);
      return $results;
    }

    return NULL;
  }

  /**
   * Save results to cache.
   *
   * @param string $query The search query
   * @param array $options Query options
   * @param array $results Results to cache
   * @param int $searchDuration Search duration in seconds (0 for immediate caching)
   */
  protected function saveResultsToCache(string $query, array $options, array $results, int $searchDuration = 0): void
  {
    $scriptName = $this->getName() ?? 'unknown';
    $siteArgs = implode(',', array_map(fn($site) => "--$site", $options['sites']));
    $cacheKey = $this->cacheService->generateCacheKey($scriptName, $query, $siteArgs);

    // Calculate time range in seconds for enhanced metadata.
    $startTimeRaw = $options['time']['start_time'];
    $endTimeRaw = $options['time']['end_time'];

    // Convert relative time strings to ISO 8601 timestamps.
    $startTimeTimestamp = strtotime($startTimeRaw);
    $endTimeTimestamp = strtotime($endTimeRaw);
    $timeRangeSeconds = $endTimeTimestamp - $startTimeTimestamp;

    $startTime = date('Y-m-d\TH:i:s\Z', $startTimeTimestamp);
    $endTime = date('Y-m-d\TH:i:s\Z', $endTimeTimestamp);

    // Generate query hash for validation.
    $queryHash = md5($query . implode(',', $options['sites']));

    $this->cacheService->saveToCache(
      $cacheKey,
      $results,
      $searchDuration,
      $startTime,
      $endTime,
      $timeRangeSeconds,
      $queryHash,
      $scriptName
    );
  }

  /**
   * Build the search query (must be implemented by child classes).
   */
  abstract protected function buildSearchQuery(array $options): string;

  /**
   * Parse script-specific options (default implementation returns empty array).
   */
  protected function parseScriptSpecificOptions(InputInterface $input): array
  {
    return [];
  }

  /**
   * Validate the query and options (default implementation does no validation).
   */
  protected function validateQuery(string $query, array $options): void
  {
    // Default implementation performs no additional validation.
  }

  /**
   * Extract search term from command options for highlighting.
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

  /**
   * Get user-friendly error message based on exception type and status code.
   */
  protected function getErrorMessage(GuzzleException $e): string
  {
    // Check if the exception has a response (HTTP error).
    if (method_exists($e, 'hasResponse') && $e->hasResponse()) {
      $response = $e->getResponse();
      $statusCode = $response->getStatusCode();

      // 5xx errors - Server-side issues.
      if ($statusCode >= 500 && $statusCode < 600) {
        return sprintf(
          "SolarWinds API is experiencing server issues (HTTP %d). Please try again later or check their status page.",
          $statusCode
        );
      }

      // 401/403 - Authentication/Authorization issues.
      if ($statusCode === 401 || $statusCode === 403) {
        return sprintf(
          "Authentication failed (HTTP %d). Please check your API token configuration.",
          $statusCode
        );
      }

      // 400 - Bad Request (likely query syntax issue).
      if ($statusCode === 400) {
        return sprintf(
          "Invalid request (HTTP %d): %s. Check your query syntax.",
          $statusCode,
          $e->getMessage()
        );
      }

      // 429 - Rate limiting.
      if ($statusCode === 429) {
        return "Rate limit exceeded (HTTP 429). Please wait a few minutes before trying again.";
      }

      // Other 4xx errors.
      if ($statusCode >= 400 && $statusCode < 500) {
        return sprintf(
          "Client error (HTTP %d): %s",
          $statusCode,
          $e->getMessage()
        );
      }

      // Other HTTP errors.
      return sprintf(
        "API request failed (HTTP %d): %s",
        $statusCode,
        $e->getMessage()
      );
    }

    // Network/connectivity errors (no HTTP response).
    $message = $e->getMessage();
    if (stripos($message, 'timeout') !== FALSE) {
      return "Request timed out. The SolarWinds API may be slow or unreachable.";
    }

    if (stripos($message, 'connection') !== FALSE || stripos($message, 'resolve') !== FALSE) {
      return "Network error: Unable to connect to SolarWinds API. Check your internet connection.";
    }

    // Generic fallback.
    return "API request failed: " . $message;
  }
}
