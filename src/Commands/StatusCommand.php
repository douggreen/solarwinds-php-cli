<?php

/**
 * @file StatusCommand.php
 * @brief HTTP status code analysis command with built-in shortcuts and color coding
 *
 * @class StatusCommand
 * @brief Analyzes HTTP status code patterns with intelligent shortcuts and visual formatting
 *
 * This command specializes in HTTP status code analysis, providing built-in shortcuts
 * for common status codes and ranges, along with color-coded output for easy pattern
 * recognition. It serves as the primary tool for web traffic health monitoring.
 *
 * @section command_purpose Command Purpose
 *
 * **Primary Functions:**
 * - HTTP status code frequency analysis
 * - Color-coded status visualization (2xx=green, 3xx=yellow, 4xx=orange, 5xx=red)
 * - Built-in shortcuts for common status patterns
 * - Flexible filtering and grouping options
 *
 * **Use Cases:**
 * - Web traffic health monitoring
 * - Error pattern analysis and troubleshooting
 * - Performance monitoring and alerting
 * - Traffic pattern analysis across different dimensions
 *
 * @section status_shortcuts Status Code Shortcuts
 *
 * **Individual Status Codes:**
 * - --200, --301, --404, --500, etc.: Specific status code analysis
 * - Automatic query construction for exact matches
 * - Integration with all display options
 *
 * **Status Range Shortcuts:**
 * - --2 or --2xx: All 2xx success responses
 * - --3 or --3xx: All 3xx redirection responses
 * - --4 or --4xx: All 4xx client error responses
 * - --5 or --5xx: All 5xx server error responses
 *
 * **Special Patterns:**
 * - --errors: Combination of 4xx and 5xx responses
 * - --success: All 2xx and 3xx responses
 * - Default behavior: All status codes with frequency analysis
 *
 * @section query_implementation Query Implementation
 *
 * **Status Code Filtering:**
 * - Exact matches: `{ json.resp_status:404 }`
 * - Range patterns: `{ json.resp_status:4* }` for 4xx codes
 * - Negative filtering: Excludes static files and irrelevant content
 * - Site filtering integration from configuration
 *
 * **Default Settings:**
 * - Time range: 1 hour (balanced for status monitoring)
 * - Display format: Status code frequency with color coding
 * - Minimum count: 1 (show all status occurrences)
 *
 * @section validation_logic Validation Logic
 *
 * **Status Code Validation:**
 * - Validates status code format (3-digit numbers)
 * - Prevents invalid status code combinations
 * - Provides meaningful error messages for invalid inputs
 *
 * **Display Option Conflicts:**
 * - Prevents redundant --status flag when analyzing specific codes
 * - Suggests appropriate alternative display options
 * - Validates logical combinations of display flags
 *
 * @section example Usage Examples
 * @code{.bash}
 * # General status code analysis
 * solarwinds status --1h
 *
 * # Specific error analysis
 * solarwinds status --404 --day --host
 *
 * # Server error patterns
 * solarwinds status --5xx --2h --country
 *
 * # Client error with path analysis
 * solarwinds status --4 --path --1h
 *
 * # Custom status code
 * solarwinds status --503 --by-hour --day
 * @endcode
 *
 * @section color_coding Color Coding System
 *
 * **Status Code Colors:**
 * - 2xx (Success): Green - indicates healthy traffic
 * - 3xx (Redirection): Yellow - normal redirect behavior
 * - 4xx (Client Error): Orange - client-side issues
 * - 5xx (Server Error): Red - server-side problems requiring attention
 *
 * **Accessibility:**
 * - Respects NO_COLOR environment variable
 * - Provides clear visual distinction without relying solely on color
 * - Maintains readability in monochrome environments
 *
 * @section display_integration Display Integration
 *
 * **Default Output:**
 * - Status code frequency analysis
 * - Color-coded status visualization
 * - Sorted by frequency or status code
 *
 * **Extended Display Options:**
 * - Host-based grouping with status breakdown
 * - Geographic analysis of status patterns
 * - Time-based status trend analysis
 * - Path-based error pattern identification
 *
 * @section monitoring_features Monitoring Features
 *
 * **Health Indicators:**
 * - High 5xx rates indicate server problems
 * - Unusual 4xx patterns may indicate broken links or attacks
 * - 3xx analysis helps understand redirect behavior
 * - 2xx baseline helps establish normal traffic patterns
 *
 * **Trend Analysis:**
 * - Hourly breakdown for identifying patterns
 * - Geographic distribution for CDN/routing analysis
 * - Host-specific patterns for load balancing insights
 *
 * @see BaseSolarWindsCommand For base class implementation
 * @see DisplayService For color coding and formatting
 * @see TimeSpecifications For time range handling
 *
 * @note Color coding provides immediate visual feedback for status health assessment
 * @warning High 5xx error rates may indicate serious server issues requiring immediate attention
 */

namespace SolarWinds\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Pstatus Command - Analyzes HTTP status code patterns
 *
 * Specialized command for analyzing HTTP status code distributions with
 * configurable status code filtering and multi-dimensional analysis.
 */
class StatusCommand extends BaseSolarWindsCommand
{
  protected string $defaultTime = '1d';
  protected array $defaultDisplayOptions = ['host', 'status'];

  protected function configure(): void
  {
    $this
      ->setName('status')
      ->setDescription('Analyze HTTP status code patterns with configurable filtering')
      ->setHelp('
        The <info>status</info> command analyzes HTTP status code patterns from SolarWinds logs.
        It excludes static file requests and provides insights into response status distributions.

        <comment>Examples:</comment>
        <info>solarwinds status --time=1h</info>                    # All status codes (last hour)
        <info>solarwinds status --code=4 --host</info>              # 4xx errors by host
        <info>solarwinds status --code=404 --path</info>            # 404 errors by path
        <info>solarwinds status --code=5 --country</info>           # 5xx errors by country
        <info>solarwinds status --host --region</info>              # Status codes by host and region
        ');

    // Call parent to set up common options (includes --status-code-filter).
    parent::configure();

    // Add status code filter option.
    $this->addOption(
      'code',
      NULL,
      InputOption::VALUE_REQUIRED,
      'Filter by status code: 200, 404, 4 (4xx), 5 (5xx), etc.'
    );
  }

  /**
   * Parse script-specific options for status.
   */
  protected function parseScriptSpecificOptions(InputInterface $input): array
  {
    $options = [];

    // Get status code from --code option.
    $options['status_filter'] = $input->getOption('code') ?: NULL;

    return $options;
  }

  /**
   * Build the SQL WHERE clause for status code analysis.
   */
  protected function buildSearchQuery(array $options): array
  {
    // Use shortcut if provided, otherwise fall back to global --status-code-filter.
    $statusFilter = $options['script_specific']['status_filter'] ?: $options['filters']['status_code_filter'];

    $conditions = [];
    $params = [];

    if ($statusFilter) {
      // Validate status filter format.
      if (!preg_match('/^\d{1,3}$/', $statusFilter)) {
        throw new \InvalidArgumentException("Invalid status filter format: $statusFilter. Must be 1-3 digits");
      }

      // Build status code filter based on the filter type.
      if (strlen($statusFilter) === 3 && ctype_digit($statusFilter)) {
        // Exact 3-digit code match.
        $conditions[] = 'resp_status = :status';
        $params[':status'] = (int) $statusFilter;
      }
      elseif (strlen($statusFilter) === 2 && ctype_digit($statusFilter)) {
        // 2-digit prefix match (e.g., "50" matches 500, 501, 502, etc.).
        $conditions[] = 'resp_status BETWEEN :status_start AND :status_end';
        $params[':status_start'] = (int) ($statusFilter . '0');
        $params[':status_end'] = (int) ($statusFilter . '9');
      }
      elseif (strlen($statusFilter) === 1 && ctype_digit($statusFilter)) {
        // 1-digit prefix match (e.g., "5" matches 500-599).
        $conditions[] = 'resp_status BETWEEN :status_start AND :status_end';
        $params[':status_start'] = (int) ($statusFilter . '00');
        $params[':status_end'] = (int) ($statusFilter . '99');
      }
      else {
        throw new \InvalidArgumentException("Invalid status filter format: $statusFilter");
      }
    }

    // Add site filtering if specified.
    if (!empty($options['sites'])) {
      $siteConditions = [];
      $paramIndex = 0;
      foreach ($options['sites'] as $site) {
        // Get all hostnames for this site (handles multiple hosts per site).
        $hostnames = $this->config->getHostnamesForSite($site);
        foreach ($hostnames as $hostname) {
          $paramName = ':site' . $paramIndex++;
          $siteConditions[] = "orig_host = $paramName";
          $params[$paramName] = $hostname;
        }
      }
      if (!empty($siteConditions)) {
        $conditions[] = '(' . implode(' OR ', $siteConditions) . ')';
      }
    }

    // Exclude static files.
    $conditions[] = 'req_uri NOT LIKE :static_files';
    $params[':static_files'] = '%/sites/default/files%';

    return [
      'where' => implode(' AND ', $conditions),
      'params' => $params,
    ];
  }

  /**
   * Validate the SQL query and options for status.
   */
  protected function validateQuery(array $sqlQuery, array $options): void
  {
    $explicitOptions = $options['display']['_explicit'] ?? [];

    // status always shows status codes, so --status flag is redundant/forbidden.
    if (isset($explicitOptions['status'])) {
      throw new \InvalidArgumentException("--status option is redundant for status (always shows status codes). Use other display options to add dimensions to status code analysis");
    }

    // Validate status filter format if provided.
    $statusFilter = $options['script_specific']['status_filter'];
    if ($statusFilter) {
      // Allow 1-digit (2, 3, 4, 5), 2-digit (20, 30, 40, etc.), or 3-digit (200, 404, etc.).
      if (!preg_match('/^\d{1,3}$/', $statusFilter)) {
        throw new \InvalidArgumentException("Invalid status filter format: $statusFilter. Must be 1-3 digits");
      }

      // Additional validation for logical ranges.
      if (strlen($statusFilter) === 1) {
        $digit = (int) $statusFilter;
        if ($digit < 1 || $digit > 5) {
          throw new \InvalidArgumentException("Invalid status range: {$statusFilter}xx. Status codes must start with 1-5");
        }
      }
      elseif (strlen($statusFilter) === 2) {
        $firstDigit = (int) $statusFilter[0];
        if ($firstDigit < 1 || $firstDigit > 5) {
          throw new \InvalidArgumentException("Invalid status range: {$statusFilter}x. Status codes must start with 1-5");
        }
      }
      elseif (strlen($statusFilter) === 3) {
        $firstDigit = (int) $statusFilter[0];
        if ($firstDigit < 1 || $firstDigit > 5) {
          throw new \InvalidArgumentException("Invalid status code: $statusFilter. Status codes must start with 1-5");
        }
      }
    }
  }
}
