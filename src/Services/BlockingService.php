<?php

/**
 * @file BlockingService.php
 * @brief IP blocking decision logic with allowlist, bot detection, and confidence scoring
 *
 * @class BlockingService
 * @brief Provides intelligent blocking decisions for exploit campaigns
 *
 * This service implements comprehensive blocking logic that considers multiple
 * factors to avoid blocking legitimate traffic while identifying real threats.
 */

namespace SolarWinds\Services;

/**
 * Blocking Service
 *
 * Handles blocking decision logic including IP allowlist checking,
 * bot classification, and confidence scoring.
 */
class BlockingService
{
  /**
   * Constructor.
   *
   * @param ConfigurationService $config Configuration service for allowlist and bot patterns
   * @param BotIpService|null $botIpService Bot IP verification service (optional)
   */
  public function __construct(
    protected ConfigurationService $config,
    protected ?BotIpService $botIpService = NULL
  ) {
  }

  /**
   * Check if an IP address is in the allowlist.
   *
   * Supports both individual IPs and CIDR ranges.
   *
   * @param string $ip IP address to check
   * @return bool TRUE if IP is in allowlist (should never be blocked)
   */
  public function isAllowlisted(string $ip): bool
  {
    $allowlist = $this->config->getBlockingAllowlist();

    foreach ($allowlist as $entry) {
      // Check if entry is CIDR notation
      if (strpos($entry, '/') !== FALSE) {
        if ($this->ipInCidr($ip, $entry)) {
          return TRUE;
        }
      }
      else {
        // Direct IP match
        if ($ip === $entry) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Check if an IP address is within a CIDR range.
   *
   * @param string $ip IP address to check
   * @param string $cidr CIDR notation (e.g., "10.0.0.0/8")
   * @return bool TRUE if IP is within the CIDR range
   */
  protected function ipInCidr(string $ip, string $cidr): bool
  {
    list($subnet, $mask) = explode('/', $cidr);

    // Convert IP and subnet to long integers
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);

    if ($ipLong === FALSE || $subnetLong === FALSE) {
      // Invalid IP or subnet
      return FALSE;
    }

    // Calculate netmask
    $netmask = -1 << (32 - (int) $mask);

    // Check if IP is in subnet
    return ($ipLong & $netmask) === ($subnetLong & $netmask);
  }

  /**
   * Classify a user-agent string with optional IP verification.
   *
   * When IP is provided, performs bot IP verification to detect spoofing.
   *
   * @param string $userAgent User-agent string
   * @param string $ip IP address (optional, enables bot verification)
   * @return string Classification: 'trusted_bot', 'bot_spoofing', 'suspicious', or 'unknown'
   */
  public function classifyUserAgent(string $userAgent, string $ip = ''): string
  {
    if (empty($userAgent)) {
      return 'suspicious';
    }

    $trustedBots = $this->config->getTrustedBots();

    // Check if user-agent matches any trusted bot pattern.
    foreach ($trustedBots as $botPattern) {
      if (stripos($userAgent, $botPattern) !== FALSE) {
        // If IP verification is available, verify the bot IP.
        if (!empty($ip) && $this->botIpService !== NULL) {
          $botName = $this->extractBotName($userAgent, $botPattern);
          $verification = $this->botIpService->verifyBot($ip, $botName);

          if (!$verification['verified']) {
            // Bot UA matches but IP doesn't verify - spoofing detected.
            return 'bot_spoofing';
          }
        }

        return 'trusted_bot';
      }
    }

    // Check for suspicious patterns.
    $suspiciousPatterns = [
      'python-requests',
      'curl/',
      'wget',
      'scanner',
      'bot' => FALSE, // Only suspicious if NOT in trusted list
      'crawler' => FALSE,
    ];

    foreach ($suspiciousPatterns as $pattern => $alwaysSuspicious) {
      if (is_int($pattern)) {
        $pattern = $alwaysSuspicious;
        $alwaysSuspicious = TRUE;
      }

      if ($alwaysSuspicious && stripos($userAgent, $pattern) !== FALSE) {
        return 'suspicious';
      }
    }

    return 'unknown';
  }

  /**
   * Extract bot name from user-agent string.
   *
   * Maps UA patterns to bot names used by BotIpService.
   *
   * @param string $userAgent Full user-agent string
   * @param string $botPattern Matched bot pattern from config
   * @return string Bot name for BotIpService lookup
   */
  protected function extractBotName(string $userAgent, string $botPattern): string
  {
    // Map common bot patterns to normalized bot names.
    $botMap = [
      'googlebot' => 'googlebot',
      'bingbot' => 'bingbot',
      'duckduckbot' => 'duckduckbot',
      'facebookbot' => 'facebookbot',
      'ahrefsbot' => 'ahrefsbot',
      'semrushbot' => 'semrushbot',
      'yandexbot' => 'yandexbot',
      'claudebot' => 'claudebot',
    ];

    $botPatternLower = strtolower($botPattern);

    // Check for exact matches.
    if (isset($botMap[$botPatternLower])) {
      return $botMap[$botPatternLower];
    }

    // Default to lowercase pattern.
    return $botPatternLower;
  }
}
