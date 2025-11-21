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
  public function __construct(protected ConfigurationService $config)
  {
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
   * Classify a user-agent string.
   *
   * @param string $userAgent User-agent string
   * @return string Classification: 'trusted_bot', 'suspicious', or 'unknown'
   */
  public function classifyUserAgent(string $userAgent): string
  {
    if (empty($userAgent)) {
      return 'suspicious';
    }

    $trustedBots = $this->config->getTrustedBots();

    // Check if user-agent matches any trusted bot pattern
    foreach ($trustedBots as $botPattern) {
      if (stripos($userAgent, $botPattern) !== FALSE) {
        return 'trusted_bot';
      }
    }

    // Check for suspicious patterns
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
}
