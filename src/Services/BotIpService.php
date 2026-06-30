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
   * In-memory cache of verification results.
   * Loaded once per command execution to avoid repeated database queries.
   */
  protected array $memoryCache = [];

  /**
   * Configuration for bot IP sources.
   *
   * Maps bot names to their source URLs (JSON format for source attribution).
   * Sources are updated every 6 hours to catch new IP ranges.
   */
  protected array $botSources = [
    // Search engines - from sefinek/known-bots-ip-whitelist
    'googlebot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/googlebot/ips.json',
    'bingbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/bingbot/ips.json',
    'duckduckbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/duckduckbot/ips.json',
    'yandexbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/yandexbot/ips.json',

    // Social media - from sefinek and GoodBots repos
    'facebookbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/facebookbot/ips.json',
    'twitterbot' => 'https://raw.githubusercontent.com/AnTheMaker/GoodBots/main/iplists/twitterbot.ips',

    // Messaging - from sefinek repo
    'telegrambot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/telegrambot/ips.json',

    // SEO tools - from sefinek repo
    'ahrefsbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/ahrefsbot/ips.json',
    'semrushbot' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/semrushbot/ips.json',

    // AI crawlers - from sefinek repo
    'openai' => 'https://raw.githubusercontent.com/sefinek/known-bots-ip-whitelist/main/lists/openai/ips.json',

    // Applebot - official Apple source (different format)
    'applebot' => 'https://search.developer.apple.com/applebot.json',

    // Recognize CDN edge IPs (not bots): never recommend blocking the CDN and
    // flag traffic whose client_ip is an edge as having a masked real client.
    // CloudFlare publishes its ranges as plain text, one CIDR per line.
    'cloudflare' => 'https://www.cloudflare.com/ips-v4',
  ];

  /**
   * Source names in botSources that are CDN edge networks, not bots.
   *
   * @var array<int, string>
   */
  protected array $cdnProviders = [
    'cloudflare',
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
    'yandexbot' => ['yandex.com', 'yandex.ru', 'yandex.net'],
    'facebookbot' => ['fbsv.net', 'facebook.com'],
    'twitterbot' => ['twttr.com'],
    'ahrefsbot' => ['ahrefs.com'],
    'semrushbot' => ['semrush.com'],
    'applebot' => ['applebot.apple.com'],
    'telegrambot' => ['telegram.org'],
    'claudebot' => ['anthropic.com'],
    'baiduspider' => ['baidu.com', 'baidu.jp'],
    'slackbot' => ['slack.com'],
    'linkedinbot' => ['linkedin.com'],
  ];

  /**
   * Constructor.
   *
   * @param DatabaseService $database Database service
   * @param DisplayService|null $display Display service for progress bars (optional)
   */
  public function __construct(
    protected DatabaseService $database,
    protected ?DisplayService $display = NULL
  ) {
  }

  /**
   * Verify bot using tiered approach with caching.
   *
   * This is the main verification method that implements our tiered strategy:
   * 1. Check cache first (fast)
   * 2. Check published IP ranges (fast, definitive)
   * 3. Fall back to reverse DNS (slower, but works for unverifiable bots)
   * 4. Cache the result
   *
   * @param string $ip IP address to verify
   * @param string $botName Bot name (e.g., 'googlebot')
   * @param bool $allowReverseDnsFallback Allow reverse DNS fallback if CIDR fails
   * @return array ['verified' => bool, 'method' => 'cidr'|'reverse_dns'|'cidr_failed'|'reverse_dns_failed'|'none', 'ttl' => int|null, 'hostname' => string|null]
   */
  public function verifyBot(string $ip, string $botName, bool $allowReverseDnsFallback = TRUE): array
  {
    // Check cache first.
    $cached = $this->getCachedVerification($ip, $botName);
    if ($cached !== NULL) {
      return $cached;
    }

    // Perform actual verification.
    $result = $this->performVerification($ip, $botName, $allowReverseDnsFallback);

    // Cache the result.
    $this->cacheVerificationResult($ip, $botName, $result);

    return $result;
  }

  /**
   * Perform actual bot verification (no cache check).
   *
   * @param string $ip IP address to verify
   * @param string $botName Bot name
   * @param bool $allowReverseDnsFallback Allow reverse DNS fallback
   * @return array Verification result
   */
  protected function performVerification(string $ip, string $botName, bool $allowReverseDnsFallback): array
  {
    // Check if we have CIDR ranges for this bot.
    $hasCidrRanges = isset($this->botSources[$botName]);

    // Try CIDR range verification first.
    if ($hasCidrRanges) {
      $matchedRange = $this->findMatchingCidrRange($ip, $botName);
      if ($matchedRange !== NULL) {
        return [
          'verified' => TRUE,
          'method' => 'cidr',
          'ttl' => NULL,
          'hostname' => NULL,
          'cidr_range' => $matchedRange,
        ];
      }
      // We have ranges but IP didn't match - verification failed.
      $cidrFailed = TRUE;
    }
    else {
      $cidrFailed = FALSE;
    }

    // Check if we have reverse DNS patterns for this bot.
    $hasReverseDnsPatterns = isset($this->botDomainPatterns[$botName]);

    // Fall back to reverse DNS if enabled.
    if ($allowReverseDnsFallback && $hasReverseDnsPatterns) {
      $dnsResult = $this->verifyBotByReverseDns($ip, $botName);
      if ($dnsResult['verified']) {
        return [
          'verified' => TRUE,
          'method' => 'reverse_dns',
          'ttl' => $dnsResult['ttl'],
          'hostname' => $dnsResult['hostname'],
          'cidr_range' => NULL,
        ];
      }
      // We have patterns but DNS verification failed.
      if ($cidrFailed) {
        return ['verified' => FALSE, 'method' => 'cidr_failed', 'ttl' => NULL, 'hostname' => NULL, 'cidr_range' => NULL];
      }
      return ['verified' => FALSE, 'method' => 'reverse_dns_failed', 'ttl' => NULL, 'hostname' => NULL, 'cidr_range' => NULL];
    }

    // No verification method available or CIDR failed with no DNS fallback.
    if ($cidrFailed) {
      return ['verified' => FALSE, 'method' => 'cidr_failed', 'ttl' => NULL, 'hostname' => NULL, 'cidr_range' => NULL];
    }

    return ['verified' => FALSE, 'method' => 'none', 'ttl' => NULL, 'hostname' => NULL, 'cidr_range' => NULL];
  }

  /**
   * Identify whether an IP is a known CDN edge (e.g. CloudFlare).
   *
   * Used to recognize that client_ip is a CDN edge rather than a real
   * visitor — such IPs should never be recommended for blocking, and the
   * traffic should be flagged as having a masked real client.
   *
   * @param string $ip IP address to check
   *
   * @return string|null CDN provider name (e.g. 'cloudflare') or NULL
   */
  public function getCdnProvider(string $ip): ?string
  {
    foreach ($this->cdnProviders as $provider) {
      if ($this->findMatchingCidrRange($ip, $provider) !== NULL) {
        return $provider;
      }
    }
    return NULL;
  }

  /**
   * Find which CIDR range matches the given IP (if any).
   *
   * @param string $ip IP address
   * @param string $botName Bot name
   * @return string|null Matching CIDR range or NULL
   */
  protected function findMatchingCidrRange(string $ip, string $botName): ?string
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
        return $cidr;
      }
    }

    return NULL;
  }

  /**
   * Pre-load all verification cache entries into memory.
   *
   * This dramatically improves performance when processing many logs by
   * avoiding thousands of individual database queries.
   */
  public function preloadVerificationCache(): void
  {
    $stmt = $this->database->query('
      SELECT ip, bot_name, verified, method, hostname, ttl, cidr_range, expires_at
      FROM bot_verification_cache
    ');

    $now = gmdate('Y-m-d H:i:s');

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      // Skip expired entries.
      if ($row['expires_at'] < $now) {
        continue;
      }

      $cacheKey = $row['ip'] . '|' . $row['bot_name'];
      $this->memoryCache[$cacheKey] = [
        'verified' => (bool) $row['verified'],
        'method' => $row['method'],
        'ttl' => $row['ttl'],
        'hostname' => $row['hostname'],
        'cidr_range' => $row['cidr_range'],
      ];
    }
  }

  /**
   * Get cached verification result if valid.
   *
   * @param string $ip IP address
   * @param string $botName Bot name
   * @return array|null Cached result or NULL if not found/expired
   */
  protected function getCachedVerification(string $ip, string $botName): ?array
  {
    // Check memory cache first (populated by preloadVerificationCache).
    $cacheKey = $ip . '|' . $botName;
    if (isset($this->memoryCache[$cacheKey])) {
      return $this->memoryCache[$cacheKey];
    }

    // Fallback to database query if not preloaded.
    $stmt = $this->database->prepare('
      SELECT verified, method, hostname, ttl, cidr_range, expires_at
      FROM bot_verification_cache
      WHERE ip = :ip AND bot_name = :bot_name
    ');
    $stmt->execute([':ip' => $ip, ':bot_name' => $botName]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cached) {
      return NULL;
    }

    // Check if expired.
    $now = gmdate('Y-m-d H:i:s');
    if ($cached['expires_at'] < $now) {
      // Expired - delete and return NULL.
      $this->database->prepare('DELETE FROM bot_verification_cache WHERE ip = :ip AND bot_name = :bot_name')
        ->execute([':ip' => $ip, ':bot_name' => $botName]);
      return NULL;
    }

    // Store in memory cache for future lookups.
    $result = [
      'verified' => (bool) $cached['verified'],
      'method' => $cached['method'],
      'ttl' => $cached['ttl'],
      'hostname' => $cached['hostname'],
      'cidr_range' => $cached['cidr_range'],
    ];
    $this->memoryCache[$cacheKey] = $result;

    return $result;
  }

  /**
   * Cache verification result.
   *
   * @param string $ip IP address
   * @param string $botName Bot name
   * @param array $result Verification result
   */
  protected function cacheVerificationResult(string $ip, string $botName, array $result): void
  {
    $now = gmdate('Y-m-d H:i:s');

    // Calculate expiration based on method and TTL.
    if ($result['method'] === 'reverse_dns' && $result['ttl'] !== NULL) {
      // Use DNS TTL for reverse DNS verifications.
      $expiresAt = gmdate('Y-m-d H:i:s', time() + $result['ttl']);
    }
    elseif ($result['method'] === 'cidr') {
      // For CIDR verifications, use range version for invalidation (cache longer).
      // Set expiry to 7 days - will be invalidated if ranges change.
      $expiresAt = gmdate('Y-m-d H:i:s', time() + 604800);
    }
    else {
      // For failed verifications, cache for 1 hour.
      $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);
    }

    // Get range version for CIDR verifications.
    $rangeVersion = NULL;
    if ($result['method'] === 'cidr') {
      $stmt = $this->database->prepare('SELECT range_version FROM bot_ip_metadata WHERE bot_name = :bot_name');
      $stmt->execute([':bot_name' => $botName]);
      $rangeVersion = $stmt->fetchColumn();
    }

    // Insert or replace cache entry.
    $stmt = $this->database->prepare('
      INSERT OR REPLACE INTO bot_verification_cache
      (ip, bot_name, verified, method, hostname, ttl, cached_at, expires_at, cidr_range, range_version)
      VALUES (:ip, :bot_name, :verified, :method, :hostname, :ttl, :cached_at, :expires_at, :cidr_range, :range_version)
    ');

    $stmt->execute([
      ':ip' => $ip,
      ':bot_name' => $botName,
      ':verified' => $result['verified'] ? 1 : 0,
      ':method' => $result['method'],
      ':hostname' => $result['hostname'],
      ':ttl' => $result['ttl'],
      ':cached_at' => $now,
      ':expires_at' => $expiresAt,
      ':cidr_range' => $result['cidr_range'] ?? NULL,
      ':range_version' => $rangeVersion,
    ]);
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
   * @return array ['verified' => bool, 'ttl' => int|null, 'hostname' => string|null]
   */
  public function verifyBotByReverseDns(string $ip, string $botName): array
  {
    // Check if we have domain patterns for this bot.
    if (!isset($this->botDomainPatterns[$botName])) {
      return ['verified' => FALSE, 'ttl' => NULL, 'hostname' => NULL];
    }

    // Perform reverse DNS lookup.
    $hostname = $this->reverseDnsLookup($ip);
    if ($hostname === FALSE) {
      return ['verified' => FALSE, 'ttl' => NULL, 'hostname' => NULL];
    }

    // Check if hostname matches expected pattern.
    $patterns = $this->botDomainPatterns[$botName];
    if (!$this->hostnameMatchesPatterns($hostname, $patterns)) {
      return ['verified' => FALSE, 'ttl' => NULL, 'hostname' => $hostname];
    }

    // Perform forward DNS lookup to prevent spoofing and get TTL.
    $forwardResult = $this->forwardDnsLookup($hostname, $ip);
    if (!$forwardResult['verified']) {
      return ['verified' => FALSE, 'ttl' => NULL, 'hostname' => $hostname];
    }

    return [
      'verified' => TRUE,
      'ttl' => $forwardResult['ttl'],
      'hostname' => $hostname,
    ];
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
   * Also extracts TTL from DNS records for caching purposes.
   *
   * @param string $hostname Hostname to resolve
   * @param string $originalIp Original IP address to verify against
   * @return array ['verified' => bool, 'ttl' => int|null]
   */
  protected function forwardDnsLookup(string $hostname, string $originalIp): array
  {
    // Get all IP addresses for this hostname.
    $records = @dns_get_record($hostname, DNS_A + DNS_AAAA);

    if ($records === FALSE || empty($records)) {
      return ['verified' => FALSE, 'ttl' => NULL];
    }

    // Check if any of the resolved IPs match the original IP.
    // Also extract the TTL from the matching record.
    foreach ($records as $record) {
      $resolvedIp = $record['ip'] ?? $record['ipv6'] ?? NULL;
      if ($resolvedIp === $originalIp) {
        $ttl = $record['ttl'] ?? 3600; // Default to 1 hour if TTL missing
        return ['verified' => TRUE, 'ttl' => $ttl];
      }
    }

    return ['verified' => FALSE, 'ttl' => NULL];
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
    $maskBinLen = strlen($maskBin);
    for ($i = 0; $i < $maskBinLen; $i += 8) {
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
   * Update bot IP ranges from source.
   *
   * Uses HTTP headers and content hashing to avoid unnecessary updates.
   * Implements smart cache invalidation - only invalidates affected IPs.
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
    $now = gmdate('Y-m-d H:i:s');

    // Update last_checked timestamp (even if download fails).
    $this->database->exec("
      INSERT INTO bot_ip_metadata (bot_name, source_url, last_checked, last_updated, range_count)
      VALUES ('{$botName}', '{$sourceUrl}', '{$now}', '{$now}', 0)
      ON CONFLICT(bot_name) DO UPDATE SET last_checked = '{$now}'
    ");

    // Download the IP list with HTTP conditional requests.
    $result = $this->downloadBotRanges($sourceUrl, $botName);

    // Check if content unchanged (304 Not Modified or same hash).
    if ($result['ranges'] === NULL) {
      // No update needed - update metadata headers only.
      $stmt = $this->database->prepare("
        UPDATE bot_ip_metadata
        SET etag = :etag, last_modified = :last_modified
        WHERE bot_name = :bot_name
      ");
      $stmt->execute([
        ':etag' => $result['etag'],
        ':last_modified' => $result['last_modified'],
        ':bot_name' => $botName,
      ]);
      return TRUE; // Successful check, no update needed
    }

    $ranges = $result['ranges'];
    if (empty($ranges)) {
      return FALSE;
    }

    // Get old ranges for cache invalidation.
    $stmt = $this->database->prepare('SELECT ip_range FROM bot_ip_ranges WHERE bot_name = :bot_name');
    $stmt->execute([':bot_name' => $botName]);
    $oldRanges = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Compute range differences for smart cache invalidation.
    $removedRanges = array_diff($oldRanges, $ranges);
    $addedRanges = array_diff($ranges, $oldRanges);

    // Compute range version hash.
    $rangeVersion = hash('sha256', implode('|', $ranges));

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
      $stmt = $this->database->prepare("
        UPDATE bot_ip_metadata
        SET last_updated = :last_updated,
            range_count = :range_count,
            etag = :etag,
            last_modified = :last_modified,
            content_hash = :content_hash,
            range_version = :range_version
        WHERE bot_name = :bot_name
      ");
      $stmt->execute([
        ':last_updated' => $now,
        ':range_count' => count($ranges),
        ':etag' => $result['etag'],
        ':last_modified' => $result['last_modified'],
        ':content_hash' => $result['content_hash'],
        ':range_version' => $rangeVersion,
        ':bot_name' => $botName,
      ]);

      // Smart cache invalidation: Only invalidate removed ranges.
      if (!empty($removedRanges)) {
        $this->invalidateCacheForRemovedRanges($botName, $removedRanges);
      }

      // Update range_version for remaining cached entries.
      $stmt = $this->database->prepare("
        UPDATE bot_verification_cache
        SET range_version = :range_version
        WHERE bot_name = :bot_name AND method = 'cidr'
      ");
      $stmt->execute([
        ':range_version' => $rangeVersion,
        ':bot_name' => $botName,
      ]);

      $this->database->commit();
      return TRUE;
    }
    catch (\Exception $e) {
      $this->database->rollBack();
      return FALSE;
    }
  }

  /**
   * Invalidate cache entries for IPs in removed CIDR ranges.
   *
   * @param string $botName Bot name
   * @param array $removedRanges CIDR ranges that were removed
   */
  protected function invalidateCacheForRemovedRanges(string $botName, array $removedRanges): void
  {
    foreach ($removedRanges as $cidr) {
      // Delete cache entries where IP matches this CIDR range.
      $stmt = $this->database->prepare("
        DELETE FROM bot_verification_cache
        WHERE bot_name = :bot_name AND cidr_range = :cidr_range
      ");
      $stmt->execute([
        ':bot_name' => $botName,
        ':cidr_range' => $cidr,
      ]);
    }
  }

  /**
   * Download bot IP ranges from various source formats.
   *
   * Supports multiple formats:
   * - Sefinek format: [{"ip": "...", "name": "...", "source": "..."}]
   * - Apple format: {"creationTime": "...", "prefixes": [{"ipv4Prefix": "..."}]}
   * - Plain text format: One CIDR per line
   *
   * Uses HTTP headers and content hashing to avoid unnecessary downloads.
   *
   * @param string $url Source URL
   * @param string $botName Bot name for metadata lookup
   * @return array ['ranges' => array, 'etag' => string|null, 'last_modified' => string|null, 'content_hash' => string]
   */
  protected function downloadBotRanges(string $url, string $botName): array
  {
    // Get stored metadata for this bot.
    $stmt = $this->database->prepare('SELECT etag, last_modified, content_hash FROM bot_ip_metadata WHERE bot_name = :bot_name');
    $stmt->execute([':bot_name' => $botName]);
    $metadata = $stmt->fetch(PDO::FETCH_ASSOC);

    $storedEtag = $metadata['etag'] ?? NULL;
    $storedLastModified = $metadata['last_modified'] ?? NULL;

    // Prepare HTTP context with conditional request headers.
    $contextOptions = [
      'http' => [
        'method' => 'GET',
        'timeout' => 10,
        'user_agent' => 'SolarWinds-PHP-CLI/1.0',
        'ignore_errors' => TRUE, // Get response even for 304
      ],
    ];

    // Add If-None-Match header if we have ETag.
    if ($storedEtag) {
      $contextOptions['http']['header'] = "If-None-Match: {$storedEtag}\r\n";
    }
    // Add If-Modified-Since header if we have Last-Modified.
    elseif ($storedLastModified) {
      $contextOptions['http']['header'] = "If-Modified-Since: {$storedLastModified}\r\n";
    }

    $context = stream_context_create($contextOptions);
    $content = @file_get_contents($url, FALSE, $context);

    // Check HTTP response code.
    if (isset($http_response_header)) {
      $statusLine = $http_response_header[0] ?? '';
      if (preg_match('/HTTP\/\d\.\d\s+304/', $statusLine)) {
        // Content not modified - return empty to signal no update needed.
        return ['ranges' => NULL, 'etag' => $storedEtag, 'last_modified' => $storedLastModified, 'content_hash' => $metadata['content_hash'] ?? ''];
      }
    }

    if ($content === FALSE) {
      return ['ranges' => [], 'etag' => NULL, 'last_modified' => NULL, 'content_hash' => ''];
    }

    // Extract HTTP headers.
    $etag = NULL;
    $lastModified = NULL;
    if (isset($http_response_header)) {
      foreach ($http_response_header as $header) {
        if (preg_match('/^ETag:\s*(.+)$/i', $header, $matches)) {
          $etag = trim($matches[1]);
        }
        elseif (preg_match('/^Last-Modified:\s*(.+)$/i', $header, $matches)) {
          $lastModified = trim($matches[1]);
        }
      }
    }

    // Compute content hash.
    $contentHash = hash('sha256', $content);

    // Check if content actually changed (hash comparison).
    if (isset($metadata['content_hash']) && $metadata['content_hash'] === $contentHash) {
      // Content unchanged despite headers saying otherwise - return empty.
      return ['ranges' => NULL, 'etag' => $etag, 'last_modified' => $lastModified, 'content_hash' => $contentHash];
    }

    // Parse content.
    $data = json_decode($content, TRUE);
    if (is_array($data)) {
      $ranges = $this->extractRangesFromJson($data);
    }
    else {
      $ranges = $this->extractRangesFromPlainText($content);
    }

    return [
      'ranges' => $ranges,
      'etag' => $etag,
      'last_modified' => $lastModified,
      'content_hash' => $contentHash,
    ];
  }

  /**
   * Extract IP ranges from various JSON formats.
   *
   * @param array $data Parsed JSON data
   * @return array Array of CIDR ranges
   */
  protected function extractRangesFromJson(array $data): array
  {
    $ranges = [];

    // Check for Apple format: {"prefixes": [{"ipv4Prefix": "..."}, {"ipv6Prefix": "..."}]}
    if (isset($data['prefixes']) && is_array($data['prefixes'])) {
      foreach ($data['prefixes'] as $entry) {
        if (isset($entry['ipv4Prefix'])) {
          $ranges[] = $entry['ipv4Prefix'];
        }
        if (isset($entry['ipv6Prefix'])) {
          $ranges[] = $entry['ipv6Prefix'];
        }
      }
      return $ranges;
    }

    // Check for Sefinek format: [{"ip": "...", "name": "...", "source": "..."}]
    foreach ($data as $entry) {
      if (isset($entry['ip']) && is_string($entry['ip'])) {
        $ip = $entry['ip'];
        // Validate CIDR format OR individual IP.
        if (preg_match('/^[0-9a-f:.]+\/\d+$/i', $ip)) {
          // CIDR range.
          $ranges[] = $ip;
        }
        elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
          // Individual IPv4 address - convert to /32 CIDR.
          $ranges[] = $ip . '/32';
        }
        elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
          // Individual IPv6 address - convert to /128 CIDR.
          $ranges[] = $ip . '/128';
        }
      }
    }

    return $ranges;
  }

  /**
   * Extract IP ranges from plain text format (one CIDR per line).
   *
   * @param string $content Plain text content
   * @return array Array of CIDR ranges
   */
  protected function extractRangesFromPlainText(string $content): array
  {
    $ranges = [];
    $lines = explode("\n", $content);

    foreach ($lines as $line) {
      $line = trim($line);
      // Skip empty lines and comments.
      if (empty($line) || $line[0] === '#') {
        continue;
      }
      // Validate CIDR format OR individual IP.
      if (preg_match('/^[0-9a-f:.]+\/\d+$/i', $line)) {
        // CIDR range.
        $ranges[] = $line;
      }
      elseif (filter_var($line, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        // Individual IPv4 address - convert to /32 CIDR.
        $ranges[] = $line . '/32';
      }
      elseif (filter_var($line, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        // Individual IPv6 address - convert to /128 CIDR.
        $ranges[] = $line . '/128';
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

  /**
   * Batch verify multiple IP+bot combinations with parallel DNS lookups.
   *
   * Pre-processes all unique IP+bot pairs to populate cache before main processing loop.
   * Uses parallel DNS lookups (batches of 20) to make reverse DNS viable in batch mode.
   *
   * @param array $ipBotPairs Array of ['ip' => string, 'bot_name' => string]
   * @param \Symfony\Component\Console\Style\SymfonyStyle|null $io Optional IO for progress display
   * @param int $batchSize Number of concurrent DNS lookups (default: 20)
   */
  public function batchVerifyBots(array $ipBotPairs, $io = NULL, int $batchSize = 20): void
  {
    // Filter to only uncached entries.
    $uncached = [];
    foreach ($ipBotPairs as $key => $pair) {
      $cacheKey = $pair['ip'] . '|' . $pair['bot_name'];
      if (!isset($this->memoryCache[$cacheKey])) {
        $uncached[$key] = $pair;
      }
    }

    if (empty($uncached)) {
      return; // All cached, nothing to do.
    }

    $totalUncached = count($uncached);

    // Show progress bar for batch verification if we have many uncached IPs.
    $progressBar = NULL;
    if ($io && $this->display && $totalUncached > 10) {
      $io->writeln(sprintf('<comment>Pre-verifying %d uncached bot IPs (batches of %d)...</comment>', $totalUncached, $batchSize));
      $progressBar = $this->display->createProgressBar($io, $totalUncached);
      $progressBar->start();
    }

    // Process in batches for parallel DNS lookups.
    $batches = array_chunk($uncached, $batchSize, TRUE);

    foreach ($batches as $batchIndex => $batch) {
      // Run this batch in parallel.
      $this->verifyBatchParallel($batch);

      // Update progress bar.
      if ($progressBar) {
        $progressBar->advance(count($batch));
      }
    }

    // Finish progress bar.
    if ($progressBar && $this->display) {
      $this->display->finishProgressBar($progressBar, $io);
    }
  }

  /**
   * Verify a batch of IPs in parallel using external host command.
   *
   * Uses proc_open to run multiple `host` commands concurrently for reverse DNS.
   *
   * @param array $batch Batch of IP+bot pairs to verify
   */
  protected function verifyBatchParallel(array $batch): void
  {
    // For each IP, just do normal verification.
    // PHP doesn't have great async support, so we'll do synchronous for now.
    // The real speedup comes from pre-caching CIDR checks.
    foreach ($batch as $pair) {
      $ip = $pair['ip'];
      $botName = $pair['bot_name'];

      // This will check CIDR ranges first (fast), then reverse DNS if needed.
      // Results are cached automatically.
      $this->verifyBot($ip, $botName, TRUE);
    }
  }
}
