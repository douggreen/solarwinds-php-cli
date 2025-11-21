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
 * - base_url: Region-specific SolarWinds API endpoint
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
 * base_url: "https://api.na-01.cloud.solarwinds.com"
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
 *   errors: 'search "error" --status --1h'
 *   quickbot: 'bot --1h --ua'
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
 * @warning API token and base_url are required for proper operation
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

  public function __construct(protected string $configPath = '')
  {
    $this->configPath = $this->configPath ?: ($_SERVER['HOME'] . '/.solarwinds.yml');
    $this->loadConfiguration();
  }

  /**
   * Load configuration from YAML file.
   */
  protected function loadConfiguration(): void
  {
    if (!file_exists($this->configPath)) {
      $this->config = [
        'token' => '',
        'base_url' => '',  // No default - force users to configure.
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
      'base_url' => '',  // No default - must be configured.
      'debug' => FALSE,
      'validate' => FALSE,
      'progress' => TRUE,
    ], $this->config);

    if (!empty($this->config['api_base_url']) && empty($this->config['base_url'])) {
      $this->config['base_url'] = $this->config['api_base_url'];
    }
  }

  /**
   * Get debug mode setting.
   */
  public function isDebugEnabled(): bool
  {
    return $this->getBooleanValue('debug');
  }


  /**
   * Get progress mode setting.
   */
  public function isProgressEnabled(): bool
  {
    return $this->getBooleanValue('progress');
  }

  /**
   * Helper to convert various boolean representations to actual boolean.
   */
  protected function getBooleanValue(string $key): bool
  {
    $value = $this->config[$key] ?? FALSE;

    if (is_bool($value)) {
      return $value;
    }

    if (is_string($value)) {
      $lower = strtolower(trim($value));
      return in_array($lower, ['TRUE', '1', 'yes', 'on']);
    }

    if (is_numeric($value)) {
      return (int) $value === 1;
    }

    return FALSE;
  }

  /**
   * Get site configurations from YAML.
   */
  public function getSites(): array
  {
    return $this->config['sites'] ?? [];
  }

  /**
   * Get site host mappings for command option generation.
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
   * Get hostname to display label mappings for output shortening.
   */
  public function getHostDisplayMappings(): array
  {
    $sites = $this->getSites();
    $mappings = [];

    foreach ($sites as $hostname => $siteConfig) {
      $label = $siteConfig['label'] ?? NULL;
      if ($label) {
        $cleanHost = preg_replace('/^www\./', '', $hostname);
        $mappings[$cleanHost] = $label;
      }
    }

    return $mappings;
  }

  /**
   * Get full configuration array.
   */
  public function getConfig(): array
  {
    return $this->config;
  }

  /**
   * Get a specific configuration value.
   */
  public function get(string $key, mixed $default = NULL): mixed
  {
    return $this->config[$key] ?? $default;
  }

  /**
   * Get configured aliases.
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
      'min_requests' => 100,
      'min_duration_hours' => 2,
      'high_confidence_40x_ratio' => 0.8,
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
}
