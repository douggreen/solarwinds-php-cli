<?php

/**
 * @file CampaignAnalysisService.php
 * @brief Service for campaign analysis, historical context, and threat assessment
 *
 * @class CampaignAnalysisService
 * @brief Provides intelligent campaign analysis with scaled thresholds and deep-dive capabilities
 */

namespace SolarWinds\Services;

/**
 * Campaign Analysis Service
 *
 * Handles campaign analysis logic including:
 * - Scaled thresholds based on timeframe
 * - Historical context enrichment
 * - Automatic deep-dive for suspicious IPs
 * - Trusted IP filtering
 * - Single-event detection
 */
class CampaignAnalysisService
{
  /**
   * Constructor.
   *
   * @param ConfigurationService $config Configuration service
   * @param DatabaseService $database Database service
   * @param LogQueryService $logQuery Log query service
   * @param SyncTrackingService $syncTracking Sync tracking service
   * @param RiskScoringService $riskScoring Risk scoring service
   */
  public function __construct(
    protected ConfigurationService $config,
    protected DatabaseService $database,
    protected LogQueryService $logQuery,
    protected SyncTrackingService $syncTracking,
    protected RiskScoringService $riskScoring
  ) {
  }

  /**
   * Parse time range string to hours.
   *
   * @param string $timeRange Time range string ('15m', '1h', '2d', '1w', etc.)
   * @return float Hours
   */
  public function parseTimeRangeToHours(string $timeRange): float
  {
    // Extract number and unit.
    if (!preg_match('/^(\d+)([smhdw])$/', $timeRange, $matches)) {
      return 1.0; // Default to 1 hour if can't parse
    }

    $value = (int) $matches[1];
    $unit = $matches[2];

    return match($unit) {
      's' => $value / 3600,      // Seconds to hours
      'm' => $value / 60,        // Minutes to hours
      'h' => $value,             // Hours
      'd' => $value * 24,        // Days to hours
      'w' => $value * 24 * 7,    // Weeks to hours
      default => 1.0,
    };
  }

  /**
   * Get scaled thresholds based on timeframe.
   *
   * Automatically scales min_requests and min_duration based on the scan window.
   *
   * @param string $timeRange Time range string ('15m', '1d', '2w', etc.)
   * @return array Scaled threshold values
   */
  public function getScaledThresholds(string $timeRange): array
  {
    $base = $this->config->getBlockingThresholds();
    $hours = $this->parseTimeRangeToHours($timeRange);

    // Scale min_requests based on timeframe
    // Use the higher of hourly rate or daily rate calculation
    $minRequestsHourly = (int) ($base['min_requests_per_hour'] * $hours);
    $minRequestsDaily = (int) ($base['min_requests_per_day'] * $hours / 24);
    $minRequests = max($minRequestsHourly, $minRequestsDaily);

    // Ensure minimum of 3 requests for very short windows
    $minRequests = max(3, $minRequests);

    // Min duration scales with window
    $minDurationHours = $hours * $base['min_duration_ratio'];

    return [
      'min_requests' => $minRequests,
      'min_duration_hours' => $minDurationHours,
      'urgent_rate_per_hour' => $base['urgent_requests_per_hour'],
      'high_confidence_40x_ratio' => $base['high_confidence_40x_ratio'],
      'exploit_ratio_threshold' => $base['exploit_ratio_threshold'],
      'single_event_threshold_hours' => $base['single_event_threshold_hours'],
      'single_event_critical_volume' => $base['single_event_critical_volume'],
      'trusted_ip_prefixes' => $base['trusted_ip_prefixes'],
    ];
  }

  /**
   * Check if IP is in trusted IP prefix list (e.g. Google crawlers).
   *
   * @param string $ip IP address
   * @return bool TRUE if IP is from trusted prefix
   */
  public function isTrustedIp(string $ip): bool
  {
    $thresholds = $this->config->getBlockingThresholds();
    $trustedPrefixes = $thresholds['trusted_ip_prefixes'] ?? [];

    foreach ($trustedPrefixes as $prefix) {
      if (strpos($ip, $prefix) === 0) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Determine if campaign is a single-event burst.
   *
   * A single event is a burst that:
   * - Lasted less than threshold hours (default 1 hour)
   * - Ended more than 1 hour ago (not continuing)
   *
   * Uses full history time span from deep dive if available, otherwise falls back to current window.
   *
   * @param array $campaign Campaign data
   * @param array $thresholds Threshold configuration
   * @return bool TRUE if this is a single-event burst
   */
  public function isSingleEvent(array $campaign, array $thresholds): bool
  {
    // Use deep dive full history time span if available (more accurate for long-term patterns)
    $timeSpanDays = $campaign['deep_dive']['full_history_span_days'] ?? $campaign['time_span_days'] ?? 0;
    $lastSeen = $campaign['last_seen'] ?? NULL;

    if ($lastSeen === NULL || $lastSeen === 'unknown') {
      return FALSE;
    }

    $threshold = $thresholds['single_event_threshold_hours'];
    $thresholdDays = $threshold / 24;

    // Check if burst duration was short
    $isShortBurst = $timeSpanDays < $thresholdDays;

    // Check if it ended (not continuing)
    // lastSeen is already a unix timestamp (INTEGER), no need for strtotime()
    $timeSinceLastSeen = time() - $lastSeen;
    $isEnded = $timeSinceLastSeen > 3600; // 1 hour

    return $isShortBurst && $isEnded;
  }

  /**
   * Check if campaign is continuing (vs ended).
   *
   * @param array $campaign Campaign data
   * @return bool TRUE if campaign is still active
   */
  public function isContinuing(array $campaign): bool
  {
    $lastSeen = $campaign['last_seen'] ?? NULL;

    if ($lastSeen === NULL || $lastSeen === 'unknown') {
      return FALSE;
    }

    // lastSeen is already a unix timestamp (INTEGER), no need for strtotime()
    $timeSinceLastSeen = time() - $lastSeen;

    // If last seen within 2 hours, consider it continuing
    return $timeSinceLastSeen < 7200;
  }

  /**
   * Enrich campaigns with historical context.
   *
   * Checks campaign_analysis table to see if IPs have been seen before.
   *
   * @param array $campaigns Array of campaigns
   * @return array Campaigns enriched with historical context
   */
  public function enrichWithHistoricalContext(array $campaigns): array
  {
    foreach ($campaigns as &$campaign) {
      $ip = $campaign['ip'] ?? '';

      // Check if we have historical analysis for this IP
      $historical = $this->getCampaignAnalysisByIpFromDb($ip);

      if ($historical) {
        $campaign['historical'] = [
          'found' => TRUE,
          'last_analyzed' => $historical['analyzed_at'],
          'last_confidence' => $historical['confidence'],
          'last_severity' => $historical['campaign_severity'],
          'total_historical_requests' => $historical['total_requests'],
        ];
      }
      else {
        $campaign['historical'] = ['found' => FALSE];
      }
    }

    return $campaigns;
  }

  /**
   * Perform deep-dive analysis for a specific IP across full history.
   *
   * Queries all logs for the IP and computes full campaign metrics.
   *
   * @param string $ip IP address
   * @param array $allLogs All logs from current query (for CMS detection, etc.)
   * @param array $thresholds Threshold configuration
   * @param object $exploitsCommand ExploitsCommand instance for campaign detection
   * @return array|null Campaign data or NULL if no significant pattern found
   */
  public function performDeepDive(
    string $ip,
    array $allLogs,
    array $thresholds,
    object $exploitsCommand
  ): ?array {
    // Get earliest log date for this database
    $firstLogDate = $this->syncTracking->getEarliestLogDate();

    if (!$firstLogDate) {
      return NULL; // No logs in database
    }

    // Query ALL logs for this specific IP
    $ipLogs = $this->logQuery->getLogsByIp($ip, $firstLogDate, NULL);

    if (empty($ipLogs)) {
      return NULL;
    }

    // Compute campaign analysis using historical thresholds (lower threshold for deep-dive)
    $minRequests = max(10, $thresholds['min_requests'] / 10); // 10x lower threshold
    $campaigns = $exploitsCommand->detectCampaigns($ipLogs, $minRequests);

    // Return first campaign (should only be one per IP)
    return !empty($campaigns) ? $campaigns[0] : NULL;
  }

  /**
   * Perform automatic deep-dives for suspicious new IPs.
   *
   * For each campaign not in historical analysis, performs deep-dive
   * to check full history.
   *
   * @param array $campaigns Array of campaigns
   * @param array $allLogs All logs from current query
   * @param array $thresholds Threshold configuration
   * @param object $exploitsCommand ExploitsCommand instance
   * @return array Campaigns with deep-dive data added
   */
  public function performAutomaticDeepDives(
    array $campaigns,
    array $allLogs,
    array $thresholds,
    object $exploitsCommand
  ): array {
    foreach ($campaigns as &$campaign) {
      // Skip if trusted IP
      if ($this->isTrustedIp($campaign['ip'])) {
        $campaign['deep_dive'] = ['performed' => FALSE, 'reason' => 'trusted_ip'];
        continue;
      }

      // Deep dive BLOCK NOW and BLOCK MAYBE campaigns to evaluate against full history.
      // This prevents false positives from IPs with long-term patterns that look harmless in short windows.
      $blockingRec = $campaign['blocking_recommendation'] ?? [];
      $shouldBlock = $blockingRec['should_block'] ?? FALSE;
      $riskLevel = $blockingRec['risk_level'] ?? 'noise';

      // Only deep dive campaigns that would trigger blocking (BLOCK NOW or BLOCK MAYBE)
      if (!$shouldBlock && !in_array($riskLevel, ['critical', 'high', 'medium'], TRUE)) {
        $campaign['deep_dive'] = ['performed' => FALSE, 'reason' => 'low_risk'];
        continue;
      }

      // Perform deep-dive
      $deepDiveResult = $this->performDeepDive(
        $campaign['ip'],
        $allLogs,
        $thresholds,
        $exploitsCommand
      );

      if ($deepDiveResult) {
        $campaign['deep_dive'] = [
          'performed' => TRUE,
          'full_history_requests' => $deepDiveResult['count'] ?? 0,
          'full_history_first_seen' => $deepDiveResult['first_seen'] ?? NULL,
          'full_history_span_days' => $deepDiveResult['time_span_days'] ?? 0,
        ];

        // Update campaign with deep-dive insights
        if (($deepDiveResult['count'] ?? 0) > $campaign['count']) {
          $campaign['is_new_campaign'] = TRUE;
          $campaign['campaign_larger_than_window'] = TRUE;
        }
      }
      else {
        $campaign['deep_dive'] = ['performed' => TRUE, 'no_additional_history' => TRUE];
      }
    }

    return $campaigns;
  }

  /**
   * Save campaigns to campaign_analysis table.
   *
   * Optionally creates an analysis run record and links all campaigns to it.
   *
   * @param array $campaigns Array of campaigns
   * @param string $timeRange Time range used
   * @param string $timeStart Start timestamp
   * @param string $timeEnd End timestamp
   * @param bool $saveRun Whether to create analysis_run record (development feature)
   * @return array ['saved' => int, 'run_id' => int|null]
   */
  public function saveCampaigns(
    array $campaigns,
    string $timeRange,
    string $timeStart,
    string $timeEnd,
    bool $saveRun = FALSE
  ): array {
    // Create analysis run if enabled (development feature).
    $runId = NULL;
    if ($saveRun) {
      $runId = $this->createAnalysisRun($campaigns, $timeRange, $timeStart, $timeEnd);
    }

    $saved = 0;

    foreach ($campaigns as $campaign) {
      // Skip saving trusted IPs and very low confidence campaigns.
      if ($this->isTrustedIp($campaign['ip'])) {
        continue;
      }

      $blockingRec = $campaign['blocking_recommendation'] ?? [];
      if (($blockingRec['confidence'] ?? 'none') === 'none') {
        continue;
      }

      $fromDeepDive = $campaign['deep_dive']['performed'] ?? FALSE;

      $this->saveCampaignAnalysisToDb(
        $campaign,
        $timeRange,
        $timeStart,
        $timeEnd,
        $fromDeepDive,
        $runId
      );

      $saved++;
    }

    return ['saved' => $saved, 'run_id' => $runId];
  }

  /**
   * Get cached campaign analysis if available.
   *
   * Checks if campaign analysis exists for the specified time range and is recent enough.
   *
   * @param string $timeStart Start of time range (ISO 8601)
   * @param string $timeEnd End of time range (ISO 8601)
   * @param int $maxAgeMinutes Maximum cache age in minutes (default: 60)
   * @param string|null $timeRange Time range label (e.g., "last all", "2w") for fuzzy matching
   * @return array|null Array with 'campaigns' and 'age_minutes', or NULL if no cache
   */
  public function getCachedCampaigns(string $timeStart, string $timeEnd, int $maxAgeMinutes = 60, ?string $timeRange = NULL): ?array
  {
    $cached = $this->getCachedCampaignsByTimeRangeFromDb($timeStart, $timeEnd, $maxAgeMinutes, $timeRange);

    if ($cached === NULL) {
      return NULL;
    }

    // Calculate age in minutes for user display.
    // analyzed_at is stored in UTC (SQLite CURRENT_TIMESTAMP), so parse as UTC.
    $analyzedAt = new \DateTime($cached['analyzed_at'], new \DateTimeZone('UTC'));
    $now = new \DateTime('now', new \DateTimeZone('UTC'));
    $ageMinutes = (int) (($now->getTimestamp() - $analyzedAt->getTimestamp()) / 60);

    // Convert database format to campaign format expected by display code.
    $campaigns = [];
    foreach ($cached['campaigns'] as $row) {
      // Recalculate risk score from cached inputs.
      $riskInputs = json_decode($row['risk_inputs'] ?? '[]', TRUE);
      if (!empty($riskInputs)) {
        $riskResult = $this->riskScoring->calculateRiskScore($riskInputs);
      }
      else {
        // Fallback if risk_inputs missing (old cache).
        $riskResult = ['score' => 0, 'level' => 'noise', 'breakdown' => []];
      }

      // Map database columns to campaign structure.
      $campaigns[] = [
        'ip' => $row['ip'],
        'country' => $row['country'],
        'count' => $row['total_requests'],
        'exploit_requests' => $row['exploit_requests'],
        'first_seen' => $row['first_seen'],
        'last_seen' => $row['last_seen'],
        'time_span_days' => $row['time_span_days'],
        'scan_types' => $row['scan_types'],
        'severity' => $row['campaign_severity'],
        'top_paths' => $row['top_paths'],
        'targeted_sites' => $row['targeted_sites'] ?? [],
        'behavior' => $row['behavior_type'],
        'request_rate' => $row['request_rate'],
        'ratio_40x' => $row['ratio_40x'],
        'ratio_exploit' => $row['ratio_exploit'],
        'path_diversity' => $row['path_diversity'],
        'uri_dup_ratio' => $row['uri_dup_ratio'],
        'blocking_recommendation' => [
          'attack_severity' => $row['attack_severity'],
          'should_block' => (bool) $row['should_block'],
          'confidence' => $row['confidence'],
          'reasons' => $row['block_reasons'],
          'risk_score' => $riskResult['score'],
          'risk_level' => $riskResult['level'],
          'risk_breakdown' => $riskResult['breakdown'],
          'risk_inputs' => $riskInputs,
        ],
        'user_agent' => $row['user_agent'],
        'bot_name' => $row['bot_name'],
        'volume_analysis' => [
          'total_requests' => $row['total_requests'],
          'requests_per_hour' => $row['request_rate'],
          'behavior_type' => $row['behavior_type'],
          'ratio_40x' => $row['ratio_40x'],
          'ratio_edge_blocked' => $row['ratio_edge_blocked'],
          'total_volume' => $row['total_volume'],
        ],
        'from_cache' => TRUE, // Mark as cached for debugging
      ];
    }

    return [
      'campaigns' => $campaigns,
      'age_minutes' => $ageMinutes,
    ];
  }

  /**
   * Save campaign analysis to database.
   *
   * @param array $campaign Campaign data
   * @param string $timeRange Time range used for analysis
   * @param string $timeStart ISO 8601 timestamp
   * @param string $timeEnd ISO 8601 timestamp
   * @param bool $fromDeepDive Whether from deep-dive
   * @param int|null $runId Optional analysis run ID to link this campaign to
   * @return int Campaign analysis ID
   */
  protected function saveCampaignAnalysisToDb(
    array $campaign,
    string $timeRange,
    string $timeStart,
    string $timeEnd,
    bool $fromDeepDive = FALSE,
    ?int $runId = NULL
  ): int {
    $blockingRec = $campaign['blocking_recommendation'] ?? [];

    $stmt = $this->database->prepare(<<<'SQL'
INSERT OR REPLACE INTO campaign_analysis (
  ip, country,
  time_start, time_end, time_range, analyzed_at,
  total_requests, exploit_requests, first_seen, last_seen, time_span_days,
  scan_types, campaign_severity, attack_severity, top_paths,
  behavior_type, request_rate, ratio_40x, ratio_exploit, path_diversity, uri_dup_ratio,
  should_block, confidence, block_reasons,
  risk_inputs,
  user_agent, bot_name, total_volume, ratio_edge_blocked,
  from_deep_dive, targeted_sites, run_id
) VALUES (
  :ip, :country,
  :time_start, :time_end, :time_range, CURRENT_TIMESTAMP,
  :total_requests, :exploit_requests, :first_seen, :last_seen, :time_span_days,
  :scan_types, :campaign_severity, :attack_severity, :top_paths,
  :behavior_type, :request_rate, :ratio_40x, :ratio_exploit, :path_diversity, :uri_dup_ratio,
  :should_block, :confidence, :block_reasons,
  :risk_inputs,
  :user_agent, :bot_name, :total_volume, :ratio_edge_blocked,
  :from_deep_dive, :targeted_sites, :run_id
)
SQL
    );

    $volumeAnalysis = $campaign['volume_analysis'] ?? [];

    $stmt->execute([
      ':ip' => $campaign['ip'] ?? '',
      ':country' => $campaign['country'] ?? '',
      ':time_start' => $timeStart,
      ':time_end' => $timeEnd,
      ':time_range' => $timeRange,
      ':total_requests' => $campaign['count'] ?? 0,
      ':exploit_requests' => $campaign['count'] ?? 0,
      ':first_seen' => $campaign['first_seen'] ?? NULL,
      ':last_seen' => $campaign['last_seen'] ?? NULL,
      ':time_span_days' => $campaign['time_span_days'] ?? 0,
      ':scan_types' => json_encode($campaign['scan_types'] ?? []),
      ':campaign_severity' => $campaign['campaign_severity'] ?? 'low',
      ':attack_severity' => $blockingRec['attack_severity'] ?? 'low',
      ':top_paths' => json_encode($campaign['top_paths'] ?? []),
      ':behavior_type' => $campaign['behavior'] ?? '',
      ':request_rate' => $campaign['request_rate'] ?? 0,
      ':ratio_40x' => $campaign['ratio_40x'] ?? 0,
      ':ratio_exploit' => $campaign['ratio_exploit'] ?? 0,
      ':path_diversity' => $campaign['path_diversity'] ?? 0,
      ':uri_dup_ratio' => $campaign['uri_dup_ratio'] ?? 0,
      ':should_block' => ($blockingRec['should_block'] ?? FALSE) ? 1 : 0,
      ':confidence' => $blockingRec['confidence'] ?? 'none',
      ':block_reasons' => json_encode($blockingRec['reasons'] ?? []),
      ':risk_inputs' => json_encode($blockingRec['risk_inputs'] ?? NULL),
      ':user_agent' => $campaign['user_agent'] ?? '',
      ':bot_name' => $campaign['bot_name'] ?? NULL,
      ':total_volume' => $volumeAnalysis['total_requests'] ?? 0,
      ':ratio_edge_blocked' => $volumeAnalysis['ratio_edge_blocked'] ?? 0,
      ':from_deep_dive' => $fromDeepDive ? 1 : 0,
      ':targeted_sites' => json_encode($campaign['targeted_sites'] ?? []),
      ':run_id' => $runId,
    ]);

    return (int) $this->database->lastInsertId();
  }

  /**
   * Get campaign analysis for a specific IP.
   *
   * @param string $ip IP address
   * @return array|null Campaign analysis or NULL if not found
   */
  protected function getCampaignAnalysisByIpFromDb(string $ip): ?array
  {
    $stmt = $this->database->prepare(<<<'SQL'
SELECT * FROM campaign_analysis
WHERE ip = :ip
ORDER BY analyzed_at DESC
LIMIT 1
SQL
    );

    $stmt->execute([':ip' => $ip]);
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$result) {
      return NULL;
    }

    // Decode JSON fields.
    $result['scan_types'] = json_decode($result['scan_types'] ?? '[]', TRUE);
    $result['top_paths'] = json_decode($result['top_paths'] ?? '[]', TRUE);
    $result['block_reasons'] = json_decode($result['block_reasons'] ?? '[]', TRUE);
    $result['targeted_sites'] = json_decode($result['targeted_sites'] ?? '[]', TRUE);

    return $result;
  }

  /**
   * Get all campaign analyses within a time range.
   *
   * @param string|null $since Start time (ISO 8601)
   * @param string|null $until End time (ISO 8601)
   * @param string|null $minConfidence Minimum confidence level
   * @return array Array of campaign analyses
   */
  protected function getCampaignAnalysesFromDb(
    ?string $since = NULL,
    ?string $until = NULL,
    ?string $minConfidence = NULL
  ): array {
    $sql = 'SELECT * FROM campaign_analysis WHERE 1=1';
    $params = [];

    if ($since) {
      $sql .= ' AND analyzed_at >= :since';
      $params[':since'] = $since;
    }

    if ($until) {
      $sql .= ' AND analyzed_at <= :until';
      $params[':until'] = $until;
    }

    if ($minConfidence) {
      $confidenceLevels = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'urgent' => 4];
      $minLevel = $confidenceLevels[$minConfidence] ?? 0;

      $sql .= ' AND confidence IN (';
      $sql .= implode(',', array_map(fn($k) => "'$k'", array_filter(
        array_keys($confidenceLevels),
        fn($k) => $confidenceLevels[$k] >= $minLevel
      )));
      $sql .= ')';
    }

    $sql .= ' ORDER BY analyzed_at DESC';

    $stmt = $this->database->prepare($sql);
    $stmt->execute($params);

    $results = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      // Decode JSON fields.
      $row['scan_types'] = json_decode($row['scan_types'] ?? '[]', TRUE);
      $row['top_paths'] = json_decode($row['top_paths'] ?? '[]', TRUE);
      $row['block_reasons'] = json_decode($row['block_reasons'] ?? '[]', TRUE);
      $results[] = $row;
    }

    return $results;
  }

  /**
   * Check if IP is in recent campaign analysis.
   *
   * @param string $ip IP address
   * @param int $daysBack Number of days to look back
   * @return bool TRUE if IP has recent analysis
   */
  protected function hasRecentCampaignAnalysisInDb(string $ip, int $daysBack = 7): bool
  {
    $since = date('Y-m-d H:i:s', strtotime("-$daysBack days"));

    $stmt = $this->database->prepare(<<<'SQL'
SELECT COUNT(*) as count FROM campaign_analysis
WHERE ip = :ip AND analyzed_at >= :since
SQL
    );

    $stmt->execute([
      ':ip' => $ip,
      ':since' => $since,
    ]);

    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return ($row['count'] ?? 0) > 0;
  }

  /**
   * Get campaign analyses for a specific time range.
   *
   * @param string $timeStart Start of analyzed time range (ISO 8601)
   * @param string $timeEnd End of analyzed time range (ISO 8601)
   * @param int $maxAgeMinutes Maximum age of analysis in minutes
   * @param string|null $timeRange Time range label for fuzzy matching
   * @return array|null Array with 'campaigns' and 'analyzed_at', or NULL
   */
  protected function getCachedCampaignsByTimeRangeFromDb(string $timeStart, string $timeEnd, int $maxAgeMinutes = 60, ?string $timeRange = NULL): ?array
  {
    // Calculate cutoff time for cache freshness.
    $cutoffTime = gmdate('Y-m-d H:i:s', time() - ($maxAgeMinutes * 60));

    // Step 1: Find the most recent analysis timestamp.
    if ($timeRange !== NULL) {
      $stmt = $this->database->prepare(<<<'SQL'
SELECT MAX(analyzed_at) as latest_analysis
FROM campaign_analysis
WHERE time_range = :time_range
  AND analyzed_at >= :cutoff
SQL
      );

      $stmt->execute([
        ':time_range' => $timeRange,
        ':cutoff' => $cutoffTime,
      ]);
    }
    else {
      $stmt = $this->database->prepare(<<<'SQL'
SELECT MAX(analyzed_at) as latest_analysis
FROM campaign_analysis
WHERE time_start = :time_start
  AND time_end = :time_end
  AND analyzed_at >= :cutoff
SQL
      );

      $stmt->execute([
        ':time_start' => $timeStart,
        ':time_end' => $timeEnd,
        ':cutoff' => $cutoffTime,
      ]);
    }

    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    $analyzedAt = $result['latest_analysis'] ?? NULL;

    if ($analyzedAt === NULL) {
      return NULL;
    }

    // Step 2: Get all campaigns from that specific analysis.
    $stmt = $this->database->prepare(<<<'SQL'
SELECT *
FROM campaign_analysis
WHERE analyzed_at = :analyzed_at
SQL
    );

    $stmt->execute([':analyzed_at' => $analyzedAt]);

    $campaigns = [];

    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      // Decode JSON fields.
      $row['scan_types'] = json_decode($row['scan_types'] ?? '[]', TRUE);
      $row['top_paths'] = json_decode($row['top_paths'] ?? '[]', TRUE);
      $row['block_reasons'] = json_decode($row['block_reasons'] ?? '[]', TRUE);
      $row['targeted_sites'] = json_decode($row['targeted_sites'] ?? '[]', TRUE);

      // Reconstruct blocking_recommendation object from flat database fields.
      $row['blocking_recommendation'] = [
        'attack_severity' => $row['attack_severity'] ?? 'low',
        'confidence' => $row['confidence'] ?? 'none',
        'should_block' => (bool) ($row['should_block'] ?? 0),
        'reasons' => $row['block_reasons'],
      ];

      $campaigns[] = $row;
    }

    if (empty($campaigns)) {
      return NULL;
    }

    return [
      'campaigns' => $campaigns,
      'analyzed_at' => $analyzedAt,
    ];
  }

  /**
   * Create an analysis run record.
   *
   * Captures configuration snapshot, git context, and summary statistics.
   *
   * @param array $campaigns Array of campaigns to be saved
   * @param string $timeRange Time range used
   * @param string $timeStart Start timestamp (ISO 8601)
   * @param string $timeEnd End timestamp (ISO 8601)
   * @return int The run_id of the created analysis run
   */
  public function createAnalysisRun(
    array $campaigns,
    string $timeRange,
    string $timeStart,
    string $timeEnd
  ): int {
    // Get configuration snapshots.
    $riskScoringConfig = $this->config->getRiskScoringConfig();
    $blockingConfig = $this->config->get('blocking', []);

    // Get git information.
    $gitInfo = $this->getGitInfo();

    // Calculate summary statistics.
    $stats = $this->calculateSummaryStats($campaigns);

    // Insert analysis run.
    $stmt = $this->database->prepare(<<<'SQL'
INSERT INTO analysis_runs (
  run_at, time_range, time_start, time_end,
  risk_scoring_config, blocking_config,
  git_commit, git_branch, git_dirty,
  total_campaigns, block_now_count, block_maybe_count, review_count, allow_count,
  critical_count, high_count, medium_count, low_count
) VALUES (
  CURRENT_TIMESTAMP, :time_range, :time_start, :time_end,
  :risk_scoring_config, :blocking_config,
  :git_commit, :git_branch, :git_dirty,
  :total_campaigns, :block_now_count, :block_maybe_count, :review_count, :allow_count,
  :critical_count, :high_count, :medium_count, :low_count
)
SQL
    );

    $stmt->execute([
      ':time_range' => $timeRange,
      ':time_start' => $timeStart,
      ':time_end' => $timeEnd,
      ':risk_scoring_config' => json_encode($riskScoringConfig),
      ':blocking_config' => json_encode($blockingConfig),
      ':git_commit' => $gitInfo['commit'],
      ':git_branch' => $gitInfo['branch'],
      ':git_dirty' => $gitInfo['dirty'] ? 1 : 0,
      ':total_campaigns' => $stats['total'],
      ':block_now_count' => $stats['block_now'],
      ':block_maybe_count' => $stats['block_maybe'],
      ':review_count' => $stats['review'],
      ':allow_count' => $stats['allow'],
      ':critical_count' => $stats['critical'],
      ':high_count' => $stats['high'],
      ':medium_count' => $stats['medium'],
      ':low_count' => $stats['low'],
    ]);

    return (int) $this->database->lastInsertId();
  }

  /**
   * Get git repository information.
   *
   * @return array Git information (commit, branch, dirty)
   */
  protected function getGitInfo(): array
  {
    $info = [
      'commit' => NULL,
      'branch' => NULL,
      'dirty' => FALSE,
    ];

    // Check if we're in a git repository.
    $gitDir = getcwd();
    while ($gitDir !== '/' && !is_dir($gitDir . '/.git')) {
      $gitDir = dirname($gitDir);
    }

    if (!is_dir($gitDir . '/.git')) {
      return $info;
    }

    // Get current commit.
    $commit = @shell_exec('cd ' . escapeshellarg($gitDir) . ' && git rev-parse HEAD 2>/dev/null');
    if ($commit) {
      $info['commit'] = trim($commit);
    }

    // Get current branch.
    $branch = @shell_exec('cd ' . escapeshellarg($gitDir) . ' && git rev-parse --abbrev-ref HEAD 2>/dev/null');
    if ($branch) {
      $info['branch'] = trim($branch);
    }

    // Check if working directory is dirty.
    $status = @shell_exec('cd ' . escapeshellarg($gitDir) . ' && git status --porcelain 2>/dev/null');
    if ($status && trim($status) !== '') {
      $info['dirty'] = TRUE;
    }

    return $info;
  }

  /**
   * Calculate summary statistics from campaigns.
   *
   * @param array $campaigns Array of campaigns
   * @return array Summary statistics
   */
  protected function calculateSummaryStats(array $campaigns): array
  {
    $stats = [
      'total' => 0,
      'block_now' => 0,
      'block_maybe' => 0,
      'review' => 0,
      'allow' => 0,
      'critical' => 0,
      'high' => 0,
      'medium' => 0,
      'low' => 0,
    ];

    foreach ($campaigns as $campaign) {
      // Skip trusted IPs and none confidence.
      if ($this->isTrustedIp($campaign['ip'])) {
        continue;
      }

      $blockingRec = $campaign['blocking_recommendation'] ?? [];
      $confidence = $blockingRec['confidence'] ?? 'none';

      if ($confidence === 'none') {
        continue;
      }

      $stats['total']++;

      // Count by blocking decision.
      if ($confidence === 'urgent' || $confidence === 'high') {
        $stats['block_now']++;
      }
      elseif ($confidence === 'medium') {
        $stats['block_maybe']++;
      }
      elseif ($confidence === 'low') {
        $stats['review']++;
      }

      // Count by severity.
      $severity = $campaign['campaign_severity'] ?? 'low';
      if (isset($stats[$severity])) {
        $stats[$severity]++;
      }
    }

    return $stats;
  }

}
