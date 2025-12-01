<?php

/**
 * @file BotCommand.php
 * @brief Bot and automated crawler traffic analysis command
 *
 * @class BotCommand
 * @brief Analyzes bot and automated crawler traffic patterns with user agent filtering
 *
 * This command specializes in analyzing automated traffic patterns by filtering log entries
 * based on user agent strings. It provides bot name extraction, IP verification, and frequency
 * analysis to help administrators understand crawler activity and detect bot spoofing.
 *
 * @section command_purpose Command Purpose
 *
 * **Primary Functions:**
 * - Filter traffic by user agent patterns (default: "bot")
 * - Extract bot names from user agent strings
 * - Verify bot IP addresses against published ranges
 * - Detect bot spoofing attempts
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
 * - Display columns: IP, Bot Name, Verification Status, User Agent
 * - Bot verification: Automatic CIDR and reverse DNS verification
 * - Caching: Smart verification result caching with TTL-based expiration
 *
 * @section validation_logic Validation Logic
 *
 * **Redundancy Prevention:**
 * - Rejects --cols=ua as redundant (command already focuses on user agents)
 * - Suggests alternative display columns (status, country, host)
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
 * solarwinds bot googlebot --time=1d
 *
 * # Bot traffic with status code breakdown
 * solarwinds bot --time=1h --cols=status
 *
 * # Archive.org crawler with minimum threshold
 * solarwinds bot archive-it --filter-min-count=5
 *
 * # Site-specific bot analysis
 * solarwinds bot --site=site1 --cols=country
 * @endcode
 *
 * @section display_integration Display Integration
 *
 * **Default Output:**
 * - Bot name and IP address grouping
 * - Bot verification status with color coding
 * - Sorted by frequency (highest first)
 *
 * **Extended Display Options:**
 * - Combines bot verification data with other dimensions
 * - Bot name extraction for easy identification
 * - Verification status indicators (verified/spoofed/unverified)
 * - Automatic format selection based on options
 *
 * @section bot_verification Bot IP Verification
 *
 * The command implements sophisticated bot verification:
 * - CIDR range matching against published bot IP ranges
 * - Reverse DNS verification with forward lookup validation
 * - Smart caching with content-based invalidation
 * - Two-tier TTL strategy (DNS TTL vs range version)
 *
 * @see BaseSolarWindsCommand For base class implementation
 * @see BotIpService For bot IP verification logic
 * @see DisplayService For display formatting
 * @see TimeSpecifications For 15-minute default time handling
 *
 * @note This command has a shorter default time range (15m) for focused bot analysis
 * @warning Large time ranges may produce overwhelming bot traffic results
 */

namespace SolarWinds\Commands;

use SolarWinds\Services\BlockingService;
use SolarWinds\Services\BotIpService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Bot Command - Analyzes bot and automated crawler traffic
 *
 * Specialized command for analyzing bot traffic patterns with user agent filtering,
 * bot name extraction, IP verification, spoofing detection, and multi-dimensional analysis.
 */
class BotCommand extends BaseSolarWindsCommand
{
  protected string $defaultTime = '15m';
  protected array $defaultDisplayOptions = ['ip', 'bot_name', 'verified'];

  /**
   * Bot IP verification statistics.
   */
  protected array $botStats = [
    'verified' => 0,
    'spoofed' => 0,
    'unverified' => 0,
    'total' => 0,
  ];

  protected function configure(): void
  {
    $this
      ->setName('bot')
      ->setDescription('Analyze bot and automated crawler traffic patterns')
      ->setHelp('
        The <info>bot</info> command analyzes bot and automated crawler activity from SolarWinds logs.
        It filters for user agents containing specified terms (default: "bot") and provides bot IP
        verification to distinguish legitimate bots from spoofed ones.

        <comment>Examples:</comment>
        <info>solarwinds bot</info>                                 # Bot traffic analysis (last 15m)
        <info>solarwinds bot googlebot --time=1d</info>             # Googlebot activity (last day)
        <info>solarwinds bot --verified-only</info>                 # Show only verified bots
        <info>solarwinds bot --spoofed-only</info>                  # Show only spoofed bots
        <info>solarwinds bot --time=1h --cols=ip,status</info>      # Bot traffic by IP and status

        <comment>Bot Verification:</comment>
        Bots are verified by checking IP addresses against known bot IP ranges:
        - <fg=green>✓ Verified</>: IP matches known bot ranges (e.g., real Googlebot)
        - <fg=red>✗ SPOOFED</>: Claims to be a bot but IP verification failed
        - <fg=yellow>? Unknown</>: Bot without verification capability
' . self::getTimeRangeHelp() . '
        ')
      ->addArgument('agent', InputArgument::OPTIONAL, 'User agent pattern to search for', 'bot')
      ->addOption('verified-only', NULL, InputOption::VALUE_NONE, 'Show only verified bots (IP verification passed)')
      ->addOption('spoofed-only', NULL, InputOption::VALUE_NONE, 'Show only spoofed bots (claiming to be bot but IP verification failed)')
      ->addOption('unverified-only', NULL, InputOption::VALUE_NONE, 'Show only unverified bots (no verification method available)')
    ;

    // Call parent to set up common options.
    parent::configure();
  }

  /**
   * Parse script-specific options for bot.
   */
  protected function parseScriptSpecificOptions(InputInterface $input): array
  {
    $options = [];

    // Get the user agent pattern to search for.
    $options['agent'] = $input->getArgument('agent');

    // Get bot verification filter options.
    $options['verified_only'] = $input->getOption('verified-only');
    $options['spoofed_only'] = $input->getOption('spoofed-only');
    $options['unverified_only'] = $input->getOption('unverified-only');

    return $options;
  }

  /**
   * Build the SQL WHERE clause for bot traffic analysis.
   */
  protected function buildSearchQuery(array $options): array
  {
    $agent = $options['script_specific']['agent'];

    // Validate agent pattern.
    $this->validateMinLength($agent, 2, 'Agent pattern');

    // If searching for "bot" (default), use known bot patterns from config.
    // This avoids false matches like "AppleWebKit" (contains "bot").
    if (strtolower($agent) === 'bot') {
      $trustedBots = $this->config->getTrustedBots();
      $conditions = [];
      $params = [];

      foreach ($trustedBots as $index => $botName) {
        $paramName = ":bot{$index}";
        $conditions[] = "req_user_agent LIKE {$paramName}";
        $params[$paramName] = '%' . $botName . '%';
      }

      return [
        'where' => '(' . implode(' OR ', $conditions) . ')',
        'params' => $params,
      ];
    }

    // For custom patterns, use direct matching.
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
    // No validation needed - allowing UA column for spoofing analysis.
  }

  /**
   * Filter and enrich results with bot IP verification data.
   *
   * Overrides parent to add bot verification status to each log entry.
   */
  protected function filterResults(array $logs, array $options): array
  {
    // First apply parent filters.
    $logs = parent::filterResults($logs, $options);

    // Initialize bot IP service.
    $botIpService = new BotIpService($this->databaseService);
    // Enable reverse DNS with batch parallel lookups (fast enough now).
    $blockingService = new BlockingService($this->config, $botIpService, TRUE);

    // Reset statistics.
    $this->botStats = [
      'verified' => 0,
      'spoofed' => 0,
      'unverified' => 0,
      'total' => 0,
    ];

    // Get filter options.
    $verifiedOnly = $options['script_specific']['verified_only'] ?? FALSE;
    $spoofedOnly = $options['script_specific']['spoofed_only'] ?? FALSE;
    $unverifiedOnly = $options['script_specific']['unverified_only'] ?? FALSE;

    // Pre-load ALL verification cache into memory for performance.
    // This avoids thousands of individual database queries.
    $botIpService->preloadVerificationCache();

    // Pre-collect all unique IP+botname combinations that need verification.
    // This allows us to batch DNS lookups for uncached entries.
    $uniqueIpBotPairs = [];
    foreach ($logs as $log) {
      $userAgent = $log['req_user_agent'] ?? $log['user_agent'] ?? '';
      $ip = $log['client_ip'] ?? $log['remote_addr'] ?? $log['ip'] ?? $log['remote_ip'] ?? '';

      if (!empty($ip) && !empty($userAgent)) {
        $botName = $this->extractBotNameFromUA($userAgent);
        $key = $ip . '|' . $botName;
        $uniqueIpBotPairs[$key] = ['ip' => $ip, 'bot_name' => $botName];
      }
    }

    // Batch verify uncached IPs (with parallel DNS lookups).
    if (!empty($uniqueIpBotPairs)) {
      $botIpService->batchVerifyBots($uniqueIpBotPairs, $this->io);
    }

    // Show progress bar for bot verification if not in JSON mode.
    $totalLogs = count($logs);
    $progressBar = NULL;
    if (!$this->jsonMode && $totalLogs > 100) {
      $this->io->writeln('<comment>Processing bot verification results...</comment>');
      $progressBar = $this->io->createProgressBar($totalLogs);
      $progressBar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');
      $progressBar->start();
    }

    // Cache for IP verification results to avoid redundant checks.
    $verificationCache = [];

    // Enrich each log with bot verification status.
    $enrichedLogs = [];
    $processed = 0;
    foreach ($logs as $log) {
      // No need to parse JSON - STORED columns already available at top level.

      $userAgent = $log['req_user_agent'] ?? $log['user_agent'] ?? '';
      $ip = $log['client_ip'] ?? $log['remote_addr'] ?? $log['ip'] ?? $log['remote_ip'] ?? '';

      // Extract bot name from user agent.
      $log['bot_name'] = $this->extractBotNameFromUA($userAgent);

      // Create cache key from IP and bot name.
      $cacheKey = $ip . '|' . $log['bot_name'];

      // Check in-memory cache first (should always hit after pre-verification).
      if (isset($verificationCache[$cacheKey])) {
        $classification = $verificationCache[$cacheKey];
      }
      else {
        // Classify the bot with IP verification (should hit BotIpService memory cache).
        $classification = $blockingService->classifyUserAgent($userAgent, $ip);
        $verificationCache[$cacheKey] = $classification;
      }

      // Convert classification to verification status.
      if ($classification === 'trusted_bot') {
        $verified = 'verified';
      }
      elseif ($classification === 'bot_spoofing') {
        $verified = 'spoofed';
      }
      elseif ($classification === 'bot_unverified') {
        $verified = 'unverified';
      }
      else {
        $verified = 'unknown';
      }

      // Update statistics.
      $this->botStats['total']++;
      if ($verified === 'verified') {
        $this->botStats['verified']++;
      }
      elseif ($verified === 'spoofed') {
        $this->botStats['spoofed']++;
      }
      else {
        $this->botStats['unverified']++;
      }

      // Apply filters early - skip enrichment for filtered logs.
      if ($verifiedOnly && $verified !== 'verified') {
        continue;
      }
      if ($spoofedOnly && $verified !== 'spoofed') {
        continue;
      }
      if ($unverifiedOnly && $verified !== 'unverified') {
        continue;
      }

      // Only enrich logs that pass the filter.
      $log['bot_verified'] = $verified;
      $enrichedLogs[] = $log;

      // Update progress bar every 100 logs for performance.
      $processed++;
      if ($progressBar && $processed % 100 === 0) {
        $progressBar->advance(100);
        // Dispatch pending signals to allow graceful interruption.
        if (function_exists('pcntl_signal_dispatch')) {
          pcntl_signal_dispatch();
        }
      }
    }

    // Finish progress bar (advance any remaining).
    if ($progressBar) {
      $remaining = $processed % 100;
      if ($remaining > 0) {
        $progressBar->advance($remaining);
      }
      $progressBar->finish();
      $this->io->newLine(2);
    }

    return $enrichedLogs;
  }

  /**
   * Extract bot name from user agent string.
   *
   * @param string $userAgent User agent string
   * @return string Bot name (e.g., "Googlebot", "Bingbot", "Unknown")
   */
  protected function extractBotNameFromUA(string $userAgent): string
  {
    // Map of bot patterns to display names.
    $botPatterns = [
      'googlebot' => 'Googlebot',
      'bingbot' => 'Bingbot',
      'duckduckbot' => 'DuckDuckBot',
      'facebookbot' => 'FacebookBot',
      'facebookexternalhit' => 'FacebookBot',
      'ahrefsbot' => 'AhrefsBot',
      'semrushbot' => 'SemrushBot',
      'yandexbot' => 'YandexBot',
      'claudebot' => 'ClaudeBot',
      'baiduspider' => 'Baiduspider',
      'applebot' => 'Applebot',
      'slackbot' => 'Slackbot',
      'twitterbot' => 'Twitterbot',
      'linkedinbot' => 'LinkedInBot',
      'pinterestbot' => 'PinterestBot',
      'whatsapp' => 'WhatsApp',
      'telegrambot' => 'TelegramBot',
      'discordbot' => 'DiscordBot',
    ];

    $userAgentLower = strtolower($userAgent);
    foreach ($botPatterns as $pattern => $displayName) {
      if (stripos($userAgentLower, $pattern) !== FALSE) {
        return $displayName;
      }
    }

    return 'Unknown';
  }

  /**
   * Override executeSearch to display bot verification summary.
   */
  protected function executeSearch(array $sqlQuery, array $options): int
  {
    // Call parent to execute the search and display results.
    $result = parent::executeSearch($sqlQuery, $options);

    // Display bot verification summary if we have statistics.
    if (!$this->jsonMode && $this->botStats['total'] > 0) {
      $this->io->newLine();
      $this->io->section('Bot Verification Summary');

      $verified = $this->botStats['verified'];
      $spoofed = $this->botStats['spoofed'];
      $unverified = $this->botStats['unverified'];
      $total = $this->botStats['total'];

      $verifiedPct = $total > 0 ? round(($verified / $total) * 100, 1) : 0;
      $spoofedPct = $total > 0 ? round(($spoofed / $total) * 100, 1) : 0;
      $unverifiedPct = $total > 0 ? round(($unverified / $total) * 100, 1) : 0;

      $this->io->text([
        sprintf('<fg=green>✓ Verified:</fg=green>   %s requests (%s%%)', number_format($verified), $verifiedPct),
        sprintf('<fg=red>✗ Spoofed:</fg=red>    %s requests (%s%%)', number_format($spoofed), $spoofedPct),
        sprintf('<fg=yellow>? Unknown:</fg=yellow>    %s requests (%s%%)', number_format($unverified), $unverifiedPct),
        sprintf('<comment>Total:</comment>        %s requests', number_format($total)),
      ]);
    }

    return $result;
  }
}
