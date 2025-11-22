<?php

/**
 * @file SearchCommand.php
 * @brief General-purpose log search command with flexible query syntax
 *
 * @class SearchCommand
 * @brief Provides flexible log search capabilities with customizable field extraction
 *
 * This command serves as the general-purpose search interface for the SolarWinds log
 * analysis system. It supports both simple text searches and complex field-specific
 * queries with flexible output formatting options.
 *
 * @section command_purpose Command Purpose
 *
 * **Primary Functions:**
 * - Free-text search across all log content and fields
 * - Field-specific query construction with JSON field syntax
 * - Flexible result filtering and display formatting
 * - Integration with all standard display options
 *
 * **Use Cases:**
 * - Ad-hoc log analysis and investigation
 * - Troubleshooting specific error conditions
 * - General pattern matching across log data
 * - Custom analysis not covered by specialized commands
 *
 * @section query_flexibility Query Flexibility
 *
 * **Query Input Methods:**
 * - Command argument: `search "error message"`
 * - Explicit option: `search --query="complex query"`
 * - Filter-only mode: `search --ip-filter=192.168.1.1`
 *
 * **Query Types Supported:**
 * - Simple text: `"database error"`
 * - Field-specific: `{ json.resp_status:500 }`
 * - Complex combinations: `"error" AND { json.host:example.com }`
 * - Filter-driven: Relies on global filters when no explicit query
 *
 * @section validation_logic Validation Logic
 *
 * **Query Requirements:**
 * - Requires either search term argument, --query option, or active filters
 * - Validates that at least one search criterion is provided
 * - Provides helpful error messages for missing search criteria
 *
 * **Filter Integration:**
 * - Accepts empty query when global filters are active
 * - Combines search terms with filter options automatically
 * - Site filtering integration from configuration
 *
 * @section display_options Display Options
 *
 * **Default Format:**
 * - Simple field extraction (usually host-based grouping)
 * - Configurable field selection for custom output
 * - Automatic format resolution based on display flags
 *
 * **Extended Options:**
 * - Full integration with all standard display dimensions
 * - Automatic regrouping for complex option combinations
 * - Fallback to appropriate formats for unsupported combinations
 *
 * @section example Usage Examples
 * @code{.bash}
 * # Simple text search
 * solarwinds search "database error" --1h
 *
 * # Field-specific query
 * solarwinds search --query="{ json.resp_status:500 }" --day
 *
 * # Search with display options
 * solarwinds search "timeout" --status --country --2h
 *
 * # Filter-only search
 * solarwinds search --ip-filter=192.168.1.100 --1h
 *
 * # Complex query with custom fields
 * solarwinds search "login failed" --fields=".host,.req_uri" --day
 * @endcode
 *
 * @section field_extraction Field Extraction
 *
 * **Custom Field Selection:**
 * - --fields option for custom jq field selectors
 * - Defaults to .orig_host for simple host-based grouping
 * - Ignored when standard display options are used
 * - Supports complex jq expressions for advanced extraction
 *
 * **Field Format Examples:**
 * - Single field: `.orig_host`
 * - Multiple fields: `.orig_host,.req_uri`
 * - Complex expressions: `.orig_host + " - " + .req_uri`
 *
 * @section integration_features Integration Features
 *
 * **Site Filtering:**
 * - Automatic integration with configured site mappings
 * - Support for --site-name flags from configuration
 * - Seamless filtering across multiple sites
 *
 * **Global Filters:**
 * - IP address filtering: --ip-filter
 * - Country filtering: --country-filter
 * - Status code filtering: --status-code-filter
 * - User agent filtering: --user-agent-filter
 * - Path filtering: --path-filter
 *
 * @see BaseSolarWindsCommand For base class implementation
 * @see DisplayService For result formatting options
 * @see ConfigurationService For site filtering integration
 *
 * @note This command serves as the fallback for searches not covered by specialized commands
 * @warning Complex queries may require careful escaping of special characters
 */

namespace SolarWinds\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Search Command - General log search utility
 *
 * Flexible command for searching arbitrary text patterns across SolarWinds logs
 * with configurable query syntax and display options.
 */
class SearchCommand extends BaseSolarWindsCommand
{
  protected string $defaultTime = 'day';
  protected array $defaultDisplayOptions = ['host'];

  protected function configure(): void
  {
    $this
      ->setName('search')
      ->setDescription('Search for arbitrary patterns in SolarWinds logs with flexible query syntax')
      ->setHelp('
        The <info>search</info> command provides flexible log search capabilities for finding
        arbitrary patterns across SolarWinds logs using any supported query format.

        <comment>Examples:</comment>
        <info>solarwinds search "error" --1h</info>                     # Text search for "error"
        <info>solarwinds search "Login attempt failed" --day</info>     # Text search for login failures
        <info>solarwinds search --query="{ json.resp_status:404 }" --2h</info> # JSON field search
        <info>solarwinds search timeout --host --ip</info>              # Text search by host and IP
        <info>solarwinds search "api error" --country</info>            # Text search by country
        ')
      ->addArgument('search_term', InputArgument::OPTIONAL, 'Text pattern to search for in logs')
      ->addOption('query', NULL, InputOption::VALUE_REQUIRED, 'SolarWinds query syntax (will be translated to SQL)')
      ->addOption('sql-where', NULL, InputOption::VALUE_REQUIRED, 'Direct SQL WHERE clause (advanced)')
    ;

    // Call parent to set up common options.
    parent::configure();
  }

  /**
   * Parse script-specific options for search.
   */
  protected function parseScriptSpecificOptions(InputInterface $input): array
  {
    $options = [];

    // Get SQL WHERE clause, SolarWinds query, or search term.
    $sqlWhere = $input->getOption('sql-where');
    $solarwindsQuery = $input->getOption('query');
    $searchTerm = $input->getArgument('search_term');

    if ($sqlWhere) {
      $options['sql_where'] = $sqlWhere;
      $options['query_type'] = 'sql';
    }
    elseif ($solarwindsQuery) {
      $options['query'] = $solarwindsQuery;
      $options['query_type'] = 'solarwinds';
    }
    elseif ($searchTerm) {
      $options['query'] = $searchTerm;
      $options['query_type'] = 'text';
    }
    else {
      $options['query'] = NULL;
      $options['query_type'] = NULL;
    }

    return $options;
  }

  /**
   * Build the SQL WHERE clause for general log search.
   */
  protected function buildSearchQuery(array $options): array
  {
    $queryType = $options['script_specific']['query_type'];
    $sqlWhere = $options['script_specific']['sql_where'] ?? NULL;
    $query = $options['script_specific']['query'] ?? NULL;

    // Check if any global filters are applied that could provide filtering
    $hasFilters = !empty($options['filters']['country_filter']) ||
                  !empty($options['filters']['city_filter']) ||
                  !empty($options['filters']['status_code_filter']) ||
                  !empty($options['filters']['user_agent_filter']) ||
                  !empty($options['filters']['path_filter']) ||
                  !empty($options['filters']['ip_filter']);

    if (empty($queryType) && !$hasFilters) {
      throw new \InvalidArgumentException("Search query is required. Provide either a search term argument, --query option, --sql-where, or filter options like --ip-filter, --country-filter, etc.");
    }

    // Handle direct SQL WHERE clause.
    if ($queryType === 'sql') {
      return [
        'where' => $sqlWhere,
        'params' => [],  // SQL WHERE should use named params - not supported yet
      ];
    }

    // Handle SolarWinds query syntax - translate to SQL.
    if ($queryType === 'solarwinds') {
      $translator = new \SolarWinds\Services\QueryTranslator();
      return $translator->translate($query);
    }

    // Handle simple text search.
    if ($queryType === 'text') {
      if (strlen(trim($query)) < 2) {
        throw new \InvalidArgumentException("Search query must be at least 2 characters: '$query'");
      }
      return [
        'where' => 'data LIKE :search',
        'params' => [':search' => '%' . $query . '%'],
      ];
    }

    // No query but has filters - return match-all.
    return [
      'where' => '1=1',
      'params' => [],
    ];
  }

  /**
   * Validate the SQL query and options for search.
   */
  protected function validateQuery(array $sqlQuery, array $options): void
  {
    // Validation is now done in buildSearchQuery.
  }
}
