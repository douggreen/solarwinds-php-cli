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
}
