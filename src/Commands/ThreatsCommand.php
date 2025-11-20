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

        <comment>Campaign Detection:</comment>
        <info>--summary-only</info>                Show only campaign summary (default, skip detailed breakdown)
        <info>--show-details</info>                Include detailed breakdown by IP/country/path after summary
        <info>--min-campaign-requests=N</info>     Minimum requests for campaign detection (default: 10)
        <info>--min-severity=LEVEL</info>          Minimum severity to display (low|medium|high|critical, default: low)

        <comment>Analysis Display:</comment>
        Shows campaign summary with severity-based grouping by default.
        Use --show-details to include full IP/country/path breakdown.

        <comment>Examples:</comment>
        <info>solarwinds threats --2w</info>                                 # Campaign summary, 2 weeks (default view)
        <info>solarwinds threats --1h --show-details</info>                  # Last hour with full breakdown
        <info>solarwinds threats --min-severity=high</info>                  # Only high/critical campaigns
        <info>solarwinds threats --min-campaign-requests=50</info>           # Only campaigns with 50+ requests
        <info>solarwinds threats --min-requests=5s --1h</info>               # Custom rate threshold
        <info>solarwinds threats --by-country-exclude=""</info>              # Include all countries (even US)
        <info>solarwinds threats --json</info>                               # JSON output for monitoring
        ')
      ->addOption('min-requests', NULL, InputOption::VALUE_REQUIRED, 'Minimum request rate to flag (default: 1/s)', '1/s')
      ->addOption('by-country-multiplier', NULL, InputOption::VALUE_REQUIRED, 'Multiply threshold for country aggregation (default: 5)', 5)
      ->addOption('by-country-exclude', NULL, InputOption::VALUE_REQUIRED, 'Comma-separated country codes to exclude (default: US)', 'US')
      ->addOption('summary-only', NULL, InputOption::VALUE_NONE, 'Show only campaign summary (default behavior)')
      ->addOption('show-details', NULL, InputOption::VALUE_NONE, 'Include detailed breakdown after campaign summary')
      ->addOption('min-campaign-requests', NULL, InputOption::VALUE_REQUIRED, 'Minimum requests for campaign detection (default: 10)', '10')
      ->addOption('min-severity', NULL, InputOption::VALUE_REQUIRED, 'Minimum severity to display (low|medium|high|critical, default: low)', 'low')
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

    // Parse campaign detection options.
    $options['show_details'] = $input->getOption('show-details');
    $options['min_campaign_requests'] = (int) $input->getOption('min-campaign-requests');
    $options['min_severity'] = strtolower($input->getOption('min-severity'));

    // Validate min_severity.
    $validSeverities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($options['min_severity'], $validSeverities, TRUE)) {
      throw new \InvalidArgumentException(
        "Invalid --min-severity value: '{$options['min_severity']}'. Must be one of: " . implode(', ', $validSeverities)
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

    // Try to load from cache first.
    $cacheUsed = FALSE;
    $cacheAge = NULL;
    $results = $this->tryLoadFromCache($query, $options, $cacheUsed, $cacheAge);

    // If no cache, fetch from API.
    if ($results === NULL) {
      if (!$this->jsonMode) {
        $this->io->section('Searching SolarWinds Logs');
        $this->io->text("Time range: {$options['time']['human_readable']}");
        $this->io->text("Query: $query");
      }

      // Create progress bar and fetch results.
      $progressBar = $this->createSearchProgressBar($options);

      $results = $this->apiService->searchLogs(
        $query,
        $options['time']['start_time'],
        $options['time']['end_time'],
        $this->getProgressCallback($progressBar, $options)
      );

      $this->finishProgressBar($progressBar);

      // Save to cache (multi-dimensional searches always cache).
      $this->saveResultsToCache($query, $options, $results, 0);
    }
    elseif (!$this->jsonMode) {
      $ageText = $cacheAge ? "($cacheAge old)" : "(age unknown)";
      $this->io->note("Using cached results $ageText - use --no-cache to force fresh query");
    }

    // Apply client-side filters.
    $originalCount = count($results);
    $filteredResults = $this->filterResults($results, $options);
    $filteredCount = count($filteredResults);

    // Get campaign detection options.
    $scriptOptions = $options['script_specific'];
    $minCampaignRequests = $scriptOptions['min_campaign_requests'];
    $minSeverity = $scriptOptions['min_severity'];

    // Detect campaigns.
    $allCampaigns = $this->detectCampaigns($filteredResults, $minCampaignRequests);

    // Filter campaigns by minimum severity.
    $severityOrder = ['critical', 'high', 'medium', 'low'];
    $minSeverityIndex = array_search($minSeverity, $severityOrder, TRUE);
    $allowedSeverities = array_slice($severityOrder, 0, $minSeverityIndex + 1);

    $campaigns = array_filter($allCampaigns, function($campaign) use ($allowedSeverities) {
      return in_array($campaign['severity'], $allowedSeverities, TRUE);
    });

    // JSON mode: output campaign data.
    if ($this->jsonMode) {
      // Group campaigns by severity for JSON output.
      $campaignsBySeverity = [
        'critical' => [],
        'high' => [],
        'medium' => [],
        'low' => [],
      ];

      foreach ($campaigns as $campaign) {
        $campaignsBySeverity[$campaign['severity']][] = $campaign;
      }

      // Calculate summary statistics.
      $totalRequests = array_sum(array_column($campaigns, 'count'));
      $uniqueIps = count($campaigns);
      $uniqueCountries = count(array_unique(array_column($campaigns, 'country')));

      $this->outputJson('success', [
        'campaigns' => $campaignsBySeverity,
        'summary' => [
          'total_campaigns' => count($campaigns),
          'unique_ips' => $uniqueIps,
          'unique_countries' => $uniqueCountries,
          'total_requests' => $totalRequests,
        ],
      ], [
        'time_range' => $options['time'],
        'filters' => [
          'original_count' => $originalCount,
          'filtered_count' => $filteredCount,
          'min_campaign_requests' => $minCampaignRequests,
          'min_severity' => $minSeverity,
        ],
      ]);

      return 0;
    }

    // Table mode: display campaign summary.
    $showDetails = $scriptOptions['show_details'];
    $this->displayCampaignSummary($campaigns, $this->io, $minCampaignRequests, $minSeverity);

    // Only show detailed breakdown if explicitly requested.
    if ($showDetails) {
      $this->io->newLine();
      $this->io->section('Detailed Breakdown by Dimension');

      $firstDimension = TRUE;
      foreach ($dimensions as $dimension) {
        if (!$firstDimension) {
          $this->io->newLine();
        }

        // Apply dimension-specific filtering.
        $dimFilters = $this->getDimensionFilters($dimension['key'], $options);
        $dimThreshold = $dimFilters['min_count'];

        $header = "Analysis by {$dimension['name']} (threshold: {$dimThreshold} requests)";
        $this->io->writeln("<comment>$header</comment>");

        // Display results for this dimension.
        $dimDisplay = [$dimension['key'] => TRUE, '_explicit' => $originalDisplay['_explicit']];
        $this->displayService->displayResults($filteredResults, $dimDisplay, $this->io, $debugMode, $dimFilters, $searchTerm);

        $firstDimension = FALSE;
      }

      // Show result count.
      $this->io->newLine();
      if (!empty($options['filters']['client_side_filters']) && $originalCount !== $filteredCount) {
        $this->io->success("Found $filteredCount results (filtered from $originalCount results" . ($cacheUsed ? ", from cache" : "") . ")");
      }
      else {
        $this->io->success("Found $filteredCount results" . ($cacheUsed ? " (from cache)" : ""));
      }
    }

    return 0;
  }

  /**
   * Detect threat campaigns from traffic results.
   *
   * Groups traffic into campaigns based on:
   * - Source IP address
   * - Request volume and rate
   * - HTTP methods (GET/POST ratio)
   * - Targeted endpoints
   * - Time span
   *
   * @param array $results Traffic results
   * @param int $minRequests Minimum requests to qualify as a campaign
   * @return array Array of detected campaigns
   */
  protected function detectCampaigns(array $results, int $minRequests = 10): array
  {
    $campaigns = [];

    // Group traffic by IP address first.
    $byIp = [];
    foreach ($results as $result) {
      $message = $result['message'] ?? '';
      $log = json_decode($message, TRUE) ?: [];

      $ip = $log['client_ip'] ?? $log['ip'] ?? 'unknown';
      $country = $log['geoip']['country_code2'] ?? $log['client_country'] ?? $log['country'] ?? 'unknown';
      $method = $log['req_method'] ?? $log['method'] ?? 'GET';
      $uri = $log['req_uri'] ?? $log['uri'] ?? '';
      $time = $result['time'] ?? '';
      $userAgent = $log['req_user_agent'] ?? $log['user_agent'] ?? '';
      $host = $log['orig_host'] ?? $log['req_host'] ?? $log['host'] ?? 'unknown';

      if (!isset($byIp[$ip])) {
        $byIp[$ip] = [
          'ip' => $ip,
          'country' => $country,
          'requests' => [],
          'methods' => [],
          'paths' => [],
          'times' => [],
          'user_agents' => [],
          'hosts' => [],
        ];
      }

      // Parse URI into base path and query string.
      $basePath = $uri;
      $queryString = '';
      if (strpos($uri, '?') !== FALSE) {
        [$basePath, $queryString] = explode('?', $uri, 2);
      }

      $byIp[$ip]['requests'][] = $result;
      $byIp[$ip]['times'][] = $time;
      $byIp[$ip]['paths'][] = $uri;  // Full URI for display
      $byIp[$ip]['hosts'][] = $host;

      // Track host + base path combinations (without query strings) for endpoint analysis.
      if (!isset($byIp[$ip]['host_base_paths'])) {
        $byIp[$ip]['host_base_paths'] = [];
      }
      $byIp[$ip]['host_base_paths'][] = $host . '|' . $basePath;

      // Track host + full URI combinations for duplicate detection.
      if (!isset($byIp[$ip]['host_full_uris'])) {
        $byIp[$ip]['host_full_uris'] = [];
      }
      $byIp[$ip]['host_full_uris'][] = $host . '|' . $uri;

      // Track user agents.
      if ($userAgent) {
        $byIp[$ip]['user_agents'][] = $userAgent;
      }

      // Track method counts.
      if (!isset($byIp[$ip]['methods'][$method])) {
        $byIp[$ip]['methods'][$method] = 0;
      }
      $byIp[$ip]['methods'][$method]++;
    }

    // Convert IP groups into campaigns with metadata.
    foreach ($byIp as $ip => $data) {
      $count = count($data['requests']);

      // Only create campaigns for IPs meeting minimum threshold.
      if ($count < $minRequests) {
        continue;
      }

      $times = array_filter($data['times']);
      sort($times);
      $firstSeen = reset($times) ?: 'unknown';
      $lastSeen = end($times) ?: 'unknown';

      // Calculate time span in hours and request rate.
      $timeSpanHours = 0;
      $requestsPerHour = 0;
      if ($firstSeen !== 'unknown' && $lastSeen !== 'unknown') {
        $start = strtotime($firstSeen);
        $end = strtotime($lastSeen);
        $timeSpanSeconds = $end - $start;
        $timeSpanHours = $timeSpanSeconds / 3600;
        if ($timeSpanHours > 0) {
          $requestsPerHour = $count / $timeSpanHours;
        }
      }

      // Analyze URI patterns (host-aware).
      $hostBasePathCounts = array_count_values($data['host_base_paths']);
      $uniqueHostBasePaths = count($hostBasePathCounts);

      $hostFullUriCounts = array_count_values($data['host_full_uris']);
      $uniqueHostFullUris = count($hostFullUriCounts);

      // Get top paths for display (strip host prefix for readability).
      arsort($hostBasePathCounts);
      $topHostPaths = array_slice(array_keys($hostBasePathCounts), 0, 5);
      $topPaths = array_map(function($hostPath) {
        // Extract just the path part after the pipe
        $parts = explode('|', $hostPath, 2);
        return $parts[1] ?? $hostPath;
      }, $topHostPaths);

      // Calculate path diversity (host+base paths vs total requests).
      $basePathDiversity = $count > 0 ? $uniqueHostBasePaths / $count : 0;

      // Calculate URI patterns:
      // - If unique host+base paths ~= unique host+full URIs: hitting different endpoints
      // - If unique host+base paths << unique host+full URIs: hitting same endpoints with different args (scanning/crawling)
      // - If unique host+full URIs << total requests: repeating same requests (exploit probing or load attack)
      $uriDuplicationRatio = $count > 0 ? $uniqueHostFullUris / $count : 1;
      $parameterScanningRatio = $uniqueHostBasePaths > 0 ? $uniqueHostFullUris / $uniqueHostBasePaths : 1;

      // Get most common user agent.
      $userAgent = '';
      if (!empty($data['user_agents'])) {
        $uaCounts = array_count_values($data['user_agents']);
        arsort($uaCounts);
        $userAgent = array_key_first($uaCounts);
      }

      // Get most common host.
      $host = 'unknown';
      if (!empty($data['hosts'])) {
        $hostCounts = array_count_values($data['hosts']);
        arsort($hostCounts);
        $host = array_key_first($hostCounts);
      }

      // Classify attack type based on behavior patterns.
      $attackType = $this->classifyThreatType(
        $data,
        $count,
        $timeSpanHours,
        $uniqueHostBasePaths,
        $userAgent,
        $basePathDiversity,
        $uriDuplicationRatio,
        $parameterScanningRatio
      );

      // Classify campaign severity based on volume and rate.
      $severity = 'low';
      if ($requestsPerHour > 1000 || $count >= 10000) {
        $severity = 'critical';  // High-volume DDoS or aggressive attack
      }
      elseif ($requestsPerHour > 100 || $count >= 1000) {
        $severity = 'high';  // Moderate DDoS or persistent attack
      }
      elseif ($requestsPerHour > 10 || $count >= 100) {
        $severity = 'medium';  // Low-volume but sustained
      }

      $campaigns[] = [
        'ip' => $ip,
        'country' => $data['country'],
        'count' => $count,
        'attack_type' => $attackType,
        'requests_per_hour' => round($requestsPerHour, 1),
        'methods' => $data['methods'],
        'unique_base_paths' => $uniqueHostBasePaths,
        'unique_full_uris' => $uniqueHostFullUris,
        'top_paths' => $topPaths,
        'host' => $host,
        'first_seen' => $firstSeen,
        'last_seen' => $lastSeen,
        'time_span_hours' => round($timeSpanHours, 1),
        'severity' => $severity,
      ];
    }

    // Sort by count (most active campaigns first).
    usort($campaigns, function ($a, $b) {
      return $b['count'] <=> $a['count'];
    });

    return $campaigns;
  }

  /**
   * Detect DDoS patterns by analyzing coordinated attacks across multiple IPs.
   *
   * A DDoS (Distributed Denial of Service) requires:
   * - Multiple IPs (distributed) - at least 10 IPs
   * - High request volume - at least 1000 requests total
   * - High request rate - at least 100 requests per second
   * - Same hostname (not just same path across different sites)
   * - Targeting same endpoint(s)
   * - Narrow time window (1-minute windows)
   *
   * Note: Multiple IPs hitting "/" is normal behavior and should NOT be
   * flagged as DDoS unless volume and rate thresholds are met.
   *
   * @param array $campaigns Array of campaigns
   * @return array DDoS attack groups
   */
  protected function detectDDoSPatterns(array $campaigns): array
  {
    $ddosGroups = [];

    // Group campaigns by host + target path + time window (1-minute windows)
    $byTarget = [];
    foreach ($campaigns as $campaign) {
      // Get primary target (most frequently hit path)
      $topPath = $campaign['top_paths'][0] ?? 'unknown';
      $host = $campaign['host'] ?? 'unknown';

      // Round time to 1-minute windows for DDoS detection
      $timeWindow = 'unknown';
      $timestamp = 0;
      if ($campaign['first_seen'] !== 'unknown') {
        $timestamp = strtotime($campaign['first_seen']);
        $timeWindow = date('Y-m-d H:i', floor($timestamp / 60) * 60); // 1-min windows
      }

      // Group by host + path + time window
      $targetKey = $host . '|' . $topPath . '|' . $timeWindow;

      if (!isset($byTarget[$targetKey])) {
        $byTarget[$targetKey] = [
          'host' => $host,
          'target' => $topPath,
          'time_window' => $timeWindow,
          'timestamp' => $timestamp,
          'ips' => [],
          'total_requests' => 0,
          'countries' => [],
        ];
      }

      $byTarget[$targetKey]['ips'][] = $campaign['ip'];
      $byTarget[$targetKey]['total_requests'] += $campaign['count'];
      $byTarget[$targetKey]['countries'][$campaign['country']] = TRUE;
    }

    // Identify DDoS patterns with strict thresholds
    foreach ($byTarget as $key => $group) {
      $ipCount = count($group['ips']);
      $totalRequests = $group['total_requests'];

      // Calculate requests per second (1-minute window = 60 seconds)
      $requestsPerSecond = $totalRequests / 60;

      // DDoS detection criteria (ALL must be met):
      // 1. At least 10 coordinating IPs (distributed)
      // 2. At least 1000 total requests in the window
      // 3. At least 100 requests per second
      // 4. Not the home page (too many false positives)
      $isHomePage = in_array($group['target'], ['/', '/index.html', '/index.php'], TRUE);

      if ($ipCount >= 10 && $totalRequests >= 1000 && $requestsPerSecond >= 100 && !$isHomePage) {
        $ddosGroups[] = [
          'host' => $group['host'],
          'target' => $group['target'],
          'time_window' => $group['time_window'],
          'ip_count' => $ipCount,
          'ips' => $group['ips'],
          'total_requests' => $totalRequests,
          'requests_per_second' => round($requestsPerSecond, 1),
          'countries' => array_keys($group['countries']),
          'country_count' => count($group['countries']),
        ];
      }
    }

    // Sort by request rate (most severe attacks first)
    usort($ddosGroups, function ($a, $b) {
      return $b['requests_per_second'] <=> $a['requests_per_second'];
    });

    return $ddosGroups;
  }

  /**
   * Identify known bot from user agent string.
   *
   * @param string $userAgent User agent string
   * @return string|null Bot name if identified, NULL otherwise
   */
  protected function identifyBot(string $userAgent): ?string
  {
    if (empty($userAgent)) {
      return NULL;
    }

    // Convert to lowercase for case-insensitive matching.
    $ua = strtolower($userAgent);

    // Check for "bot" keyword and extract bot name.
    if (strpos($ua, 'bot') !== FALSE) {
      // Common bot patterns.
      $botPatterns = [
        'googlebot' => 'Googlebot',
        'bingbot' => 'Bingbot',
        'yandexbot' => 'YandexBot',
        'baiduspider' => 'Baidu Spider',
        'duckduckbot' => 'DuckDuckBot',
        'slurp' => 'Yahoo Slurp',
        'semrushbot' => 'SEMrushBot',
        'ahrefsbot' => 'AhrefsBot',
        'dotbot' => 'DotBot (Moz)',
        'mj12bot' => 'MJ12bot (Majestic)',
        'petalbot' => 'PetalBot',
        'applebot' => 'Applebot',
        'facebookexternalhit' => 'Facebook Bot',
        'twitterbot' => 'TwitterBot',
        'linkedinbot' => 'LinkedInBot',
        'discordbot' => 'DiscordBot',
        'slackbot' => 'Slackbot',
        'telegrambot' => 'TelegramBot',
        'whatsapp' => 'WhatsApp Bot',
      ];

      foreach ($botPatterns as $pattern => $name) {
        if (strpos($ua, $pattern) !== FALSE) {
          return $name;
        }
      }

      // Generic bot detection - extract word before "bot".
      if (preg_match('/(\w+)bot/i', $userAgent, $matches)) {
        return ucfirst($matches[1]) . 'Bot';
      }
    }

    // Crawler patterns (without "bot").
    $crawlerPatterns = [
      'crawler' => 'Crawler',
      'spider' => 'Spider',
      'scraper' => 'Scraper',
    ];

    foreach ($crawlerPatterns as $pattern => $suffix) {
      if (strpos($ua, $pattern) !== FALSE) {
        // Try to extract name before pattern.
        if (preg_match('/(\w+)\s*' . $pattern . '/i', $userAgent, $matches)) {
          return ucfirst($matches[1]) . ' ' . $suffix;
        }
        return 'Generic ' . $suffix;
      }
    }

    return NULL;
  }

  /**
   * Classify threat type based on behavior patterns.
   *
   * @param array $data IP traffic data
   * @param int $count Total request count
   * @param float $timeSpanHours Time span in hours
   * @param int $uniqueBasePaths Number of unique base paths (without query strings)
   * @param string $userAgent User agent string
   * @param float $basePathDiversity Base path diversity ratio (unique paths / total requests)
   * @param float $uriDuplicationRatio Ratio of unique full URIs to total requests
   * @param float $parameterScanningRatio Ratio of full URIs to base paths (indicates param variation)
   * @return string Threat type classification
   */
  protected function classifyThreatType(
    array $data,
    int $count,
    float $timeSpanHours,
    int $uniqueBasePaths,
    string $userAgent = '',
    float $basePathDiversity = 0,
    float $uriDuplicationRatio = 1,
    float $parameterScanningRatio = 1
  ): string {
    // Check if this is a known bot.
    $botName = $this->identifyBot($userAgent);
    if ($botName !== NULL) {
      return $botName;
    }

    $methods = $data['methods'];
    $postCount = $methods['POST'] ?? 0;
    $getCount = $methods['GET'] ?? 0;
    $headCount = $methods['HEAD'] ?? 0;

    $postRatio = $count > 0 ? $postCount / $count : 0;
    $requestRate = $timeSpanHours > 0 ? $count / $timeSpanHours : 0;

    // Exploit Probing: Repeating same request with same parameters (low URI duplication)
    // Example: 100 requests to "/page?id=1" trying to trigger a vulnerability
    if ($uriDuplicationRatio < 0.3 && $count > 20) {
      return 'Exploit Probing';
    }

    // Parameter Scanning: Same endpoint, varying parameters (high param scanning ratio)
    // Example: /search?q=<script>, /search?q=SELECT, /search?q=../../etc/passwd
    if ($parameterScanningRatio > 3 && $uniqueBasePaths < 5) {
      return 'Parameter Scanner';
    }

    // Load Attack: Repeating same requests to exhaust resources
    // Similar to exploit probing but higher volume and rate
    if ($uriDuplicationRatio < 0.2 && $requestRate > 100) {
      return 'Load Attack';
    }

    // Brute-force: High POST ratio, low path diversity
    if ($postRatio > 0.7 && $basePathDiversity < 0.1) {
      return 'Brute-force Attack';
    }

    // High-rate crawler: High path diversity + high request rate (legitimate aggressive crawling)
    if ($basePathDiversity > 0.5 && $requestRate > 1000) {
      return 'High-Rate Crawler';
    }

    // Scraper/Crawler: High path diversity (exploring site)
    if ($basePathDiversity > 0.5) {
      return 'Web Scraper / Crawler';
    }

    // DoS Attack: Very high rate + low diversity (hammering specific endpoint)
    if ($requestRate > 5000 && $basePathDiversity < 0.2) {
      return 'DoS Attack (High Rate)';
    }

    // Aggressive Crawler: High rate with moderate diversity
    if ($requestRate > 1000 && $basePathDiversity > 0.2) {
      return 'Aggressive Crawler';
    }

    // Targeted attack: Low path diversity, sustained traffic
    if ($basePathDiversity < 0.05 && $count > 50) {
      return 'Targeted Endpoint Attack';
    }

    // Scanner: Moderate diversity, HEAD requests, or moderate path scanning
    if ($headCount > 10 || ($basePathDiversity > 0.2 && $basePathDiversity < 0.5)) {
      return 'Vulnerability Scanner';
    }

    // High rate but moderate diversity - likely aggressive bot
    if ($requestRate > 500) {
      return 'Aggressive Bot';
    }

    // Default: High-volume traffic
    return 'High-Volume Traffic';
  }

  /**
   * Display campaign summary.
   *
   * @param array $campaigns Array of detected campaigns
   * @param SymfonyStyle $io Console I/O
   * @param int $minRequests Minimum requests threshold used
   * @param string $minSeverity Minimum severity level to display
   */
  protected function displayCampaignSummary(array $campaigns, $io, int $minRequests = 10, string $minSeverity = 'low'): void
  {
    if (empty($campaigns)) {
      $io->note("No significant threat campaigns detected (minimum $minRequests requests per IP).");
      return;
    }

    $io->section(sprintf('Threat Campaigns Detected: %d', count($campaigns)));

    // Detect DDoS patterns (coordinated attacks across multiple IPs)
    $ddosPatterns = $this->detectDDoSPatterns($campaigns);

    if (!empty($ddosPatterns)) {
      $io->writeln("\n\033[1;31m*** DDoS ATTACKS DETECTED (Coordinated Multi-IP Attacks) ***\033[0m");
      $io->newLine();

      $ddosRows = [];
      foreach ($ddosPatterns as $ddos) {
        $countriesStr = implode(', ', array_slice($ddos['countries'], 0, 5));
        if (count($ddos['countries']) > 5) {
          $countriesStr .= ' +' . (count($ddos['countries']) - 5) . ' more';
        }

        $ddosRows[] = [
          $ddos['host'],
          substr($ddos['target'], 0, 40),
          $ddos['ip_count'],
          $ddos['total_requests'],
          $ddos['requests_per_second'] . ' req/s',
          $countriesStr,
          $ddos['time_window'],
        ];
      }

      $io->table(
        ['Host', 'Target', 'IPs', 'Total Req', 'Rate', 'Countries', 'Time Window'],
        $ddosRows
      );

      $io->writeln("<comment>These coordinated attacks from multiple IPs targeting the same host+endpoint indicate DDoS activity.</comment>");
      $io->newLine();
    }

    // Group by severity.
    $bySeverity = [
      'critical' => [],
      'high' => [],
      'medium' => [],
      'low' => [],
    ];

    foreach ($campaigns as $campaign) {
      $bySeverity[$campaign['severity']][] = $campaign;
    }

    // Determine which severity levels to display based on min_severity.
    $severityOrder = ['critical', 'high', 'medium', 'low'];
    $minSeverityIndex = array_search($minSeverity, $severityOrder, TRUE);
    $displaySeverities = array_slice($severityOrder, 0, $minSeverityIndex + 1);

    // Display campaigns by severity (highest first).
    foreach ($displaySeverities as $severity) {
      $severityCampaigns = $bySeverity[$severity];
      if (empty($severityCampaigns)) {
        continue;
      }

      $severityLabel = strtoupper($severity);
      $severityColor = [
        'critical' => "\033[1;31m",  // Bold red
        'high' => "\033[31m",        // Red
        'medium' => "\033[33m",      // Yellow
        'low' => "\033[37m",         // Gray
      ][$severity];
      $reset = "\033[0m";

      $io->writeln("\n{$severityColor}{$severityLabel} PRIORITY ({$severityLabel} count: " . count($severityCampaigns) . "){$reset}");
      $io->newLine();

      $rows = [];
      foreach ($severityCampaigns as $campaign) {
        $methodSummary = [];
        foreach (['GET', 'POST', 'HEAD'] as $method) {
          if (isset($campaign['methods'][$method]) && $campaign['methods'][$method] > 0) {
            $methodSummary[] = "$method: {$campaign['methods'][$method]}";
          }
        }
        $methodStr = implode(', ', $methodSummary) ?: 'Unknown';

        $timeSpan = $campaign['time_span_hours'] > 0
          ? sprintf('%.1fh', $campaign['time_span_hours'])
          : 'instant';

        $rate = $campaign['requests_per_hour'] > 0
          ? sprintf('%.1f req/h', $campaign['requests_per_hour'])
          : '-';

        // Show endpoint analysis (base paths vs full URIs).
        $endpointInfo = $campaign['unique_base_paths'] . ' paths';
        if ($campaign['unique_full_uris'] != $campaign['unique_base_paths']) {
          $endpointInfo = $campaign['unique_base_paths'] . ' paths (' . $campaign['unique_full_uris'] . ' URIs)';
        }

        $rows[] = [
          $campaign['count'],
          $campaign['ip'],
          $campaign['country'],
          $campaign['attack_type'],
          $methodStr,
          $endpointInfo,
          $rate,
          $timeSpan,
        ];
      }

      $io->table(
        ['Requests', 'Source IP', 'Country', 'Attack Type', 'Methods', 'Targets', 'Rate', 'Time Span'],
        $rows
      );
    }

    // Summary statistics.
    $totalRequests = array_sum(array_column($campaigns, 'count'));
    $uniqueIps = count($campaigns);
    $uniqueCountries = count(array_unique(array_column($campaigns, 'country')));

    $io->newLine();
    $io->success(sprintf(
      'Summary: %d campaigns from %d IPs across %d countries, %d total requests',
      count($campaigns),
      $uniqueIps,
      $uniqueCountries,
      $totalRequests
    ));
  }
}
