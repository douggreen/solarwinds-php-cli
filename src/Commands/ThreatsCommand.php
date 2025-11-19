<?php

/**
 * @file ThreatsCommand.php
 * @brief Security threat detection and analysis command
 *
 * @class ThreatsCommand
 * @brief Analyzes traffic patterns to detect potential security threats and malicious actors
 *
 * This command specializes in detecting various security threats including distributed
 * denial-of-service attacks, exploit attempts, vulnerability scanning, brute-force attacks,
 * and other malicious behavior patterns.
 *
 * @section command_purpose Command Purpose
 *
 * **Primary Functions:**
 * - Detect high-volume request sources (potential DDoS)
 * - Identify exploit scanning and vulnerability probing
 * - Detect geographic clustering of attack traffic
 * - Flag excessive POST requests (exploit/brute-force attempts)
 * - Identify targeted endpoint attacks
 *
 * **Use Cases:**
 * - Monitor for active attacks (DDoS, exploits, brute-force)
 * - Detect vulnerability scanning and probe attempts
 * - Identify coordinated attack patterns
 * - Track malicious traffic sources for blocking
 *
 * @section query_implementation Query Implementation
 *
 * **Search Pattern:**
 * - Analyzes all HTTP traffic in specified timeframe
 * - Optional POST-only filtering via --posts-only flag
 * - Aggregates by IP, country, or path based on --by-* flags
 * - Applies configurable thresholds to identify threats
 *
 * **Default Settings:**
 * - Time range: 15 minutes
 * - Min requests per IP: 100
 * - Min POST requests: 20
 * - Geographic threshold: 80% from single country
 * - Path threshold: 100 requests to single endpoint
 * - Display: By IP address
 *
 * @section validation_logic Validation Logic
 *
 * **Threshold Validation:**
 * - Ensures threshold values are positive integers
 * - Validates at least one aggregation method selected
 * - Prevents conflicting options
 *
 * @section example Usage Examples
 * @code{.bash}
 * # Basic threat detection (high-volume IPs, last 15 minutes)
 * solarwinds threats
 *
 * # Custom threshold for IP analysis
 * solarwinds threats --min-requests=200 --1h
 *
 * # Detect POST-based attacks (exploit/brute-force attempts)
 * solarwinds threats --posts-only --min-posts=10
 *
 * # Geographic attack clustering
 * solarwinds threats --by-country --1h
 *
 * # Targeted endpoint attacks
 * solarwinds threats --by-path --min-requests=150
 *
 * # JSON output for monitoring integration
 * solarwinds threats --json
 * @endcode
 *
 * @see BaseSolarWindsCommand For base class implementation
 * @see DisplayService For result formatting
 * @see ApiService For SolarWinds API integration
 *
 * @note Designed for integration with automated monitoring and Slack alerting
 * @warning High thresholds may miss subtle attacks; low thresholds increase false positives
 */

namespace SolarWinds\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Threats Command - Analyzes traffic for security threats and malicious actors
 *
 * Specialized command for detecting various security threats including DDoS attacks,
 * exploit attempts, vulnerability scanning, and malicious traffic patterns.
 */
class ThreatsCommand extends BaseSolarWindsCommand
{
  protected string $defaultTime = '15m';
  protected array $defaultDisplayOptions = ['ip', 'country', 'path'];

  protected function configure(): void
  {
    $this
      ->setName('threats')
      ->setDescription('Detect potential security threats and malicious actors')
      ->setHelp('
        The <info>threats</info> command analyzes traffic patterns to detect volume-based security threats
        including DDoS attacks, vulnerability scanning, brute-force attempts, and high-volume malicious actors.

        For payload-based exploit detection (XSS, SQLi, etc.), use the <info>exploits</info> command instead.

        <comment>Threshold Options (with defaults):</comment>
        <info>--min-requests=RATE</info>           Minimum request rate to flag (default: 1/s)
                                          Formats: N/s (per second), Ns (every N seconds), or absolute number
                                          Examples: 3/s, 5s (1 every 5 sec), 100 (absolute)
        <info>--by-country-multiplier=N</info>     Multiply threshold for country aggregation (default: 5)
                                          Countries need N times more requests than IPs to be flagged
        <info>--by-country-exclude=LIST</info>     Comma-separated country codes to exclude (default: US)
                                          Use empty string or "none" to include all countries

        <comment>Analysis Display:</comment>
        Always shows all three dimensions: IP addresses, countries, and paths.

        <comment>Examples:</comment>
        <info>solarwinds threats</info>                                      # Multi-dimensional analysis (all 3)
        <info>solarwinds threats --min-requests=5s --1h</info>               # Custom threshold, all dimensions
        <info>solarwinds threats --by-country-exclude=""</info>              # Include all countries (even US)
        <info>solarwinds threats --by-country-exclude=US,CN,RU</info>        # Exclude multiple countries
        <info>solarwinds threats --by-country-multiplier=10</info>           # Countries need 10x threshold
        <info>solarwinds threats --json</info>                               # JSON output for monitoring
        ')
      ->addOption('min-requests', NULL, InputOption::VALUE_REQUIRED, 'Minimum request rate to flag (default: 1/s)', '1/s')
      ->addOption('by-country-multiplier', NULL, InputOption::VALUE_REQUIRED, 'Multiply threshold for country aggregation (default: 5)', 5)
      ->addOption('by-country-exclude', NULL, InputOption::VALUE_REQUIRED, 'Comma-separated country codes to exclude (default: US)', 'US')
    ;

    // Call parent to set up common options.
    parent::configure();
  }


  /**
   * Parse request rate syntax and convert to absolute count based on timeframe.
   *
   * Supported formats:
   * - "3/s" or "3/sec" or "3/second" = 3 requests per second
   * - "5s" or "5sec" or "5second" = 1 request every 5 seconds (0.2/s)
   * - "100" = Absolute count (not rate-based)
   *
   * @param string $rate The rate string to parse
   * @param int $timeframeSeconds The timeframe duration in seconds
   * @return int The calculated absolute count
   */
  protected function parseRequestRate(string $rate, int $timeframeSeconds): int
  {
    $rate = strtolower(trim($rate));

    // Format: N/s (requests per second)
    if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(s|sec|secs|second|seconds)$/', $rate, $matches)) {
      $requestsPerSecond = (float) $matches[1];
      return (int) ceil($requestsPerSecond * $timeframeSeconds);
    }

    // Format: Ns (1 request every N seconds)
    if (preg_match('/^(\d+(?:\.\d+)?)\s*(s|sec|secs|second|seconds)$/', $rate, $matches)) {
      $secondsPerRequest = (float) $matches[1];
      $requestsPerSecond = 1.0 / $secondsPerRequest;
      return (int) ceil($requestsPerSecond * $timeframeSeconds);
    }

    // Absolute number
    if (is_numeric($rate)) {
      return (int) $rate;
    }

    throw new \InvalidArgumentException("Invalid rate format: '$rate'. Use formats like '3/s', '5s', or '100'");
  }

  /**
   * Override parseFilterOptions to apply --min-requests as the threshold.
   */
  protected function parseFilterOptions(InputInterface $input): array
  {
    // Call parent to get base filter options.
    $filters = parent::parseFilterOptions($input);

    // Calculate timeframe duration in seconds from the time options.
    $timeOptions = $this->parseTimeOptions($input);
    $startTime = strtotime($timeOptions['start_time']);
    $endTime = strtotime($timeOptions['end_time']);
    $timeframeSeconds = $endTime - $startTime;

    // Parse --min-requests threshold.
    $minRequestsRaw = $input->getOption('min-requests');
    $minThreshold = $this->parseRequestRate($minRequestsRaw, $timeframeSeconds);

    // If --min-count is still the default value (1), override with calculated threshold.
    // This allows users to still use --min-count if they want to override.
    if ($filters['min_count'] === 1 && $minThreshold > 0) {
      $filters['min_count'] = $minThreshold;
    }

    return $filters;
  }

  /**
   * Parse script-specific options for threats.
   */
  protected function parseScriptSpecificOptions(InputInterface $input): array
  {
    $options = [];

    // Parse threshold options (keep as strings for rate parsing).
    $options['min_requests'] = $input->getOption('min-requests');

    // Parse country-specific options.
    $options['country_multiplier'] = (int) $input->getOption('by-country-multiplier');

    // Parse country exclusion list.
    $excludeCountries = $input->getOption('by-country-exclude');
    // Handle special values: empty string or 'none' means no exclusions.
    if ($excludeCountries === '' || strtolower($excludeCountries) === 'none') {
      $options['excluded_countries'] = [];
    }
    else {
      // Split comma-separated list and normalize to uppercase.
      $options['excluded_countries'] = array_map(
        'trim',
        array_map('strtoupper', explode(',', $excludeCountries))
      );
    }

    return $options;
  }

  /**
   * Build the search query for threat detection.
   */
  protected function buildSearchQuery(array $options): string
  {
    // Analyze all HTTP traffic (Fastly logs only).
    // GET + POST + HEAD + other HTTP methods.
    return "( { json.req_method:GET } OR { json.req_method:POST } OR { json.req_method:HEAD } )";
  }

  /**
   * Validate the query and options for threat detection.
   */
  protected function validateQuery(string $query, array $options): void
  {
    $scriptOptions = $options['script_specific'];

    // Validate country_multiplier.
    if ($scriptOptions['country_multiplier'] < 1) {
      throw new \InvalidArgumentException("--by-country-multiplier must be at least 1: {$scriptOptions['country_multiplier']}");
    }

    // Note: min_requests and min_posts are validated in parseRequestRate() when parsed.
  }

  /**
   * Get dimension-specific filters.
   *
   * Applies country-specific threshold multiplier and exclusions.
   */
  protected function getDimensionFilters(string $dimension, array $options): array
  {
    $filters = $options['filters'];
    $scriptOptions = $options['script_specific'];

    // Apply country-specific logic.
    if ($dimension === 'country') {
      // Multiply threshold for country aggregation.
      $filters['min_count'] = $filters['min_count'] * $scriptOptions['country_multiplier'];

      // Add excluded countries to filters.
      $filters['excluded_countries'] = $scriptOptions['excluded_countries'];
    }

    return $filters;
  }

  /**
   * Override executeSearch to provide multi-dimensional analysis.
   *
   * Fetches results once, then displays them aggregated by each dimension.
   * In JSON mode, combines all dimensions into a single JSON object.
   */
  protected function executeSearch(string $query, array $options): int
  {
    // Determine which dimensions to analyze.
    $dimensions = [];
    if (isset($options['display']['ip'])) {
      $dimensions[] = ['name' => 'IP', 'key' => 'ip', 'plural' => 'IPs'];
    }
    if (isset($options['display']['country'])) {
      $dimensions[] = ['name' => 'Country', 'key' => 'country', 'plural' => 'countries'];
    }
    if (isset($options['display']['path'])) {
      $dimensions[] = ['name' => 'Path', 'key' => 'path', 'plural' => 'paths'];
    }

    // If only one dimension, use standard display.
    if (count($dimensions) <= 1) {
      return parent::executeSearch($query, $options);
    }

    // Multi-dimensional analysis - fetch data once, display multiple times.
    return $this->executeMultiDimensionalSearch($query, $options, $dimensions);
  }

  /**
   * Execute multi-dimensional search.
   *
   * Fetches data once, then either:
   * - Table mode: displays each dimension in separate sections
   * - JSON mode: outputs a single combined JSON object
   */
  protected function executeMultiDimensionalSearch(string $query, array $options, array $dimensions): int
  {
    $originalDisplay = $options['display'];
    $threshold = $options['filters']['min_count'];
    $searchTerm = $this->extractSearchTerm($options);
    $debugMode = $options['filters']['debug'] || $this->config->isDebugEnabled();

    // Fetch data once using parent's caching logic.
    $scriptName = $this->getName() ?? 'unknown';
    $timeArg = $options['time']['human_readable'];
    $siteArgs = implode(',', array_map(fn($site) => "--$site", $options['sites']));
    $cacheKey = $this->cacheService->generateCacheKey($scriptName, $query, $timeArg, $siteArgs);

    // Try to load from cache first.
    $results = NULL;
    $cacheUsed = FALSE;
    $cacheAge = NULL;

    if (!$options['filters']['no_cache']) {
      $currentTimeArg = $this->extractTimeArgFromHumanReadable($options['time']['human_readable']);

      if ($this->cacheService->isCacheFresh(
        $cacheKey,
        $currentTimeArg,
        $options['filters']['cache_infinite'],
        $options['filters']['cache_seconds']
      )) {
        $results = $this->cacheService->loadFromCache($cacheKey);
        if ($results !== NULL) {
          $cacheUsed = TRUE;
          $cacheAge = $this->cacheService->getCacheAge($cacheKey);
        }
      }
    }

    // If no cache, fetch from API.
    if ($results === NULL) {
      if (!$this->jsonMode) {
        $this->io->section('Searching SolarWinds Logs');
        $this->io->text("Time range: {$options['time']['human_readable']}");
        $this->io->text("Query: $query");
      }

      $results = $this->apiService->searchLogs(
        $query,
        $options['time']['start_time'],
        $options['time']['end_time']
      );

      // Save to cache (multi-dimensional searches always cache).
      $this->cacheService->saveToCache($cacheKey, $results, 0);
    }
    elseif (!$this->jsonMode) {
      $ageText = $cacheAge ? "($cacheAge old)" : "(age unknown)";
      $this->io->note("Using cached results $ageText - use --no-cache to force fresh query");
    }

    // Apply client-side filters.
    $originalCount = count($results);
    $results = $this->filterResults($results, $options);
    $filteredCount = count($results);

    // JSON mode: output combined JSON.
    if ($this->jsonMode) {
      $dimensionResults = [];
      foreach ($dimensions as $dimension) {
        $dimDisplay = [$dimension['key'] => TRUE, '_explicit' => $originalDisplay['_explicit']];

        // Apply dimension-specific filtering.
        $dimFilters = $this->getDimensionFilters($dimension['key'], $options);

        $formattedResults = $this->displayService->formatResultsForJson(
          $results,
          $dimDisplay,
          $dimFilters,
          $searchTerm
        );
        $dimensionResults[$dimension['key']] = $formattedResults;
      }

      $this->outputJson('success', [
        'dimensions' => $dimensionResults,
      ], [
        'query' => $query,
        'time_range' => $options['time'],
        'display_options' => array_keys(array_filter($originalDisplay)),
        'cache' => [
          'used' => $cacheUsed,
          'age' => $cacheAge,
        ],
        'filters' => [
          'original_count' => $originalCount,
          'filtered_count' => $filteredCount,
          'client_side' => $options['filters']['client_side_filters'] ?: [],
          'threshold' => $threshold,
        ],
      ]);

      return 0;
    }

    // Table mode: display each dimension in separate sections.
    $firstDimension = TRUE;
    foreach ($dimensions as $dimension) {
      if (!$firstDimension) {
        $this->io->newLine();
      }

      // Apply dimension-specific filtering.
      $dimFilters = $this->getDimensionFilters($dimension['key'], $options);
      $dimThreshold = $dimFilters['min_count'];

      $header = "Analysis by {$dimension['name']} (threshold: {$dimThreshold} requests)";
      $this->io->section($header);

      // Display results for this dimension.
      $dimDisplay = [$dimension['key'] => TRUE, '_explicit' => $originalDisplay['_explicit']];
      $this->displayService->displayResults($results, $dimDisplay, $this->io, $debugMode, $dimFilters, $searchTerm);

      // Show result count.
      if (!empty($options['filters']['client_side_filters']) && $originalCount !== $filteredCount) {
        $this->io->success("Found $filteredCount results (filtered from $originalCount results" . ($cacheUsed ? ", from cache" : "") . ")");
      }
      else {
        $this->io->success("Found $filteredCount results" . ($cacheUsed ? " (from cache)" : ""));
      }

      $firstDimension = FALSE;
    }

    return 0;
  }
}
