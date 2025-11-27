<?php

/**
 * @file BotIpService.php
 * @brief Service for managing verified bot IP ranges
 *
 * Handles downloading, caching, and verifying bot IP addresses from trusted sources.
 * Used to detect bot spoofing (UAs claiming to be bots but not from verified IPs).
 */

namespace SolarWinds\Services;

use PDO;

/**
 * Bot IP Service
 *
 * Manages verified bot IP ranges from GitHub sources, with automatic updates
 * and CIDR range checking for bot verification.
 */
class BotIpService
{
  /**
   * Database service.
   */
  protected DatabaseService $database;

  /**
   * Configuration for bot IP sources.
   *
   * Maps bot names to their GitHub raw URLs (JSON format for source attribution).
   */
  protected array $botSources = [
    'googlebot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/googlebot/ips.json',
    'bingbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/bingbot/ips.json',
    'duckduckbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/duckduckbot/ips.json',
    'facebookbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/facebookbot/ips.json',
    'ahrefsbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/ahrefsbot/ips.json',
    'semrushbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/semrushbot/ips.json',
    'yandexbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/yandexbot/ips.json',
  ];

  /**
   * How long to wait between update checks (6 hours).
   *
   * GitHub source updates every 6 hours, so we check frequently
   * to catch bot spoofing attempts with newly added IP ranges.
   */
  protected int $updateInterval = 21600; // 6 hours

  /**
   * Domain patterns for reverse DNS verification.
   *
   * Maps bot names to expected reverse DNS hostname patterns.
   * Used as fallback when IP is not in published ranges.
   */
  protected array $botDomainPatterns = [
    'googlebot' => ['googlebot.com', 'google.com'],
    'bingbot' => ['search.msn.com'],
    'duckduckbot' => ['duckduckgo.com'],
    'facebookbot' => ['fbsv.net', 'facebook.com'],
    'ahrefsbot' => ['ahrefs.com'],
    'semrushbot' => ['semrush.com'],
    'yandexbot' => ['yandex.com', 'yandex.ru', 'yandex.net'],
    'claudebot' => ['anthropic.com'],
  ];

  /**
   * Constructor.
   *
   * @param DatabaseService $database Database service
   */
  public function __construct(DatabaseService $database)
  {
    $this->database = $database;
  }

  /**
   * Verify bot using tiered approach: CIDR ranges first, then reverse DNS.
   *
   * This is the main verification method that implements our tiered strategy:
   * 1. Check published IP ranges (fast, definitive)
   * 2. Fall back to reverse DNS (slower, but works for unverifiable bots)
   *
   * @param string $ip IP address to verify
   * @param string $botName Bot name (e.g., 'googlebot')
   * @param bool $allowReverseDnsFallback Allow reverse DNS fallback if CIDR fails
   * @return array ['verified' => bool, 'method' => 'cidr'|'reverse_dns'|'none']
   */
  public function verifyBot(string $ip, string $botName, bool $allowReverseDnsFallback = TRUE): array
  {
    // Try CIDR range verification first.
    if ($this->isVerifiedBotIp($ip, $botName)) {
      return ['verified' => TRUE, 'method' => 'cidr'];
    }

    // Fall back to reverse DNS if enabled.
    if ($allowReverseDnsFallback && $this->verifyBotByReverseDns($ip, $botName)) {
      return ['verified' => TRUE, 'method' => 'reverse_dns'];
    }

    return ['verified' => FALSE, 'method' => 'none'];
  }

  /**
   * Check if IP is in verified bot ranges.
   *
   * @param string $ip IP address to check
   * @param string $botName Bot name (e.g., 'googlebot')
   * @return bool TRUE if IP is in verified ranges for this bot
   */
  public function isVerifiedBotIp(string $ip, string $botName): bool
  {
    // Ensure we have recent data for this bot.
    $this->ensureRecentData($botName);

    // Get all ranges for this bot.
    $stmt = $this->database->prepare('SELECT ip_range FROM bot_ip_ranges WHERE bot_name = :bot_name');
    $stmt->execute([':bot_name' => $botName]);
    $ranges = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Check if IP falls in any of the ranges.
    foreach ($ranges as $cidr) {
      if ($this->ipInCidr($ip, $cidr)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Verify bot by reverse DNS lookup (fallback method).
   *
   * Used when IP is not in published ranges. Performs:
   * 1. Reverse DNS lookup (IP -> hostname)
   * 2. Verify hostname matches expected pattern
   * 3. Forward DNS lookup (hostname -> IP) to prevent spoofing
   *
   * @param string $ip IP address to verify
   * @param string $botName Bot name (e.g., 'googlebot')
   * @return bool TRUE if reverse DNS verification succeeds
   */
  public function verifyBotByReverseDns(string $ip, string $botName): bool
  {
    // Check if we have domain patterns for this bot.
    if (!isset($this->botDomainPatterns[$botName])) {
      return FALSE;
    }

    // Perform reverse DNS lookup.
    $hostname = $this->reverseDnsLookup($ip);
    if ($hostname === FALSE) {
      return FALSE;
    }

    // Check if hostname matches expected pattern.
    $patterns = $this->botDomainPatterns[$botName];
    if (!$this->hostnameMatchesPatterns($hostname, $patterns)) {
      return FALSE;
    }

    // Perform forward DNS lookup to prevent spoofing.
    return $this->forwardDnsLookup($hostname, $ip);
  }

  /**
   * Perform reverse DNS lookup.
   *
   * @param string $ip IP address
   * @return string|false Hostname or FALSE on failure
   */
  protected function reverseDnsLookup(string $ip): string|false
  {
    $hostname = @gethostbyaddr($ip);

    // gethostbyaddr() returns the IP itself on failure.
    if ($hostname === $ip) {
      return FALSE;
    }

    return $hostname;
  }

  /**
   * Check if hostname matches any of the expected patterns.
   *
   * @param string $hostname Hostname from reverse DNS
   * @param array $patterns Array of domain patterns (e.g., ['googlebot.com', 'google.com'])
   * @return bool TRUE if hostname matches any pattern
   */
  protected function hostnameMatchesPatterns(string $hostname, array $patterns): bool
  {
    foreach ($patterns as $pattern) {
      // Check if hostname ends with the pattern (supports subdomains).
      if (str_ends_with($hostname, '.' . $pattern) || $hostname === $pattern) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Perform forward DNS lookup to verify hostname resolves back to IP.
   *
   * Prevents DNS spoofing by ensuring the hostname actually points to the IP.
   *
   * @param string $hostname Hostname to resolve
   * @param string $originalIp Original IP address to verify against
   * @return bool TRUE if hostname resolves to original IP
   */
  protected function forwardDnsLookup(string $hostname, string $originalIp): bool
  {
    // Get all IP addresses for this hostname.
    $records = @dns_get_record($hostname, DNS_A + DNS_AAAA);

    if ($records === FALSE || empty($records)) {
      return FALSE;
    }

    // Check if any of the resolved IPs match the original IP.
    foreach ($records as $record) {
      $resolvedIp = $record['ip'] ?? $record['ipv6'] ?? NULL;
      if ($resolvedIp === $originalIp) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Check if an IP address is within a CIDR range.
   *
   * @param string $ip IP address to check
   * @param string $cidr CIDR range (e.g., '66.249.64.0/19')
   * @return bool TRUE if IP is in CIDR range
   */
  protected function ipInCidr(string $ip, string $cidr): bool
  {
    // Parse CIDR notation.
    [$subnet, $mask] = explode('/', $cidr);

    // Convert IP and subnet to binary.
    $ipBin = inet_pton($ip);
    $subnetBin = inet_pton($subnet);

    if ($ipBin === FALSE || $subnetBin === FALSE) {
      return FALSE;
    }

    // Handle IPv4 vs IPv6.
    $ipVersion = strlen($ipBin) === 4 ? 4 : 6;
    $maxBits = $ipVersion === 4 ? 32 : 128;

    // Create binary mask.
    $maskBin = '';
    for ($i = 0; $i < $maxBits; $i++) {
      $maskBin .= $i < $mask ? '1' : '0';
    }

    // Convert mask to binary string.
    $maskBinStr = '';
    for ($i = 0; $i < strlen($maskBin); $i += 8) {
      $maskBinStr .= chr(bindec(substr($maskBin, $i, 8)));
    }

    // Apply mask and compare.
    return ($ipBin & $maskBinStr) === ($subnetBin & $maskBinStr);
  }

  /**
   * Ensure we have recent data for a bot (update if needed).
   *
   * @param string $botName Bot name to check
   */
  protected function ensureRecentData(string $botName): void
  {
    // Check metadata for last update time.
    $stmt = $this->database->prepare('SELECT last_checked, enabled FROM bot_ip_metadata WHERE bot_name = :bot_name');
    $stmt->execute([':bot_name' => $botName]);
    $metadata = $stmt->fetch(PDO::FETCH_ASSOC);

    // If bot is disabled, skip update.
    if ($metadata && !$metadata['enabled']) {
      return;
    }

    // If no metadata or data is stale, update.
    if (!$metadata || strtotime($metadata['last_checked']) < time() - $this->updateInterval) {
      $this->updateBotRanges($botName);
    }
  }

  /**
   * Update bot IP ranges from GitHub.
   *
   * @param string $botName Bot name to update
   * @return bool TRUE if update successful
   */
  public function updateBotRanges(string $botName): bool
  {
    // Check if we have a source for this bot.
    if (!isset($this->botSources[$botName])) {
      return FALSE;
    }

    $sourceUrl = $this->botSources[$botName];

    // Update last_checked timestamp (even if download fails).
    $now = gmdate('Y-m-d H:i:s');
    $this->database->exec("
      INSERT INTO bot_ip_metadata (bot_name, source_url, last_checked, last_updated, range_count)
      VALUES ('{$botName}', '{$sourceUrl}', '{$now}', '{$now}', 0)
      ON CONFLICT(bot_name) DO UPDATE SET last_checked = '{$now}'
    ");

    // Download the IP list.
    $ranges = $this->downloadBotRanges($sourceUrl);

    if (empty($ranges)) {
      return FALSE;
    }

    // Start transaction for atomic update.
    $this->database->beginTransaction();

    try {
      // Delete old ranges for this bot.
      $stmt = $this->database->prepare('DELETE FROM bot_ip_ranges WHERE bot_name = :bot_name');
      $stmt->execute([':bot_name' => $botName]);

      // Insert new ranges.
      $stmt = $this->database->prepare('
        INSERT INTO bot_ip_ranges (bot_name, ip_range, source, updated_at)
        VALUES (:bot_name, :ip_range, :source, :updated_at)
      ');

      foreach ($ranges as $range) {
        $stmt->execute([
          ':bot_name' => $botName,
          ':ip_range' => $range,
          ':source' => $sourceUrl,
          ':updated_at' => $now,
        ]);
      }

      // Update metadata with successful update.
      $this->database->exec("
        UPDATE bot_ip_metadata
        SET last_updated = '{$now}', range_count = " . count($ranges) . "
        WHERE bot_name = '{$botName}'
      ");

      $this->database->commit();
      return TRUE;
    }
    catch (\Exception $e) {
      $this->database->rollBack();
      return FALSE;
    }
  }

  /**
   * Download bot IP ranges from GitHub.
   *
   * @param string $url GitHub raw URL
   * @return array Array of CIDR ranges
   */
  protected function downloadBotRanges(string $url): array
  {
    // Use file_get_contents with timeout.
    $context = stream_context_create([
      'http' => [
        'timeout' => 10, // 10 second timeout
        'user_agent' => 'SolarWinds-PHP-CLI/1.0',
      ],
    ]);

    $content = @file_get_contents($url, FALSE, $context);

    if ($content === FALSE) {
      return [];
    }

    // Parse JSON content.
    $data = json_decode($content, TRUE);

    if (!is_array($data)) {
      return [];
    }

    // Extract IP ranges from JSON objects.
    $ranges = [];
    foreach ($data as $entry) {
      if (isset($entry['ip']) && is_string($entry['ip'])) {
        // Validate CIDR format.
        if (preg_match('/^[0-9a-f:.]+\/\d+$/i', $entry['ip'])) {
          $ranges[] = $entry['ip'];
        }
      }
    }

    return $ranges;
  }

  /**
   * Force update all bot ranges.
   *
   * @return array Summary of update results
   */
  public function updateAllBots(): array
  {
    $results = [];

    foreach (array_keys($this->botSources) as $botName) {
      $success = $this->updateBotRanges($botName);
      $results[$botName] = $success;
    }

    return $results;
  }

  /**
   * Get bot IP statistics.
   *
   * @return array Bot metadata with range counts
   */
  public function getBotStatistics(): array
  {
    $stmt = $this->database->query('
      SELECT bot_name, source_url, last_checked, last_updated, range_count, enabled
      FROM bot_ip_metadata
      ORDER BY bot_name
    ');

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}
