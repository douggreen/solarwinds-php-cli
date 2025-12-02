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
 * - **Display Options**: Standardized display formatting via --cols option
 *   (status, host, country, etc.)
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
use SolarWinds\Services\LogQueryService;
use SolarWinds\Services\SyncTrackingService;
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
  protected LogQueryService $logQuery;
  protected SyncTrackingService $syncTracking;
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

  // Track whether we've already synced during confirmation to avoid double-syncing.
  protected bool $syncedInConfirmation = FALSE;

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
    $this->logQuery = new LogQueryService($this->databaseService);
    $this->syncTracking = new SyncTrackingService($this->config, $this->databaseService);
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

    $this
      // Time specification.
      // Note: Shorthand options like --2w, --15m are transformed to --time=2w, --time=15m
      // by SolarWindsApplication::run() for backward compatibility while keeping help clean.
      ->addOption('time', 't', InputOption::VALUE_REQUIRED,
        'Time range: 1h (last hour), 2d..1d (2 days ago to 1 day ago), 2024-11-20..2024-11-25 (date range), yesterday, all', NULL)

      // Site options.
      ->addOption('site', NULL, InputOption::VALUE_REQUIRED,
        'Filter by site(s) - comma-separated: abag,mtc,pba')
    ;

    // Display column options.
    $this
      ->addOption('cols', NULL, InputOption::VALUE_REQUIRED, 'Columns to display (comma-separated): status, host, ua, ip, country, region, path')
      ->addOption('drupal', NULL, InputOption::VALUE_NONE, 'Format PHP/Drupal watchdog errors with file and line grouping')
      ->addOption('vars', NULL, InputOption::VALUE_OPTIONAL, 'Show variable replacements (all variables, or specify: user,ip,post.name). Supports deep array references with dot notation (e.g., post.name, geoip.country_code2)', FALSE)

      // Other options.
      ->addOption('cached', NULL, InputOption::VALUE_OPTIONAL, 'Use cached results (optionally specify max age)', FALSE)
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Maximum number of results', 1000)
      ->addOption('debug', NULL, InputOption::VALUE_NONE, 'Enable debug output')
      ->addOption('json', NULL, InputOption::VALUE_NONE, 'Output results as JSON (suppresses progress and interactive messages)')
      ->addOption('no-group', NULL, InputOption::VALUE_NONE, 'Disable automatic time-based regrouping for single result groups')
      ->addOption('substitute-vars', NULL, InputOption::VALUE_NONE, 'Substitute variable placeholders in messages (e.g., %name, %choice) with their values')
      ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Automatically confirm all prompts (skip confirmations)')

      // Global filter options.
      ->addOption('filter-city', NULL, InputOption::VALUE_REQUIRED, 'Filter by city name (e.g., London, Paris)')
      ->addOption('filter-country', NULL, InputOption::VALUE_REQUIRED, 'Filter by country code (e.g., US, GB, FR)')
      ->addOption('filter-ip', NULL, InputOption::VALUE_REQUIRED, 'Filter by IP address (single IP or comma-separated list)')
      ->addOption('filter-min-count', NULL, InputOption::VALUE_REQUIRED, 'Show only results with count >= N [default: 1]', 1)
      ->addOption('filter-path', NULL, InputOption::VALUE_OPTIONAL, 'Filter by request path (e.g., /, /api, /admin)', FALSE)
      ->addOption('filter-status-code', NULL, InputOption::VALUE_REQUIRED, 'Filter by HTTP status code (e.g., 404, 500)')
      ->addOption('filter-user-agent', NULL, InputOption::VALUE_REQUIRED, 'Filter by user agent pattern (e.g., bot, mobile)')

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

      // Check if time range requires confirmation (months/years/all).
      if (!$this->confirmTimeRange($input, $queryOptions)) {
        return Command::SUCCESS; // User cancelled
      }

      // Build the SQL WHERE clause (implemented by child classes).
      $sqlQuery = $this->buildSearchQuery($queryOptions);

      // Validate the query and options.
      $this->validateQuery($sqlQuery, $queryOptions);

      // Execute the search and display results.
      return $this->executeSearch($sqlQuery, $queryOptions);

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
    $timeOption = $input->getOption('time');

    // Handle time option if provided.
    if ($timeOption) {
      // Try to convert time option (handles both pre-registered and dynamic ranges).
      $mapping = TimeSpecifications::convertToTimeRange($timeOption);

      if ($mapping !== NULL) {
        [$start, $end] = $mapping;

        // Generate human-readable description
        if ($timeOption === 'all') {
          // For 'all', get actual database coverage dates from sync_ranges (fast).
          // Using sync_ranges instead of logs table is 1000x faster (0.006s vs 10s).
          $coverage = $this->databaseService->execute(
            "SELECT MIN(start_time) as earliest, MAX(end_time) as latest
             FROM sync_ranges WHERE status = 'completed'"
          )->fetch();

          if ($coverage && $coverage['earliest'] && $coverage['latest']) {
            $earliest = date('M j, Y', strtotime($coverage['earliest']));
            $latest = date('M j, Y', strtotime($coverage['latest']));
            $humanReadable = "all database coverage ($earliest to $latest)";
          }
          else {
            $humanReadable = 'all database coverage (empty database)';
          }
        }
        elseif (strpos($timeOption, '..') !== FALSE) {
          $humanReadable = $timeOption;  // Range format: show as-is (e.g., "2d..1d")
        }
        else {
          $humanReadable = "last $timeOption";  // Relative format: add "last" prefix
        }

        return [
          'start_time' => $start,
          'end_time' => $end,
          'human_readable' => $humanReadable,
          'time_option' => $timeOption,
        ];
      }

      throw new \InvalidArgumentException("Invalid time option: $timeOption");
    }

    // Use default time if nothing specified.
    $defaultFlag = $this->defaultTime;
    $mapping = TimeSpecifications::convertToTimeRange($defaultFlag);

    if ($mapping !== NULL) {
      [$start, $end] = $mapping;
      return [
        'start_time' => $start,
        'end_time' => $end,
        'human_readable' => "last $defaultFlag (default)",
        'time_option' => $defaultFlag,
      ];
    }

    throw new \InvalidArgumentException("No valid time option specified");
  }

  /**
   * Confirm time range if it requires user confirmation.
   *
   * Prompts user to confirm large time ranges (months/years/all) to prevent
   * accidental expensive queries. Shows accurate information by detecting gaps
   * and estimating final record count after sync. Skipped if --yes flag is
   * provided or in JSON mode.
   *
   * @param InputInterface $input Command input interface
   * @param array $options Parsed query options
   * @return bool TRUE to proceed, FALSE to cancel
   */
  protected function confirmTimeRange(InputInterface $input, array $options): bool
  {
    // Skip confirmation in JSON mode or if --yes flag is provided.
    if ($this->jsonMode || $input->getOption('yes')) {
      return TRUE;
    }

    // Get the time value from --time option (all time specifications use this now).
    $timeFlag = $input->getOption('time');

    // If still null, it's using default time - no confirmation needed.
    if ($timeFlag === NULL) {
      return TRUE;
    }

    // Check if this time flag requires confirmation.
    if (!TimeSpecifications::requiresConfirmation($timeFlag)) {
      return TRUE;
    }

    // For months (NM), years (Ny), and 'all', show confirmation with accurate impact estimate.
    $startTime = gmdate('Y-m-d\TH:i:s\Z', strtotime($options['time']['start_time']));
    $endTime = gmdate('Y-m-d\TH:i:s\Z', strtotime($options['time']['end_time']));

    // Step 1: Detect missing ranges to understand what needs to be synced.
    // This is typically very fast (< 1 second), so no need for a progress message.
    $rangeAnalysis = $this->syncTracking->detectMissingRanges($startTime, $endTime);

    // Step 2: Calculate what needs to be synced.
    $missingRanges = $rangeAnalysis['ranges'] ?? [];
    $syncableRanges = array_filter($missingRanges, function($range) {
      return $range['reason'] !== 'beyond_retention';
    });

    // Step 3: If there are missing ranges, sync them BEFORE showing confirmation.
    // This ensures we show actual counts, not estimates.
    if (!empty($syncableRanges)) {
      // Calculate total duration of missing data.
      $totalMissingSeconds = 0;
      foreach ($syncableRanges as $range) {
        $rangeStart = strtotime($range['start']);
        $rangeEnd = strtotime($range['end']);
        $totalMissingSeconds += ($rangeEnd - $rangeStart);
      }
      $missingDays = $totalMissingSeconds / 86400;

      // Format duration string.
      if ($missingDays < 1) {
        $hours = round($missingDays * 24, 1);
        $durationStr = $hours . ' hour' . ($hours != 1 ? 's' : '');
      }
      else {
        $days = round($missingDays, 1);
        $durationStr = $days . ' day' . ($days != 1 ? 's' : '');
      }

      if (!$this->jsonMode) {
        $this->io->writeln(sprintf(
          '<comment>Syncing %s of missing data (%d range%s)...</comment>',
          $durationStr,
          count($syncableRanges),
          count($syncableRanges) != 1 ? 's' : ''
        ));
        $this->io->newLine();
      }

      // Do the actual sync to fill gaps.
      $this->syncLogsToDatabase($options, TRUE);
      $this->syncedInConfirmation = TRUE;

      // Re-check coverage after sync to get actual counts.
      $rangeAnalysis = $this->syncTracking->detectMissingRanges($startTime, $endTime);
      $missingRanges = $rangeAnalysis['ranges'] ?? [];
    }

    // Step 4: Get actual record count from database (after sync).
    $actualCount = 0;
    if ($rangeAnalysis['has_data']) {
      $actualCount = $rangeAnalysis['coverage']['count'] ?? 0;
    }

    // Format dates in human-readable format.
    // For 'all', use actual database coverage dates instead of theoretical range.
    if ($timeFlag === 'all' && $rangeAnalysis['has_data'] && isset($rangeAnalysis['coverage'])) {
      $startDate = date('M j, Y g:i A', strtotime($rangeAnalysis['coverage']['earliest']));
      $endDate = date('M j, Y g:i A', strtotime($rangeAnalysis['coverage']['latest']));
    }
    else {
      $startDate = date('M j, Y g:i A', strtotime($startTime));
      $endDate = date('M j, Y g:i A', strtotime($endTime));
    }

    // Show time range.
    $this->io->writeln(sprintf(
      '<comment>You requested %s which queries all records from %s to %s</comment>',
      $options['time']['human_readable'],
      $startDate,
      $endDate
    ));
    $this->io->newLine();

    // Show actual database status (after sync has completed).
    if ($actualCount > 0) {
      $this->io->writeln(sprintf(
        '<comment>Database contains: %s records</comment>',
        number_format($actualCount)
      ));
      $this->io->writeln('<comment>This is a very large query that may take several minutes.</comment>');
    }
    else {
      $this->io->writeln('<comment>Database status: No data available</comment>');
      $this->io->writeln('<comment>Warning: Requested time range is beyond API retention limit (2 weeks)</comment>');
      $this->io->writeln('<comment>No data can be fetched for this range.</comment>');
    }

    // Only warn about beyond-retention ranges if user explicitly specified a date range.
    // For --all, it's expected that old database data might have unfillable gaps.
    if ($timeFlag !== 'all') {
      $beyondRetention = array_filter($missingRanges, function($range) {
        return $range['reason'] === 'beyond_retention';
      });
      if (!empty($beyondRetention)) {
        $this->io->newLine();
        $this->io->writeln(sprintf(
          '<comment>Warning: %d range%s beyond API retention limit will be skipped</comment>',
          count($beyondRetention),
          count($beyondRetention) != 1 ? 's are' : ' is'
        ));
      }
    }

    $this->io->newLine();
    return $this->io->confirm('Do you want to continue with this query?', FALSE);  // Default to No for safety
  }

  /**
   * Parse site filtering options.
   *
   * Gets sites from multi-value array (transformed from shorthand options).
   *
   * @param InputInterface $input Command input interface
   * @return array Array of active site names
   */
  protected function parseSiteOptions(InputInterface $input): array
  {
    // Get sites from comma-separated string.
    $siteOption = $input->getOption('site');

    if (empty($siteOption)) {
      return [];
    }

    // Split by comma and trim whitespace.
    $sites = array_map('trim', explode(',', $siteOption));

    if ($input->getOption('debug')) {
      $this->io->writeln("<comment>DEBUG [parseSiteOptions]: Raw site option value: " . var_export($siteOption, TRUE) . "</comment>");
      $this->io->writeln("<comment>DEBUG [parseSiteOptions]: Parsed sites: " . var_export($sites, TRUE) . "</comment>");
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

    // Parse --cols option for column display.
    $colsOption = $input->getOption('cols');
    if ($colsOption) {
      $cols = array_map('trim', explode(',', $colsOption));
      $columnOrder = [];
      foreach ($cols as $col) {
        // Validate column name.
        if (!in_array($col, ['status', 'host', 'ua', 'ip', 'country', 'region', 'path'])) {
          throw new \InvalidArgumentException("Invalid column name: '$col'. Valid columns: status, host, ua, ip, country, region, path");
        }
        $display[$col] = TRUE;
        $explicitOptions[$col] = TRUE;
        $columnOrder[] = $col;
      }
      // Store column order for display service.
      $display['_order'] = $columnOrder;
    }

    // Handle drupal option (special formatting).
    if ($input->getOption('drupal')) {
      $display['drupal'] = TRUE;
      $explicitOptions['drupal'] = TRUE;
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
      'min_count' => (int) $input->getOption('filter-min-count'),
      'use_cached' => $useCached,
      'limit' => (int) $input->getOption('limit'),
      'debug' => $input->getOption('debug'),
      'json' => $input->getOption('json'),
      'yes' => $input->getOption('yes'),
      'no_group' => $input->getOption('no-group'),
      'substitute_vars' => $input->getOption('substitute-vars'),
      'country_filter' => $input->getOption('filter-country'),
      'city_filter' => $input->getOption('filter-city'),
      'status_code_filter' => $input->getOption('filter-status-code'),
      'user_agent_filter' => $input->getOption('filter-user-agent'),
      'path_filter' => $input->getOption('filter-path') !== FALSE ? ($input->getOption('filter-path') ?: '/') : NULL,
      'ip_filter' => $input->getOption('filter-ip'),
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
        // Get all hostnames for this site (handles multiple hosts per site).
        $hostnames = $this->config->getHostnamesForSite($site);
        foreach ($hostnames as $hostname) {
          // Check both json.site and json.orig_host fields to support different log formats.
          $siteConditions[] = "( { json.site:$hostname } OR { json.orig_host:$hostname } )";
        }
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
      $failedFilter = $fieldValue = NULL;

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
        elseif (isset($log['data'])) {
          // Parse JSON data to get nested fields.
          $data = json_decode($log['data'], TRUE);
          if (is_array($data) && isset($data[$field])) {
            $value = $data[$field];
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
          $host = $log['orig_host'] ?? $log['hostname'] ?? 'unknown';
          $uri = $log['req_uri'] ?? 'unknown';
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
   * Syncs data to database, then queries with SQL WHERE clause.
   *
   * @param array $sqlQuery Array with 'where' (SQL WHERE clause) and 'params' (PDO parameters)
   * @param array $options Parsed command options
   * @return int Command exit code (Command::SUCCESS)
   */
  protected function executeSearch(array $sqlQuery, array $options): int
  {
    // Register signal handlers for graceful interruption.
    $this->registerSignalHandlers();

    // Display query information.
    if (!$this->jsonMode) {
      $this->io->section('Searching SolarWinds Logs');
      $this->io->text("Time range: {$options['time']['human_readable']}");
      if ($options['filters']['debug'] || $this->config->isDebugEnabled()) {
        $this->io->text("SQL WHERE: {$sqlQuery['where']}");
        if (!empty($sqlQuery['params'])) {
          $this->io->text("SQL Params: " . json_encode($sqlQuery['params']));
        }
      }
      $this->io->newLine();
    }

    // Step 1: Sync data to database (skip if already synced during confirmation).
    if (!$this->syncedInConfirmation) {
      $this->syncLogsToDatabase($options);
    }

    // Step 2: Query database with SQL WHERE clause.
    if (!$this->jsonMode) {
      $this->io->writeln('<comment>Reading logs from database...</comment>');
    }
    $results = $this->queryDatabase($options, $sqlQuery['where'], $sqlQuery['params']);

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
        'sql_where' => $sqlQuery['where'],
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
   * Handle interrupt signals from pcntl (SIGINT/SIGTERM).
   *
   * Sets interrupted flag on first signal, forces exit on second signal.
   * This is the callback for pcntl_signal, separate from Symfony's handleSignal.
   *
   * @param int $signal Signal number received
   * @param mixed $signinfo Signal information (array when using async signals)
   * @return void
   */
  public function handlePcntlSignal(int $signal, mixed $signinfo = NULL): void
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
   * Enables async signals so interrupts work even during blocking operations.
   */
  protected function registerSignalHandlers(): void
  {
    if (function_exists('pcntl_signal')) {
      // Enable async signal handling (PHP 7.1+) so signals can interrupt blocking I/O.
      if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(TRUE);
      }

      pcntl_signal(SIGINT, [$this, 'handlePcntlSignal']);
      pcntl_signal(SIGTERM, [$this, 'handlePcntlSignal']);
    }
  }

  /**
   * Check for incomplete syncs and report them to the user.
   *
   * Shows information about interrupted, failed, or pending syncs from previous runs.
   * The gap detection system will automatically fill any missing data ranges.
   */
  protected function checkAndReportIncompleteSyncs(): void
  {
    $incompleteSyncs = $this->syncTracking->getIncompleteSyncRanges();

    if (empty($incompleteSyncs)) {
      return;
    }

    // Group syncs by status for clearer reporting.
    $byStatus = [
      'interrupted' => [],
      'failed' => [],
      'in_progress' => [],
      'pending' => [],
    ];

    foreach ($incompleteSyncs as $sync) {
      $status = $sync['status'] ?? 'unknown';
      if (isset($byStatus[$status])) {
        $byStatus[$status][] = $sync;
      }
    }

    // Report each status category.
    $messages = [];

    if (!empty($byStatus['interrupted'])) {
      $count = count($byStatus['interrupted']);
      $messages[] = "$count interrupted sync" . ($count > 1 ? 's' : '') . " (Ctrl+C)";
    }

    if (!empty($byStatus['failed'])) {
      $count = count($byStatus['failed']);
      $messages[] = "$count failed sync" . ($count > 1 ? 's' : '');
    }

    if (!empty($byStatus['in_progress'])) {
      $count = count($byStatus['in_progress']);
      $messages[] = "$count stale sync" . ($count > 1 ? 's' : '') . " (crashed or still running elsewhere)";
    }

    if (!empty($byStatus['pending'])) {
      $count = count($byStatus['pending']);
      $messages[] = "$count pending sync" . ($count > 1 ? 's' : '');
    }

    if (!empty($messages)) {
      $this->io->note(
        "Found incomplete syncs: " . implode(', ', $messages) . "\n" .
        "Gap detection will automatically fill any missing data ranges."
      );
    }
  }

  /**
   * Format range message in human-readable format.
   *
   * Converts ISO timestamps to readable format and calculates duration.
   * Examples:
   *   - "Fetching 12 minutes of recent data (Nov 21 12:43 to 12:55)"
   *   - "Fetching 2 days of historical data (Nov 19 to Nov 21)"
   *
   * @param string $startTime Start time (ISO 8601)
   * @param string $endTime End time (ISO 8601)
   * @param string $reason Range reason (historical, recent, no_data)
   * @return string Human-readable range message
   */
  protected function formatRangeMessage(string $startTime, string $endTime, string $reason): string
  {
    $start = strtotime($startTime);
    $end = strtotime($endTime);
    $duration = $end - $start;

    // Calculate duration in human-readable format.
    // Use floor() to avoid rounding up (18 hours shouldn't say "1 day").
    if ($duration < 3600) {
      $minutes = max(1, floor($duration / 60));
      $durationStr = "$minutes minute" . ($minutes != 1 ? 's' : '');
    }
    elseif ($duration < 86400) {
      $hours = max(1, floor($duration / 3600));
      $durationStr = "$hours hour" . ($hours != 1 ? 's' : '');
    }
    else {
      $days = max(1, floor($duration / 86400));
      $durationStr = "$days day" . ($days != 1 ? 's' : '');
    }

    // Format dates based on duration and whether they span different days.
    $startDay = date('Y-m-d', $start);
    $endDay = date('Y-m-d', $end);
    $spansDays = $startDay !== $endDay;

    if ($duration < 3600) {
      // Short duration: show full date and time.
      $startStr = date('M j, g:ia', $start);
      if ($spansDays) {
        $endStr = date('M j, g:ia', $end);
      }
      else {
        $endStr = date('g:ia', $end);
      }
      $dateRange = "$startStr to $endStr";
    }
    elseif ($duration < 86400) {
      // Hours: show date and time.
      $startStr = date('M j, g:ia', $start);
      if ($spansDays) {
        $endStr = date('M j, g:ia', $end);
      }
      else {
        $endStr = date('g:ia', $end);
      }
      $dateRange = "$startStr to $endStr";
    }
    else {
      // Days: show just dates.
      $startStr = date('M j', $start);
      $endStr = date('M j', $end);
      $dateRange = "$startStr to $endStr";
    }

    // Format reason.
    $reasonStr = $reason === 'no_data' ? 'missing' : $reason;

    return "Fetching $durationStr of $reasonStr data ($dateRange)";
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

    // Calculate total time range in seconds for progress tracking.
    $startEpoch = strtotime($options['time']['start_time']);
    $endEpoch = strtotime($options['time']['end_time']);
    $totalSeconds = $endEpoch - $startEpoch;

    // Create progress bar with DisplayService for consistent formatting.
    $progressBar = $this->displayService->createProgressBar($this->io, $totalSeconds);

    // Override format for sync-specific custom message display.
    ProgressBar::setFormatDefinition('custom', ' [%bar%] %message%');
    $progressBar->setFormat('custom');

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
  protected function getProgressCallback(?ProgressBar $progressBar, array $options, &$totalFixed = 0, int $currentChunk = 0, int $totalChunks = 1): ?callable
  {
    if (!$progressBar) {
      return NULL;
    }

    $startEpoch = strtotime($options['time']['start_time']);
    $endEpoch = strtotime($options['time']['end_time']);
    $totalSeconds = $endEpoch - $startEpoch;
    $lastMessage = '';
    $dayRates = [];  // Rolling average of seconds per day: [realTimeForDay]
    $lastDayBoundary = $endEpoch;  // Track when we cross 24-hour boundaries
    $lastDayStartTime = NULL;  // Real time when current day started
    $debugMode = $options['filters']['debug'] ?? FALSE;

    // Open debug log file if debug mode is enabled
    $debugLogFile = NULL;
    if ($debugMode) {
      $logPath = getenv('HOME') . '/.solarwinds/sync-debug.log';
      $debugLogFile = fopen($logPath, 'a');
      if ($debugLogFile) {
        fwrite($debugLogFile, "\n" . str_repeat('=', 80) . "\n");
        fwrite($debugLogFile, "Sync started: " . date('Y-m-d H:i:s') . "\n");
        fwrite($debugLogFile, "Time range: " . gmdate('Y-m-d H:i:s', $startEpoch) . " to " . gmdate('Y-m-d H:i:s', $endEpoch) . "\n");
        fwrite($debugLogFile, "Chunk: $currentChunk / $totalChunks\n");
        fwrite($debugLogFile, str_repeat('=', 80) . "\n");
      }
    }

    return function($pageNum, $pageLogs, $newLogsAdded, $duplicatesFound, $totalResults) use ($progressBar, $options, $startEpoch, $endEpoch, $totalSeconds, &$totalFixed, &$lastMessage, &$dayRates, &$lastDayBoundary, &$lastDayStartTime, $debugMode, $debugLogFile, $currentChunk, $totalChunks) {
      // Update progress based on time range covered (oldest timestamp in current page).
      $coveredSeconds = 0;
      if (!empty($pageLogs)) {
        $oldestTime = $pageLogs[0]['time'] ?? NULL;
        if ($oldestTime) {
          $currentEpoch = strtotime($oldestTime);
          $coveredSeconds = $endEpoch - $currentEpoch;
          $progressBar->setProgress($coveredSeconds);
        }
      }

      // Calculate elapsed time and detect 24-hour boundaries.
      $elapsed = time() - $progressBar->getStartTime();

      // Detect when we cross a 24-hour boundary in the fetched data.
      if (!empty($pageLogs)) {
        $currentTimestamp = strtotime($pageLogs[0]['time']);
        $daysBehind = floor(($lastDayBoundary - $currentTimestamp) / 86400);

        // If we've crossed into a new day (fetched 24+ hours of data).
        if ($daysBehind >= 1) {
          if ($lastDayStartTime !== NULL) {
            // Record how long this 24-hour period took in real time.
            $realTimeForDay = $elapsed - $lastDayStartTime;
            $dayRates[] = $realTimeForDay;

            // Keep only last 3 days for rolling average.
            if (count($dayRates) > 3) {
              array_shift($dayRates);
            }
          }

          // Start tracking the new 24-hour period.
          $lastDayBoundary = $currentTimestamp;
          $lastDayStartTime = $elapsed;
        }
        elseif ($lastDayStartTime === NULL && $coveredSeconds > 0) {
          // First page - start tracking the first day.
          $lastDayStartTime = $elapsed;
        }
      }

      // Format elapsed time as clock format (0:44, 4:44, 1:23:44).
      if ($elapsed < 60) {
        $elapsedStr = '0:' . sprintf('%02d', $elapsed);
      }
      elseif ($elapsed < 3600) {
        $mins = floor($elapsed / 60);
        $secs = $elapsed % 60;
        $elapsedStr = $mins . ':' . sprintf('%02d', $secs);
      }
      else {
        $hours = floor($elapsed / 3600);
        $mins = floor(($elapsed % 3600) / 60);
        $secs = $elapsed % 60;
        $elapsedStr = $hours . ':' . sprintf('%02d', $mins) . ':' . sprintf('%02d', $secs);
      }

      // Format data completeness: "13h of 14d" or "2d3h of 7d".
      $totalHours = $totalSeconds / 3600;
      $retrievedHours = $coveredSeconds / 3600;

      // Format retrieved amount.
      if ($retrievedHours < 24) {
        $retrievedPart = round($retrievedHours) . 'h';
      }
      else {
        $days = floor($retrievedHours / 24);
        $hours = (int) round(fmod($retrievedHours, 24));
        if ($hours >= 24) {
          $days++;
          $hours = 0;
        }
        $retrievedPart = $hours > 0 ? "{$days}d{$hours}h" : "{$days}d";
      }

      // Format total amount.
      if ($totalHours < 24) {
        $totalPart = round($totalHours) . 'h';
      }
      else {
        $days = floor($totalHours / 24);
        $hours = (int) round(fmod($totalHours, 24));
        if ($hours >= 24) {
          $days++;
          $hours = 0;
        }
        $totalPart = $hours > 0 ? "{$days}d{$hours}h" : "{$days}d";
      }

      $completenessStr = "$retrievedPart of $totalPart";

      // Estimate remaining time if enough data.
      $remainingStr = 'estimating';
      if ($elapsed >= 5 && $coveredSeconds > 0) {
        $remainingSeconds = $totalSeconds - $coveredSeconds;

        // Use 24-hour day rates if we have completed day data.
        if (!empty($dayRates)) {
          // Average the last 1-3 completed days.
          $avgSecondsPerDay = array_sum($dayRates) / count($dayRates);
          $remainingDays = $remainingSeconds / 86400;
          $estimatedTimeRemaining = $remainingDays * $avgSecondsPerDay;
        }
        else {
          // Fall back to overall average if haven't completed a full day yet.
          $coverageRate = $coveredSeconds / $elapsed;
          $estimatedTimeRemaining = $remainingSeconds / $coverageRate;
        }

        // Calculate ETA as actual wall clock time.
        $etaTimestamp = time() + (int) $estimatedTimeRemaining;
        $etaTime = date('g:ia', $etaTimestamp);

        // Determine if ETA is today, tomorrow, or a future date.
        $todayStart = strtotime('today');
        $tomorrowStart = strtotime('tomorrow');
        $dayAfterStart = strtotime('+2 days', $todayStart);

        if ($etaTimestamp < $tomorrowStart) {
          $remainingStr = $etaTime . ' ETA';
        }
        elseif ($etaTimestamp < $dayAfterStart) {
          $remainingStr = $etaTime . ' tomorrow';
        }
        else {
          $etaDate = date('n/j', $etaTimestamp);
          $remainingStr = $etaTime . ' on ' . $etaDate;
        }
      }

      // Build progress message.
      // Normal mode: "13h of 14d / 12:40am ETA / 4:59 elapsed"
      // Debug mode:  "13h of 14d / 12:40am ETA / p42 / 1.2K recs / 34/s / 4:59 elapsed"
      $message = $completenessStr;

      if ($remainingStr !== 'estimating') {
        $message .= " / $remainingStr";
      }

      // Activity indicators only shown in debug mode.
      if ($debugMode) {
        // Show chunk info if multiple chunks
        if ($totalChunks > 1) {
          $message .= " / c$currentChunk/$totalChunks";
        }
        $message .= " / p$pageNum";  // Page number shows API pagination progress

        if ($totalResults > 0) {
          // Total records - use K for thousands, M for millions
          if ($totalResults >= 1000000) {
            $recsStr = round($totalResults / 1000000, 1) . 'M';
          }
          elseif ($totalResults >= 1000) {
            $recsStr = round($totalResults / 1000, 1) . 'K';
          }
          else {
            $recsStr = $totalResults;
          }
          $message .= " / {$recsStr} recs";
        }

        // Calculate and show records per second.
        if ($elapsed > 0 && $totalResults > 0) {
          $recsPerSec = round($totalResults / $elapsed, 1);
          $message .= " / {$recsPerSec}/s";  // Records per second shows insertion rate
        }
      }

      if ($totalFixed > 0) {
        $message .= " / $totalFixed bad json";
      }

      $message .= " / $elapsedStr elapsed";

      // Log to debug file every 10 pages
      if ($debugLogFile && $pageNum % 10 === 0) {
        $timestamp = date('H:i:s');
        $recsPerSec = $elapsed > 0 ? round($totalResults / $elapsed, 1) : 0;
        $oldestLog = !empty($pageLogs) ? ($pageLogs[0]['time'] ?? 'unknown') : 'unknown';
        fwrite($debugLogFile, sprintf(
          "[%s] Chunk %d/%d | Page %d | %d recs | %.1f rec/s | Oldest: %s | New: %d | Dups: %d\n",
          $timestamp,
          $currentChunk,
          $totalChunks,
          $pageNum,
          $totalResults,
          $recsPerSec,
          $oldestLog,
          $newLogsAdded,
          $duplicatesFound
        ));
      }

      // Only update terminal if message changed (avoid redundant I/O).
      if ($message !== $lastMessage) {
        $progressBar->setMessage($message);
        $lastMessage = $message;
      }
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
   * Format a number for human-readable display.
   *
   * Formats numbers with appropriate abbreviations (k for thousands, M for millions).
   * Examples: 5000 -> "5k", 1500000 -> "1.5M", 500 -> "500"
   *
   * @param int $number The number to format
   * @return string Formatted number string
   */
  protected function formatNumber(int $number): string
  {
    if ($number >= 1000000) {
      // Millions: show one decimal place
      return round($number / 1000000, 1) . 'M';
    }
    elseif ($number >= 1000) {
      // Thousands: show one decimal place if not a round number
      $k = $number / 1000;
      if ($k == floor($k)) {
        return floor($k) . 'k';
      }
      return round($k, 1) . 'k';
    }
    else {
      // Under 1000: show as-is
      return (string) $number;
    }
  }

  /**
   * Split large time range into smaller 1-day chunks.
   *
   * Prevents memory issues and performance degradation during long fetches.
   *
   * IMPORTANT: Chunks are returned in REVERSE order (newest to oldest) to avoid
   * creating middle gaps if interrupted. This ensures interrupted fetches only have
   * missing historical data at the beginning, which range detection handles naturally.
   *
   * @param string $start Start time (ISO 8601)
   * @param string $end End time (ISO 8601)
   * @return array Array of chunk definitions with 'start' and 'end' times (newest first)
   */
  protected function splitRangeIntoChunks(string $start, string $end): array
  {
    $chunks = [];
    $chunkSize = 86400; // 1 day in seconds

    $startTs = strtotime($start);
    $endTs = strtotime($end);
    $duration = $endTs - $startTs;

    // If range is less than 1 day, no need to chunk.
    if ($duration <= $chunkSize) {
      return [['start' => $start, 'end' => $end]];
    }

    // Split into 1-day chunks, working backwards from newest to oldest.
    // This prevents middle gaps if interrupted (only old data will be missing).
    $currentEnd = $endTs;
    while ($currentEnd > $startTs) {
      $currentStart = max($currentEnd - $chunkSize, $startTs);

      $chunks[] = [
        'start' => gmdate('Y-m-d\TH:i:s\Z', $currentStart),
        'end' => gmdate('Y-m-d\TH:i:s\Z', $currentEnd),
      ];

      $currentEnd = $currentStart;
    }

    return $chunks;
  }

  /**
   * Sync logs from API to database with automatic range detection.
   *
   * Implements universal sync: fetches ALL logs for time range to database,
   * with automatic range detection and incremental page-by-page saves.
   *
   * Long ranges (>1 day) are automatically split into 1-day chunks to prevent
   * memory issues and performance degradation.
   *
   * NOTE: This method ONLY syncs data. It does NOT return results.
   * Call queryDatabase() after sync to retrieve filtered results.
   *
   * @param array $options Query options containing time range
   * @param bool $showCacheMessage Whether to show database hit/sync messages (default: TRUE)
   */
  protected function syncLogsToDatabase(array $options, bool $showCacheMessage = TRUE): void
  {
    // Check if debug log exists and ask to clear it
    $autoConfirm = $options['filters']['yes'] ?? FALSE;
    if (!$this->jsonMode && ($options['filters']['debug'] ?? FALSE)) {
      $logPath = getenv('HOME') . '/.solarwinds/sync-debug.log';
      if (file_exists($logPath)) {
        $fileSize = filesize($logPath);
        $fileSizeKb = round($fileSize / 1024, 1);
        // With --yes flag, automatically clear the log
        if ($autoConfirm) {
          unlink($logPath);
          $this->io->writeln('<info>Debug log cleared (--yes flag)</info>');
        }
        else {
          $choice = $this->io->confirm(
            "Debug log exists ($fileSizeKb KB). Clear it and start fresh?",
            TRUE
          );
          if ($choice) {
            unlink($logPath);
            $this->io->writeln('<info>Debug log cleared</info>');
          }
        }
      }
    }

    // Convert time range to ISO 8601 for database queries.
    $startTime = gmdate('Y-m-d\TH:i:s\Z', strtotime($options['time']['start_time']));
    $endTime = gmdate('Y-m-d\TH:i:s\Z', strtotime($options['time']['end_time']));

    // Check for incomplete syncs and report them.
    if ($showCacheMessage && !$this->jsonMode) {
      $this->checkAndReportIncompleteSyncs();
    }

    // Detect missing ranges in database coverage.
    if ($showCacheMessage && !$this->jsonMode) {
      $this->io->writeln('<comment>Detecting gaps in coverage...</comment>');
    }
    $rangeAnalysis = $this->syncTracking->detectMissingRanges($startTime, $endTime);

    // No missing ranges - data already in database.
    if (empty($rangeAnalysis['ranges'])) {
      if ($showCacheMessage && !$this->jsonMode) {
        $this->io->note("Data already in database");
      }
      return;
    }

    // We have missing ranges - fetch from API with incremental saves.
    // Process ranges in REVERSE order (recent first) to avoid middle gaps if interrupted.
    $totalNewLogs = 0;
    $interrupted = FALSE;
    foreach (array_reverse($rangeAnalysis['ranges']) as $range) {
      // Skip ranges beyond API retention - cannot fetch from API
      if ($range['reason'] === 'beyond_retention') {
        continue;
      }

      // Check for interruption before processing each range.
      if (self::isInterrupted()) {
        $interrupted = TRUE;
        if (!$this->jsonMode) {
          $this->io->writeln('');
          $this->io->warning('Sync interrupted - continuing with partial data');
        }
        break;
      }

      // Split large ranges into 1-day chunks to prevent memory issues.
      // Chunking is an internal optimization - show user a single progress bar for entire range.
      $chunks = $this->splitRangeIntoChunks($range['start'], $range['end']);

      // Create sync range tracking entry.
      $syncId = $this->syncTracking->createSyncRange($range['start'], $range['end'], count($chunks));

      if (!$this->jsonMode && $showCacheMessage) {
        $rangeMessage = $this->formatRangeMessage($range['start'], $range['end'], $range['reason']);
        $this->io->writeln("<comment>$rangeMessage</comment>");
      }

      // Create single progress bar for entire range (not per-chunk).
      $rangeOptions = $options;
      $rangeOptions['time'] = [
        'start_time' => $range['start'],
        'end_time' => $range['end'],
      ];
      $progressBar = $this->createSearchProgressBar($rangeOptions);

      // Mark sync as in progress.
      $this->syncTracking->updateSyncStatus($syncId, 'in_progress');

      // Track malformed JSON entries and progress.
      $totalFixed = $chunksCompleted = $rangeRecordsInserted = 0;

      // Create save callback to insert each page immediately.
      $saveCallback = function(array $pageLogs) use (&$totalNewLogs, &$totalFixed, &$rangeRecordsInserted) {
        $result = $this->databaseService->insertLogs($pageLogs);
        $totalNewLogs += $result['inserted'];
        $totalFixed += $result['fixed'];
        $rangeRecordsInserted += $result['inserted'];
      };

      // Process each chunk separately (internal optimization).
      foreach ($chunks as $chunkIndex => $chunk) {
        // Check for interruption before each chunk.
        if (self::isInterrupted()) {
          $interrupted = TRUE;
          $this->syncTracking->updateSyncStatus($syncId, 'interrupted');
          $this->syncTracking->updateSyncProgress($syncId, $rangeRecordsInserted, $chunksCompleted);
          if (!$this->jsonMode) {
            $this->io->writeln('');
            $this->io->warning('Sync interrupted - continuing with partial data');
          }
          break 2; // Break out of both chunk and range loops.
        }

        try {
          // Fetch chunk data with incremental saving.
          // Progress bar continues across all chunks showing overall progress.
          $this->apiService->retrieveLogs(
            $chunk['start'],
            $chunk['end'],
            $this->getProgressCallback($progressBar, $rangeOptions, $totalFixed, $chunkIndex + 1, count($chunks)),
            NULL,  // No debug callback
            $saveCallback  // Save each page immediately
          );

          // Chunk completed successfully - update progress.
          $chunksCompleted++;
          $this->syncTracking->updateSyncProgress($syncId, $rangeRecordsInserted, $chunksCompleted);
        }
        catch (\Exception $e) {
          $this->finishProgressBar($progressBar);

          // Check if this was an interruption - if so, break loop and continue gracefully.
          if (self::isInterrupted()) {
            $interrupted = TRUE;
            $this->syncTracking->updateSyncStatus($syncId, 'interrupted');
            $this->syncTracking->updateSyncProgress($syncId, $rangeRecordsInserted, $chunksCompleted);
            if (!$this->jsonMode) {
              $this->io->writeln('');
              $this->io->warning('Sync interrupted - continuing with partial data');
            }
            break 2;  // Break out of both chunk and range loops.
          }

          // Non-interruption error - mark as failed.
          $this->syncTracking->updateSyncStatus($syncId, 'failed', $e->getMessage());
          $this->syncTracking->updateSyncProgress($syncId, $rangeRecordsInserted, $chunksCompleted);

          // Report and re-throw.
          if (!$this->jsonMode) {
            $this->io->error(sprintf(
              'Failed to fetch range %s to %s: %s',
              $range['start'],
              $range['end'],
              $e->getMessage()
            ));

            if ($totalNewLogs > 0) {
              $this->io->note("Partial data saved: " . $this->formatNumber($totalNewLogs) . " logs were successfully saved to database before error");
            }
          }

          // Re-throw non-interruption errors to let caller handle them.
          throw $e;
        }
      } // End chunk loop

      // All chunks completed successfully - mark sync as completed.
      $this->syncTracking->updateSyncStatus($syncId, 'completed');

      // Finish progress bar after all chunks complete.
      $this->finishProgressBar($progressBar);
    } // End range loop

    // Show sync summary.
    if ($showCacheMessage && !$this->jsonMode) {
      if ($interrupted) {
        $this->io->warning("Sync interrupted - partial data saved (" . $this->formatNumber($totalNewLogs) . " logs)");
      }
      elseif ($rangeAnalysis['has_data']) {
        $this->io->note("Synced " . $this->formatNumber($totalNewLogs) . " new logs to database");
      }
      else {
        $this->io->note("Synced " . $this->formatNumber($totalNewLogs) . " logs to database");
      }
    }
  }

  /**
   * Query database with SQL WHERE clause.
   *
   * Retrieves logs from database filtered by SQL WHERE clause and time range.
   * Should be called AFTER syncLogsToDatabase().
   *
   * @param array $options Query options containing time range
   * @param string|null $sqlWhere SQL WHERE clause (without WHERE keyword)
   * @param array $sqlParams PDO parameters for SQL WHERE clause
   * @param bool $includeData Whether to include data JSON column
   * @param callable|null $progressCallback Optional progress callback
   * @return array Array of log entries matching the query
   */
  protected function queryDatabase(array $options, ?string $sqlWhere = NULL, array $sqlParams = [], bool $includeData = FALSE, ?callable $progressCallback = NULL): array
  {
    // When --all is used, skip time filtering for maximum performance.
    if (isset($options['time']['time_option']) && $options['time']['time_option'] === 'all') {
      $startTime = NULL;
      $endTime = NULL;
    }
    else {
      // Convert time range to ISO 8601 for database queries.
      $startTime = gmdate('Y-m-d\TH:i:s\Z', strtotime($options['time']['start_time']));
      $endTime = gmdate('Y-m-d\TH:i:s\Z', strtotime($options['time']['end_time']));
    }

    // Query database with SQL WHERE clause.
    // includeData=TRUE for commands that need display fields like region (SearchCommand, BotCommand)
    return $this->logQuery->getLogsWithQuery($sqlWhere, $sqlParams, $startTime, $endTime, $includeData, $progressCallback);
  }

  /**
   * Build the SQL WHERE clause (must be implemented by child classes).
   *
   * @param array $options Parsed command options
   * @return array Array with 'where' (SQL WHERE clause) and 'params' (PDO parameters)
   */
  abstract protected function buildSearchQuery(array $options): array;

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
   * Validate the SQL query and options (default implementation does no validation).
   *
   * Child classes override this to implement command-specific validation logic.
   *
   * @param array $sqlQuery Array with 'where' and 'params'
   * @param array $options Parsed command options
   * @throws \InvalidArgumentException If validation fails
   */
  protected function validateQuery(array $sqlQuery, array $options): void
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

  /**
   * Get standardized time range documentation for command help text.
   *
   * Returns formatted documentation of all available time range options
   * that can be appended to any command's help text.
   *
   * @return string Time range documentation in help format
   */
  protected static function getTimeRangeHelp(): string
  {
    return <<<'HELP'

        <comment>Time Range Options:</comment>
        <info>--Nm</info>                             Minutes (N = 1-60): --1m, --15m, --30m
        <info>--Nh</info>                             Hours (N = 1-48): --1h, --6h, --24h
        <info>--Nd</info>                             Days (N = 1-90): --1d, --7d, --30d
        <info>--Nw</info>                             Weeks (N = 1-8): --1w, --2w, --4w
        <info>--NM</info>                             Months (N = 1-12): --1M, --3M, --6M
        <info>--Ny</info>                             Years (N = 1-10): --1y, --2y, --5y
        <info>--ND</info>                             Full days ago (N = 1-365): --1D (yesterday), --7D (week ago)
        <info>--yesterday</info>                      Yesterday (full day, same as --1D)
        <info>--all</info>                            All available data
        <info>-t, --time=VALUE</info>                 Alternative: use any of the above values (e.g., --time=2w)
HELP;
  }

  /**
   * Build SQL site filtering conditions with PDO parameters.
   *
   * Generates SQL conditions for filtering by site names using configured
   * hostname mappings. Produces parameterized query fragments for safe
   * SQL execution.
   *
   * @param array $options Parsed command options containing sites array
   * @param array &$params Reference to params array to populate with PDO bindings
   * @param int $startIndex Starting index for parameter naming (default: 0)
   * @return string SQL condition string or empty string if no sites specified
   */
  protected function buildSqlSiteFilter(array $options, array &$params, int $startIndex = 0): string
  {
    if (empty($options['sites'])) {
      return '';
    }

    $siteConditions = [];
    $paramIndex = $startIndex;

    foreach ($options['sites'] as $site) {
      // Get all hostnames for this site (handles multiple hosts per site).
      $hostnames = $this->config->getHostnamesForSite($site);
      foreach ($hostnames as $hostname) {
        $paramName = ':site' . $paramIndex++;
        $siteConditions[] = "orig_host = $paramName";
        $params[$paramName] = $hostname;
      }
    }

    return empty($siteConditions) ? '' : '(' . implode(' OR ', $siteConditions) . ')';
  }

  /**
   * Validate that a column is not redundant for this command.
   *
   * Some commands always display certain columns (e.g., bot command shows ua,
   * status command shows status). This helper validates that users don't
   * explicitly request columns that are already displayed by default.
   *
   * @param array $options Parsed command options
   * @param string $columnName Column to check (e.g., 'ua', 'status')
   * @param string $commandContext Description for error message (e.g., 'bot command')
   * @throws \InvalidArgumentException If column is explicitly set and redundant
   */
  protected function validateNotRedundantColumn(array $options, string $columnName, string $commandContext): void
  {
    $explicitOptions = $options['display']['_explicit'] ?? [];

    if (isset($explicitOptions[$columnName])) {
      throw new \InvalidArgumentException(
        "--cols=$columnName is redundant for $commandContext (already displays {$columnName}s). " .
        "Use other columns to add dimensions to the analysis"
      );
    }
  }

  /**
   * Validate minimum string length for user input.
   *
   * Ensures that search terms, patterns, and other text inputs meet minimum
   * length requirements to prevent overly broad or meaningless queries.
   *
   * @param string $value Value to validate
   * @param int $minLength Minimum required length
   * @param string $fieldName Field name for error message
   * @throws \InvalidArgumentException If value is too short
   */
  protected function validateMinLength(string $value, int $minLength, string $fieldName): void
  {
    $trimmed = trim($value);
    if (empty($trimmed) || strlen($trimmed) < $minLength) {
      throw new \InvalidArgumentException(
        "$fieldName must be at least $minLength characters: '$value'"
      );
    }
  }

  /**
   * Build SQL conditions for status code filtering.
   *
   * Supports flexible status code filtering:
   * - 1-digit: "5" matches 500-599 (all 5xx errors)
   * - 2-digit: "50" matches 500-509 (specific range)
   * - 3-digit: "404" matches exactly 404 (exact match)
   *
   * Validates that status codes start with valid digits (1-5) and generates
   * appropriate SQL conditions with PDO parameter bindings.
   *
   * @param string $statusFilter Status filter value (1-3 digits)
   * @param array &$params Reference to params array to populate with PDO bindings
   * @return string SQL condition
   * @throws \InvalidArgumentException If status filter format or range is invalid
   */
  protected function buildStatusCodeFilter(string $statusFilter, array &$params): string
  {
    // Validate format.
    if (!preg_match('/^\d{1,3}$/', $statusFilter)) {
      throw new \InvalidArgumentException(
        "Invalid status filter format: $statusFilter. Must be 1-3 digits"
      );
    }

    // Validate logical range (1-5xx).
    $firstDigit = (int) $statusFilter[0];
    if ($firstDigit < 1 || $firstDigit > 5) {
      $context = strlen($statusFilter) === 1 ? 'range' : 'code';
      throw new \InvalidArgumentException(
        "Invalid status $context: $statusFilter. Status codes must start with 1-5"
      );
    }

    // Build condition based on length.
    if (strlen($statusFilter) === 3) {
      // Exact match: 404
      $params[':status'] = (int) $statusFilter;
      return 'resp_status = :status';
    }
    elseif (strlen($statusFilter) === 2) {
      // Range match: 50 => 500-509
      $params[':status_start'] = (int) ($statusFilter . '0');
      $params[':status_end'] = (int) ($statusFilter . '9');
      return 'resp_status BETWEEN :status_start AND :status_end';
    }
    else {
      // Range match: 5 => 500-599
      $params[':status_start'] = (int) ($statusFilter . '00');
      $params[':status_end'] = (int) ($statusFilter . '99');
      return 'resp_status BETWEEN :status_start AND :status_end';
    }
  }

  /**
   * Build SQL condition to exclude static files.
   *
   * Filters out requests for static assets (images, CSS, JS, etc.) that are
   * typically served from the /sites/default/files directory in Drupal.
   * Useful for focusing analysis on dynamic page requests.
   *
   * @param array &$params Reference to params array to populate with PDO bindings
   * @return string SQL condition
   */
  protected function excludeStaticFiles(array &$params): string
  {
    $params[':static_files'] = '%/sites/default/files%';
    return 'req_uri NOT LIKE :static_files';
  }

}
