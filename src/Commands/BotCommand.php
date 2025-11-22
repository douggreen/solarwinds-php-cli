<?php

/**
 * @file BotCommand.php
 * @brief Bot and automated crawler traffic analysis command
 *
 * @class BotCommand
 * @brief Analyzes bot and automated crawler traffic patterns with user agent filtering
 *
 * This command specializes in analyzing automated traffic patterns by filtering log entries
 * based on user agent strings. It provides bot term highlighting and frequency analysis
 * to help administrators understand crawler activity and automated traffic sources.
 *
 * @section command_purpose Command Purpose
 *
 * **Primary Functions:**
 * - Filter traffic by user agent patterns (default: "bot")
 * - Highlight bot-related terms in user agent strings
 * - Analyze frequency patterns of automated traffic
 * - Support custom user agent pattern matching
 *
 * **Use Cases:**
 * - Monitor search engine crawler activity
 * - Identify suspicious automated behavior
 * - Analyze bot traffic patterns for capacity planning
 * - Track specific crawler or monitoring service activity
 *
 * @section query_implementation Query Implementation
 *
 * **Search Pattern:**
 * - Uses field-specific matching: `{ json.req_user_agent:PATTERN }`
 * - Supports custom patterns via command argument
 * - Default pattern: "bot" (case-insensitive)
 * - Integrates with site filtering from configuration
 *
 * **Default Settings:**
 * - Time range: 15 minutes (shorter than other commands)
 * - Display options: ['ua'] for user agent analysis
 * - Bot highlighting: Automatic detection and coloring
 *
 * @section validation_logic Validation Logic
 *
 * **Redundancy Prevention:**
 * - Rejects --ua flag as redundant (command already focuses on user agents)
 * - Suggests alternative display options (--status, --country, --host)
 *
 * **Pattern Validation:**
 * - Requires minimum 2-character patterns
 * - Validates pattern length and content
 * - Provides meaningful error messages for invalid patterns
 *
 * @section example Usage Examples
 * @code{.bash}
 * # Basic bot traffic analysis (last 15 minutes)
 * solarwinds bot
 *
 * # Google bot activity analysis
 * solarwinds bot googlebot --day
 *
 * # Bot traffic with status code breakdown
 * solarwinds bot --1h --status
 *
 * # Archive.org crawler with minimum threshold
 * solarwinds bot archive-it --min-count=5
 *
 * # Site-specific bot analysis
 * solarwinds bot --site1 --country
 * @endcode
 *
 * @section display_integration Display Integration
 *
 * **Default Output:**
 * - User agent frequency analysis
 * - Bot term highlighting with color coding
 * - Sorted by frequency (highest first)
 *
 * **Extended Display Options:**
 * - Combines user agent data with other dimensions
 * - Automatic format selection based on options
 * - Fallback to user-agent-compatible formats
 *
 * @section bot_highlighting Bot Highlighting
 *
 * The command automatically highlights bot-related terms in user agent strings:
 * - Case-insensitive detection of "bot" substrings
 * - Word boundary detection for accurate highlighting
 * - Respects NO_COLOR environment variable
 * - Preserves original user agent string structure
 *
 * @see BaseSolarWindsCommand For base class implementation
 * @see DisplayService For bot highlighting logic
 * @see TimeSpecifications For 15-minute default time handling
 *
 * @note This command has a shorter default time range (15m) for focused bot analysis
 * @warning Large time ranges may produce overwhelming bot traffic results
 */

namespace SolarWinds\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Pbot Command - Analyzes bot and automated crawler traffic
 *
 * Specialized command for analyzing bot traffic patterns with user agent filtering,
 * bot term highlighting, and multi-dimensional analysis capabilities.
 */
class BotCommand extends BaseSolarWindsCommand
{
  protected string $defaultTime = '15m';
  protected array $defaultDisplayOptions = ['ua'];

  protected function configure(): void
  {
    $this
      ->setName('bot')
      ->setDescription('Analyze bot and automated crawler traffic patterns')
      ->setHelp('
        The <info>bot</info> command analyzes bot and automated crawler activity from SolarWinds logs.
        It filters for user agents containing specified terms (default: "bot") and provides bot highlighting.

        <comment>Examples:</comment>
        <info>solarwinds bot</info>                            # Bot traffic analysis (last 15m)
        <info>solarwinds bot crawler</info>                    # Crawler traffic analysis
        <info>solarwinds bot --1h --status</info>              # Bot traffic by status code
        <info>solarwinds bot spider --day --country</info>     # Spider bots by country
        <info>solarwinds bot --host --status</info>            # Bot traffic by host and status
        ')
      ->addArgument('agent', InputArgument::OPTIONAL, 'User agent pattern to search for', 'bot')
    ;

    // Call parent to set up common options.
    parent::configure();

    // bot has no additional options beyond the agent argument.
  }

  /**
   * Parse script-specific options for bot.
   */
  protected function parseScriptSpecificOptions(InputInterface $input): array
  {
    $options = [];

    // Get the user agent pattern to search for.
    $options['agent'] = $input->getArgument('agent');

    return $options;
  }

  /**
   * Build the SQL WHERE clause for bot traffic analysis.
   */
  protected function buildSearchQuery(array $options): array
  {
    $agent = $options['script_specific']['agent'];

    // Validate agent pattern.
    if (empty($agent) || strlen($agent) < 2) {
      throw new \InvalidArgumentException("Agent pattern must be at least 2 characters: $agent");
    }

    // Build SQL WHERE clause for user agent matching.
    return [
      'where' => 'req_user_agent LIKE :agent',
      'params' => [':agent' => '%' . $agent . '%'],
    ];
  }

  /**
   * Validate the SQL query and options for bot.
   */
  protected function validateQuery(array $sqlQuery, array $options): void
  {
    $explicitOptions = $options['display']['_explicit'] ?? [];

    // bot is already about user agents, so --ua flag is redundant.
    if (isset($explicitOptions['ua'])) {
      throw new \InvalidArgumentException("--ua option is redundant for bot (bot is already about user agents). Use other display options like --status, --country, --host instead");
    }
  }
}
