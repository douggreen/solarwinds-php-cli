<?php

/**
 * @file ConfigurationService.php
 * @brief YAML-based configuration management service for SolarWinds application
 *
 * @class ConfigurationService
 * @brief Centralized configuration management with YAML file parsing and site mappings
 *
 * This service handles all configuration aspects of the SolarWinds application, including
 * API credentials, site mappings, display preferences, and dynamic alias definitions.
 *
 * @section config_structure Configuration Structure
 *
 * The configuration file (~/.solarwinds.yml) supports these sections:
 *
 * **Required Settings:**
 * - token: SolarWinds API authentication token
 * - api_base_url: Region-specific SolarWinds API endpoint
 *
 * **Optional Settings:**
 * - debug: Enable debug output (default: false)
 * - progress: Show progress bars (default: true)
 * - validate: Enable result validation (default: false)
 *
 * **Site Configuration:**
 * - sites: Array of site definitions with name, hosts, and display labels
 *
 * **Dynamic Commands:**
 * - aliases: Key-value pairs defining custom command shortcuts
 *
 * **Blocking Configuration:**
 * - blocking.allowlist: IP addresses/CIDR ranges that should never be blocked
 * - blocking.trusted_bots: User-agent patterns for known good bots (supplements defaults)
 * - blocking.thresholds: Customizable thresholds for blocking decisions
 *
 * **Risk Scoring Configuration:**
 * - risk_scoring.weights: Weight factors for each risk component (must sum to 1.0)
 * - risk_scoring.origin_impact: Origin impact scoring thresholds
 * - risk_scoring.volume_rate: Volume rate thresholds and scores
 * - risk_scoring.attack_severity: Pattern severity classifications and bonuses
 * - risk_scoring.historical: Historical context scoring
 * - risk_scoring.server_impact: Server impact scoring
 * - risk_scoring.risk_levels: Risk classification thresholds
 *
 * **Database Configuration:**
 * - database.path: Path to SQLite database file (default: ~/.solarwinds/logs.db)
 *
 * @section site_mapping Site Mapping System
 *
 * The service provides three types of site mappings:
 * - **Host Mappings**: Map domain names to site identifiers for filtering
 * - **Display Mappings**: Map site identifiers to user-friendly labels
 * - **Site Filtering**: Enable --site-name flags for targeted analysis
 *
 * @section example Configuration Example
 * @code{.yaml}
 * # Required API configuration
 * token: "your-api-token"
 * api_base_url: "https://api.na-01.cloud.solarwinds.com"
 *
 * # Optional behavior settings
 * debug: false
 * progress: true
 * validate: false
 *
 * # Site definitions
 * sites:
 *   - name: "example"
 *     label: "Example Site"
 *     hosts: ["example.com", "www.example.com"]
 *
 * # Custom command aliases
 * aliases:
 *   errors: 'search "error" --cols=status --time=1h'
 *   quickbot: bot --time=1h --cols=ua
 * @endcode
 *
 * @section error_handling Error Handling
 *
 * The service provides graceful fallbacks:
 * - Missing config file: Uses sensible defaults
 * - Invalid YAML: Reports specific parsing errors
 * - Missing required settings: Provides helpful error messages
 *
 * @see SolarWindsApplication For alias registration
 * @see BaseSolarWindsCommand For site filtering usage
 * @see AliasCommand For dynamic command generation
 *
 * @note Configuration is loaded once at service instantiation and cached
 * @warning API token and api_base_url are required for proper operation
 */

namespace SolarWinds\Services;

use Symfony\Component\Yaml\Yaml;

/**
 * Configuration Service
 *
 * Handles loading and managing configuration from ~/.solarwinds.yml
 */
class ConfigurationService
{
  protected array $config = [];

  /**
   * Constructor.
   *
   * Initializes configuration service and loads settings from YAML file.
   *
   * @param string $configPath Optional path to configuration file (defaults to ~/.solarwinds.yml)
   */
  public function __construct(protected string $configPath = '')
  {
    $this->configPath = $this->configPath ?: ($_SERVER['HOME'] . '/.solarwinds.yml');
    $this->loadConfiguration();
  }

  /**
   * Load configuration from YAML file.
   *
   * Parses YAML configuration and applies defaults for missing settings.
   *
   * @throws \RuntimeException If YAML parsing fails
   */
  protected function loadConfiguration(): void
  {
    if (!file_exists($this->configPath)) {
      $this->config = [
        'token' => '',
        'api_base_url' => '',  // No default - force users to configure.
        'debug' => FALSE,
        'validate' => FALSE,
        'progress' => TRUE,
      ];
      return;
    }

    try {
      $this->config = Yaml::parseFile($this->configPath) ?? [];
    }
    catch (\Exception $e) {
      throw new \RuntimeException(
        "Failed to parse configuration file {$this->configPath}: " . $e->getMessage()
      );
    }

    $this->config = array_merge([
      'token' => '',
      'api_base_url' => '',  // No default - must be configured.
      'debug' => FALSE,
      'validate' => FALSE,
      'progress' => TRUE,
    ], $this->config);
  }

  /**
   * Get debug mode setting.
   *
   * @return bool TRUE if debug mode is enabled
   */
  public function isDebugEnabled(): bool
  {
    return $this->getBooleanValue('debug');
  }


  /**
   * Get progress mode setting.
   *
   * @return bool TRUE if progress bars should be displayed
   */
  public function isProgressEnabled(): bool
  {
    return $this->getBooleanValue('progress');
  }

  /**
   * Helper to convert various boolean representations to actual boolean.
   *
   * Handles string values like 'true', '1', 'yes', 'on' and numeric values.
   *
   * @param string $key Configuration key to retrieve
   * @return bool Boolean value of the configuration setting
   */
  protected function getBooleanValue(string $key): bool
  {
    $value = $this->config[$key] ?? FALSE;

    if (is_bool($value)) {
      return $value;
    }

    if (is_string($value)) {
      $lower = strtolower(trim($value));
      return in_array($lower, ['true', '1', 'yes', 'on']);
    }

    if (is_numeric($value)) {
      return (int) $value === 1;
    }

    return FALSE;
  }

  /**
   * Get site configurations from YAML.
   *
   * @return array Site configuration array
   */
  public function getSites(): array
  {
    return $this->config['sites'] ?? [];
  }

  /**
   * Get site host mappings for command option generation.
   *
   * Builds array of site names to host/description mappings for --site-name flags.
   *
   * @return array Site host mappings array
   */
  public function getSiteHostMappings(): array
  {
    $sites = $this->getSites();
    $mappings = [];

    foreach ($sites as $hostname => $siteConfig) {
      $name = $siteConfig['name'] ?? NULL;
      $label = $siteConfig['label'] ?? $hostname;

      if ($name) {
        $mappings[$name] = [
          'host' => $hostname,
          'description' => "Filter to {$label} site"
        ];
      }
    }

    return $mappings;
  }

  /**
   * Get all hostnames for a given site name.
   *
   * Returns array of all hostnames configured with the specified site name.
   * This handles configurations where multiple hostnames map to the same site.
   *
   * @param string $siteName Site name to look up
   * @return array Array of hostnames for this site
   */
  public function getHostnamesForSite(string $siteName): array
  {
    $sites = $this->getSites();
    $hostnames = [];

    foreach ($sites as $hostname => $siteConfig) {
      $name = $siteConfig['name'] ?? NULL;
      if ($name === $siteName) {
        $hostnames[] = $hostname;
      }
    }

    return $hostnames;
  }

  /**
   * Get hostname to display label mappings for output shortening.
   *
   * Maps full hostnames to short display labels for result formatting.
   * Supports new format where site name is key with label and hosts array.
   *
   * New format:
   *   sites:
   *     site1:
   *       label: Site 1
   *       hosts:
   *         - site1
   *         - example.com
   *         - "*.example.com"
   *
   * Note: With wildcard support, this returns only exact matches.
   * Use matchHostnameToLabel() for wildcard-aware matching.
   *
   * @return array Hostname to display label mappings (for caching/performance)
   */
  public function getHostDisplayMappings(): array
  {
    $sites = $this->getSites();
    $mappings = [];

    foreach ($sites as $key => $siteConfig) {
      // New format: key is site name, config has 'label' and 'hosts'
      if (isset($siteConfig['label']) && isset($siteConfig['hosts'])) {
        $label = $siteConfig['label'];
        // We can't pre-build all wildcard mappings, so we return patterns
        // The matching logic will be in matchHostnameToLabel()
        continue;
      }

      // Old format: key is hostname, config has 'label'
      $label = $siteConfig['label'] ?? NULL;
      if ($label) {
        $cleanHost = preg_replace('/^www\./', '', $key);
        $mappings[$cleanHost] = $label;
      }
    }

    return $mappings;
  }

  /**
   * Match a hostname to its display label, supporting wildcard patterns.
   *
   * Checks both exact matches and wildcard patterns ("*.example.com").
   *
   * New format:
   *   sites:
   *     example:
   *       label: Example Site
   *       hosts:
   *         - example
   *         - example.com
   *         - "*.example.com"
   *
   * @param string $hostname Hostname to match
   * @return string|null Label if match found, NULL otherwise
   */
  public function matchHostnameToLabel(string $hostname): ?string
  {
    $sites = $this->getSites();
    $cleanHost = preg_replace('/^www\./', '', $hostname);

    foreach ($sites as $key => $siteConfig) {
      // New format: key is site name, config has 'label' and 'hosts'
      if (isset($siteConfig['label']) && isset($siteConfig['hosts'])) {
        $label = $siteConfig['label'];
        $patterns = $siteConfig['hosts'];

        // Hosts should be an array from YAML
        if (!is_array($patterns)) {
          continue;
        }

        foreach ($patterns as $pattern) {
          if ($this->hostnameMatchesPattern($cleanHost, $pattern)) {
            return $label;
          }
        }
      }
      // Old format: key is hostname, config has 'label'
      elseif ($key === $cleanHost || $key === $hostname) {
        return $siteConfig['label'] ?? NULL;
      }
    }

    return NULL;
  }

  /**
   * Match a hostname to its site key (e.g., "abag", "mtc").
   *
   * Returns the site key for display in tables (legend provides full mapping).
   *
   * @param string $hostname Hostname to match
   * @return string|null Site key if match found, NULL otherwise
   */
  public function matchHostnameToSiteKey(string $hostname): ?string
  {
    $sites = $this->getSites();
    $cleanHost = preg_replace('/^www\./', '', $hostname);

    foreach ($sites as $key => $siteConfig) {
      // New format: key is site name, config has 'label' and 'hosts'
      if (isset($siteConfig['label']) && isset($siteConfig['hosts'])) {
        $patterns = $siteConfig['hosts'];

        // Hosts should be an array from YAML
        if (!is_array($patterns)) {
          continue;
        }

        foreach ($patterns as $pattern) {
          if ($this->hostnameMatchesPattern($cleanHost, $pattern)) {
            return $key; // Return site key (e.g., "abag", "mtc")
          }
        }
      }
      // Old format: key is hostname, return the name if configured
      elseif ($key === $cleanHost || $key === $hostname) {
        return $siteConfig['name'] ?? NULL;
      }
    }

    return NULL;
  }

  /**
   * Check if hostname matches a pattern (supports wildcards).
   *
   * Patterns:
   *   - "example.com" - exact match
   *   - "*.example.com" - matches base domain AND any subdomain
   *
   * @param string $hostname Hostname to check
   * @param string $pattern Pattern to match against
   * @return bool TRUE if hostname matches pattern
   */
  public function hostnameMatchesPattern(string $hostname, string $pattern): bool
  {
    // Exact match
    if ($hostname === $pattern) {
      return TRUE;
    }

    // Wildcard pattern: "*.example.com"
    if (str_starts_with($pattern, '*.')) {
      $baseDomain = substr($pattern, 2);
      // Match base domain: example.com
      if ($hostname === $baseDomain) {
        return TRUE;
      }
      // Match subdomains: foo.example.com, bar.baz.example.com
      return str_ends_with($hostname, '.' . $baseDomain);
    }

    return FALSE;
  }

  /**
   * Get default CMS type for all sites.
   *
   * Returns the global CMS type that applies to all sites unless overridden.
   *
   * @return string CMS type ('drupal', 'wordpress', 'other', or 'unknown')
   */
  public function getDefaultCms(): string
  {
    return $this->config['cms'] ?? 'unknown';
  }

  /**
   * Get the ring-of-trust configuration.
   *
   * Returns the raw trust config (levels + ordered rings) for TrustService,
   * or an empty array when no trust gradient is configured.
   *
   * @return array Trust configuration ('levels' and 'rings'), possibly empty.
   */
  public function getTrustConfig(): array
  {
    return $this->config['trust'] ?? [];
  }

  /**
   * Get CMS type for a specific hostname.
   *
   * Checks site-specific configuration first, then falls back to global default.
   * Returns 'unknown' if not configured - caller should detect from traffic.
   *
   * @param string $hostname Hostname to check
   * @return string CMS type ('drupal', 'wordpress', 'other', or 'unknown')
   */
  public function getSiteCmsType(string $hostname): string
  {
    $sites = $this->getSites();

    // Check for site-specific CMS configuration.
    if (isset($sites[$hostname]['cms'])) {
      return $sites[$hostname]['cms'];
    }

    // Fall back to global default.
    return $this->getDefaultCms();
  }

  /**
   * Get full configuration array.
   *
   * @return array Complete configuration array
   */
  public function getConfig(): array
  {
    return $this->config;
  }

  /**
   * Get a specific configuration value.
   *
   * @param string $key Configuration key to retrieve
   * @param mixed $default Default value if key not found
   * @return mixed Configuration value or default
   */
  public function get(string $key, mixed $default = NULL): mixed
  {
    return $this->config[$key] ?? $default;
  }

  /**
   * Get configured aliases.
   *
   * @return array Command aliases array
   */
  public function getAliases(): array
  {
    return $this->config['aliases'] ?? [];
  }

  /**
   * Get IP allowlist (IPs/ranges that should never be blocked).
   *
   * @return array Array of IP addresses and CIDR ranges
   */
  public function getBlockingAllowlist(): array
  {
    return $this->config['blocking']['allowlist'] ?? [];
  }

  /**
   * Get trusted bot user-agent patterns.
   *
   * @return array Array of regex patterns for known good bots
   */
  public function getTrustedBots(): array
  {
    $defaultBots = [
      'Googlebot',
      'bingbot',
      'Slackbot',
      'facebookexternalhit',
      'Twitterbot',
      'LinkedInBot',
      'WhatsApp',
      'TelegramBot',
      'DuckDuckBot',
      'Baiduspider',
      'YandexBot',
      'Applebot',
      'Discordbot',
    ];

    $configuredBots = $this->config['blocking']['trusted_bots'] ?? [];

    // Merge default bots with user-configured ones.
    return array_unique(array_merge($defaultBots, $configuredBots));
  }

  /**
   * Get blocking threshold configuration.
   *
   * @return array Associative array of threshold values
   */
  public function getBlockingThresholds(): array
  {
    $defaults = [
      // Scaled thresholds (apply to any timeframe)
      'min_requests_per_hour' => 10,
      'min_requests_per_day' => 70,
      'urgent_requests_per_hour' => 5000,

      // Duration as % of scan window
      'min_duration_ratio' => 0.1,

      // Behavior ratios
      'high_confidence_40x_ratio' => 0.8,
      'exploit_ratio_threshold' => 0.7,

      // Single-event filtering
      'single_event_threshold_hours' => 1,
      'single_event_critical_volume' => 10000,

      // Trusted IP prefixes (Google crawlers)
      'trusted_ip_prefixes' => [
        '66.249.',
        '64.233.',
        '66.102.',
        '74.125.',
        '142.250.',
      ],
    ];

    $configured = $this->config['blocking']['thresholds'] ?? [];

    return array_merge($defaults, $configured);
  }

  /**
   * Get database path for SQLite storage.
   *
   * @return string Absolute path to SQLite database file
   */
  public function getDatabasePath(): string
  {
    $default = $_SERVER['HOME'] . '/.solarwinds/logs.db';
    return $this->config['database']['path'] ?? $default;
  }

  /**
   * Get archive configuration with defaults.
   *
   * Archive moves logs older than local_keep_days to monthly SQLite shard
   * files at longterm_storage. Disabled by default; the rest of the values
   * only matter when enabled=TRUE.
   *
   * @return array{enabled: bool, local_keep_days: int, longterm_storage: string, shard_period: string, include_in_queries: bool, fail_on_unavailable: bool}
   */
  public function getArchiveConfig(): array
  {
    $config = $this->config['archive'] ?? [];

    // Shard period controls how data is partitioned into shard files and,
    // because a period is only archived once it is ENTIRELY older than
    // local_keep_days, how much extra data is held locally beyond the keep
    // window (up to one partial period). Weekly keeps a tighter local
    // footprint; monthly produces fewer, larger files. Only 'weekly' and
    // 'monthly' are supported; anything else falls back to monthly.
    $period = (string) ($config['shard_period'] ?? 'monthly');
    if (!in_array($period, ['weekly', 'monthly'], TRUE)) {
      $period = 'monthly';
    }

    return [
      'enabled' => (bool) ($config['enabled'] ?? FALSE),
      'local_keep_days' => (int) ($config['local_keep_days'] ?? 60),
      'longterm_storage' => (string) ($config['longterm_storage'] ?? ''),
      'shard_period' => $period,
      'include_in_queries' => (bool) ($config['include_in_queries'] ?? FALSE),
      'fail_on_unavailable' => (bool) ($config['fail_on_unavailable'] ?? TRUE),
    ];
  }

  /**
   * Get API retention limit in seconds.
   *
   * Returns the maximum time range that the SolarWinds API retains data.
   * This varies by license/plan. Attempts to fetch data older than this
   * limit will return no results.
   *
   * @return int Retention period in seconds (default: 14 days)
   */
  public function getApiRetentionLimit(): int
  {
    // Default to 14 days (2 weeks) if not configured
    $defaultDays = 14;
    $days = $this->config['api_retention_days'] ?? $defaultDays;
    return $days * 24 * 60 * 60;
  }

  /**
   * Get risk scoring configuration with defaults.
   *
   * Returns the complete risk scoring configuration including weights,
   * thresholds, and classification levels. Merges user configuration
   * with sensible defaults.
   *
   * @return array Risk scoring configuration
   */
  public function getRiskScoringConfig(): array
  {
    $defaults = [
      'weights' => [
        'origin_impact' => 0.30,
        'volume_rate' => 0.25,
        'attack_severity' => 0.20,
        'recency' => 0.20,
        'server_impact' => 0.05,
      ],
      // Gate the final score by volume so a small, slow probe can't ride the
      // ratio factors (origin/severity/recency) into a block recommendation.
      // The gate saturates to 1.0 once a campaign clears EITHER threshold, so
      // high-rate bursts and sustained campaigns keep their full score and only
      // low-count-and-low-rate one-offs are discounted.
      'volume_gate' => [
        'count_saturation' => 30,
        'rate_saturation' => 100,
      ],
      'origin_impact' => [
        'edge_blocked_100' => 0,
        'edge_blocked_high' => 20,
        'origin_50_percent' => 60,
        'origin_high' => 100,
      ],
      'volume_rate' => [
        'thresholds' => [
          ['rate' => 10, 'score' => 10],
          ['rate' => 50, 'score' => 30],
          ['rate' => 100, 'score' => 50],
          ['rate' => 500, 'score' => 70],
          ['rate' => 1000, 'score' => 85],
          ['rate' => 5000, 'score' => 100],
        ],
      ],
      'attack_severity' => [
        'critical_patterns' => ['sqli', 'cmdi', 'rce', 'pma'],
        'high_patterns' => ['xss', 'lfi', 'xxe', 'ssrf', 'ssti'],
        'medium_patterns' => ['file', 'boot', 'dbg', 'api'],
        'low_patterns' => ['scan', 'info'],
        'single_low' => 10,
        'single_medium' => 20,
        'single_high' => 40,
        'single_critical' => 60,
        'multiple_types_bonus' => 20,
        'three_plus_types_bonus' => 30,
      ],
      'recency' => [
        'active_now_hours' => 1,
        'active_now' => 100,
        'very_recent_hours' => 6,
        'very_recent' => 90,
        'recent_hours' => 24,
        'recent' => 70,
        'moderately_recent_hours' => 72,
        'moderately_recent' => 40,
        'aging_hours' => 168,
        'aging' => 20,
        'historical' => 0,
      ],
      'server_impact' => [
        'no_php_execution' => 0,
        'php_execution_404' => 30,
        'php_execution_40x' => 50,
        'php_errors_5xx' => 80,
        'php_success_200' => 100,
      ],
      'risk_levels' => [
        'critical' => 80,
        'high' => 60,
        'medium' => 40,
        'low' => 20,
        'noise' => 0,
      ],
    ];

    $configured = $this->config['risk_scoring'] ?? [];

    // Deep merge to allow partial overrides
    return $this->deepMerge($defaults, $configured);
  }

  /**
   * Deep merge two arrays recursively.
   *
   * Unlike array_merge_recursive, this preserves numeric keys and
   * replaces values instead of creating arrays for duplicates.
   *
   * @param array $defaults Default values
   * @param array $overrides Override values
   * @return array Merged array
   */
  protected function deepMerge(array $defaults, array $overrides): array
  {
    $result = $defaults;

    foreach ($overrides as $key => $value) {
      if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
        $result[$key] = $this->deepMerge($result[$key], $value);
      }
      else {
        $result[$key] = $value;
      }
    }

    return $result;
  }

  /**
   * Get risk scoring weights.
   *
   * Returns the weight factors for each risk scoring component.
   * Weights should sum to 1.0 for normalized scoring.
   *
   * @return array Weight factors for risk scoring
   */
  public function getRiskScoringWeights(): array
  {
    $config = $this->getRiskScoringConfig();
    return $config['weights'] ?? [];
  }

  /**
   * Get risk level thresholds.
   *
   * Returns score thresholds for classifying risk levels.
   *
   * @return array Risk level thresholds
   */
  public function getRiskLevels(): array
  {
    $config = $this->getRiskScoringConfig();
    return $config['risk_levels'] ?? [];
  }

  /**
   * Classify a risk score into a risk level.
   *
   * Converts a numeric risk score (0-100) into a classification
   * (critical, high, medium, low, noise) based on configured thresholds.
   *
   * @param float $score Risk score (0-100)
   * @return string Risk level classification
   */
  public function classifyRiskScore(float $score): string
  {
    $levels = $this->getRiskLevels();

    if ($score >= $levels['critical']) {
      return 'critical';
    }
    if ($score >= $levels['high']) {
      return 'high';
    }
    if ($score >= $levels['medium']) {
      return 'medium';
    }
    if ($score >= $levels['low']) {
      return 'low';
    }

    return 'noise';
  }
}
