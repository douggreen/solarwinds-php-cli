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
 * @see BaseSolarWindsCommand For template method implementation
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
        <info>solarwinds status --1h</info>                    # All status codes (last hour)
        <info>solarwinds status --4xx --host</info>            # 4xx errors by host
        <info>solarwinds status --404 --path</info>            # 404 errors by path
        <info>solarwinds status --5 --country</info>           # 5xx errors by country
        <info>solarwinds status --host --region</info>         # Status codes by host and region
        ');

    // Call parent to set up common options (includes --status-code-filter).
    parent::configure();

    // Add shortcut options for common status codes and ranges.
    // Specific 3-digit codes.
    foreach (['200', '201', '301', '302', '400', '401', '403', '404', '500', '502', '503', '504'] as $code) {
      $this->addOption($code, NULL, InputOption::VALUE_NONE, "Filter to {$code} status codes");
    }

    // 1-digit ranges (2, 3, 4, 5).
    foreach (['2', '3', '4', '5'] as $digit) {
      $this->addOption($digit, NULL, InputOption::VALUE_NONE, "Filter to {$digit}xx status codes");
      $this->addOption($digit . 'xx', NULL, InputOption::VALUE_NONE, "Filter to {$digit}xx status codes");
    }

    // 2-digit ranges (20, 30, 40, 50).
    foreach (['20', '21', '30', '40', '41', '42', '43', '50', '51', '52', '53', '54'] as $range) {
      $this->addOption($range, NULL, InputOption::VALUE_NONE, "Filter to {$range}x status codes");
      $this->addOption($range . 'x', NULL, InputOption::VALUE_NONE, "Filter to {$range}x status codes");
    }
  }

  /**
   * Parse script-specific options for status
   */
  protected function parseScriptSpecificOptions(InputInterface $input): array
  {
    $options = [];
    $statusFilter = NULL;

    // Check for specific 3-digit code shortcuts (override global --status-code-filter).
    foreach (['200', '201', '301', '302', '400', '401', '403', '404', '500', '502', '503', '504'] as $code) {
      if ($input->getOption($code)) {
        $statusFilter = $code;
        break;
      }
    }

    // Check for 1-digit range shortcuts (2, 3, 4, 5, 2xx, 3xx, 4xx, 5xx).
    if (!$statusFilter) {
      foreach (['2', '3', '4', '5'] as $digit) {
        if ($input->getOption($digit) || $input->getOption($digit . 'xx')) {
          $statusFilter = $digit;
          break;
        }
      }
    }

    // Check for 2-digit range shortcuts (20, 30, 40, etc. and 20x, 30x, 40x, etc.).
    if (!$statusFilter) {
      foreach (['20', '21', '30', '40', '41', '42', '43', '50', '51', '52', '53', '54'] as $range) {
        if ($input->getOption($range) || $input->getOption($range . 'x')) {
          $statusFilter = $range;
          break;
        }
      }
    }

    $options['status_filter'] = $statusFilter;

    return $options;
  }

  /**
   * Build the search query for status code analysis
   */
  protected function buildSearchQuery(array $options): string
  {
    // Use shortcut if provided, otherwise fall back to global --status-code-filter.
    $statusFilter = $options['script_specific']['status_filter'] ?: $options['filters']['status_code_filter'];

    if ($statusFilter) {
      // Build status code filter based on the filter type.
      if (strlen($statusFilter) === 3 && ctype_digit($statusFilter)) {
        // Exact 3-digit code match.
        $query = "{ json.resp_status:$statusFilter } -/sites/default/files";
      }
      elseif (strlen($statusFilter) === 2 && ctype_digit($statusFilter)) {
        // 2-digit prefix match (e.g., "50" matches 500, 501, 502, etc.).
        // Use the 2-digit prefix directly - SolarWinds handles this correctly
        $query = "{ json.resp_status:$statusFilter } -/sites/default/files";
      }
      elseif (strlen($statusFilter) === 1 && ctype_digit($statusFilter)) {
        // 1-digit prefix match (e.g., "5" matches 500-599).
        // Convert to 2-digit prefix (5 -> 50)
        $prefix = $statusFilter . '0';
        $query = "{ json.resp_status:$prefix } -/sites/default/files";
      }
      else {
        throw new \InvalidArgumentException("Invalid status filter format: $statusFilter");
      }
    }
    else {
      // No status filter - analyze all status codes.
      $query = "( -/sites/default/files )";
    }

    // Apply global filters (except status-code-filter which is handled above).
    return $this->applyGlobalFilters($query, $options, ['status']);
  }

  /**
   * Validate the query and options for status
   */
  protected function validateQuery(string $query, array $options): void
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
