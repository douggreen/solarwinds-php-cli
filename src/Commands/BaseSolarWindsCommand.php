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
      'vars' => 'Show variable replacements (all variables, or specify: file,line,function)'
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
      ->addOption('no-group', NULL, InputOption::VALUE_NONE, 'Disable automatic time-based regrouping for single result groups')

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
      $this->io->error($e->getMessage());
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
      'no_group' => $input->getOption('no-group'),
      'no_cache' => $input->getOption('no-cache'),
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
      $this->io->section('Client-Side Filtering');
      $this->io->text("Applying filters:");
      foreach ($filters as $filter) {
        $this->io->text("  - $filter");
      }
      if (!empty($logs)) {
        $this->io->text("Sample log fields: " . implode(', ', array_keys($logs[0])));
      }
      $this->io->newLine();
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
          $this->io->text("<info>MATCHED:</info> $host - $uri");

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
            $this->io->text("  Filter: $filter");
            $this->io->text("  Value: " . substr($value, 0, 200));
            if ($decodedValue !== $value) {
              $this->io->text("  Decoded: " . substr($decodedValue, 0, 200));
            }
          }
          $this->io->newLine();
        }
      }
    }

    if ($debugMode && !empty($filters)) {
      $this->io->text("Client-side filtering: " . count($logs) . " results -> " . count($filtered) . " results after filters");
      $this->io->newLine();
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

    // Generate cache key for this query.
    $scriptName = $this->getName() ?? 'unknown';
    $timeArg = $options['time']['human_readable'];
    $siteArgs = implode(',', array_map(fn($site) => "--$site", $options['sites']));
    $cacheKey = $this->cacheService->generateCacheKey($scriptName, $query, $timeArg, $siteArgs);

    if ($debugMode) {
      $this->io->section('Debug Information');
      $this->io->text("Query: $query");
      $this->io->text("Time range: {$options['time']['human_readable']}");
      $this->io->text("Start time: {$options['time']['start_time']}");
      $this->io->text("End time: {$options['time']['end_time']}");
      $this->io->text("API Base URL: " . $this->config->getApiBaseUrl());
      $this->io->text("Progress mode: " . ($progressMode ? 'enabled' : 'disabled'));
      $this->io->text("Cache key: $cacheKey");
      $this->io->text("Use cached: " . ($options['filters']['use_cached'] ? 'yes' : 'no'));
      $this->io->newLine();
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
        $this->io->note("Using cached results $ageText - use --no-cache to force fresh query");
        $cachedResults = $this->cacheService->loadFromCache($cacheKey);

        if ($cachedResults !== NULL) {
          // Apply client-side filters.
          $originalCount = count($cachedResults);
          $cachedResults = $this->filterResults($cachedResults, $options);
          $filteredCount = count($cachedResults);

          // Extract search term for highlighting.
          $searchTerm = $this->extractSearchTerm($options);
          $this->displayService->displayResults($cachedResults, $options['display'], $this->io, $debugMode, $options['filters'], $searchTerm);

          // Show filtered count if filtering was applied.
          if (!empty($options['filters']['client_side_filters']) && $originalCount !== $filteredCount) {
            $this->io->success("Found $filteredCount results (filtered from $originalCount results, from cache)");
          }
          else {
            $this->io->success("Found $filteredCount results (from cache)");
          }
          return Command::SUCCESS;
        }
      }
    }

    $this->io->section('Searching SolarWinds Logs');
    $this->io->text("Time range: {$options['time']['human_readable']}");
    $this->io->text("Query: $query");

    // Display applied filters if any.
    $appliedFilters = $this->formatAppliedFilters($options);
    if (!empty($appliedFilters)) {
      $this->io->text("Applied filters: $appliedFilters");
    }

    // Create progress bar only if progress is enabled.
    // Show progress if enabled.
    $progressBar = NULL;
    if ($progressMode) {
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
            $this->io->section('API Call Debug');
            $this->io->text("First page received: " . count($pageLogs) . " logs");
            if (!empty($pageLogs)) {
              $this->io->text("Sample log structure: " . json_encode(array_keys($pageLogs[0] ?? []), JSON_PRETTY_PRINT));
            }
            else {
              $this->io->text("No logs returned from API");
              $this->io->text("This suggests the query may not match any data in the time range");
            }
            $this->io->newLine();
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
            $this->io->text("Page $pageNum: Added $newLogsAdded new logs, found $duplicatesFound duplicates ($totalResults total unique)");
          }
        },
        // Add debug callback for detailed API logging.
        $debugMode ? function(string $message) {
          $this->io->text($message);
        } : NULL
      );

      $searchDuration = time() - $searchStartTime;

      // Check if search was interrupted.
      if (self::isInterrupted()) {
        if ($progressBar) {
          $progressBar->finish();
          $this->io->newLine(2);
        }
        $this->io->error("Search interrupted by user. Displaying partial results (" . count($results) . " found so far).");
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
          $this->io->note("Results saved to cache ($cacheReason)");
        }
      }

      // Apply client-side filters.
      $originalCount = count($results);
      $results = $this->filterResults($results, $options);
      $filteredCount = count($results);

      // Extract search term for highlighting.
      $searchTerm = $this->extractSearchTerm($options);

      // Display results.
      $this->displayService->displayResults($results, $options['display'], $this->io, $debugMode, $options['filters'], $searchTerm);

      // Show filtered count if filtering was applied.
      if (!empty($options['filters']['client_side_filters']) && $originalCount !== $filteredCount) {
        $this->io->success("Found $filteredCount results (filtered from $originalCount results)");
      }
      else {
        $this->io->success("Found $filteredCount results");
      }
      return Command::SUCCESS;

    }
    catch (GuzzleException $e) {
      if ($progressBar) {
        $progressBar->finish();
        $this->io->newLine();
      }
      $this->io->error("API request failed: " . $e->getMessage());
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
}
