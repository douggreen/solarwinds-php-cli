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
   */
  public function __construct(
    protected ConfigurationService $config,
    protected DatabaseService $database
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
   * @param array $campaign Campaign data
   * @param array $thresholds Threshold configuration
   * @return bool TRUE if this is a single-event burst
   */
  public function isSingleEvent(array $campaign, array $thresholds): bool
  {
    $timeSpanDays = $campaign['time_span_days'] ?? 0;
    $lastSeen = $campaign['last_seen'] ?? NULL;

    if ($lastSeen === NULL || $lastSeen === 'unknown') {
      return FALSE;
    }

    $threshold = $thresholds['single_event_threshold_hours'];
    $thresholdDays = $threshold / 24;

    // Check if burst duration was short
    $isShortBurst = $timeSpanDays < $thresholdDays;

    // Check if it ended (not continuing)
    $timeSinceLastSeen = time() - strtotime($lastSeen);
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

    $timeSinceLastSeen = time() - strtotime($lastSeen);

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
      $historical = $this->database->getCampaignAnalysisByIp($ip);

      if ($historical) {
        $campaign['historical'] = [
          'found' => TRUE,
          'last_analyzed' => $historical['analyzed_at'],
          'last_confidence' => $historical['confidence'],
          'last_severity' => $historical['severity'],
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
    $firstLogDate = $this->database->getEarliestLogDate();

    if (!$firstLogDate) {
      return NULL; // No logs in database
    }

    // Query ALL logs for this specific IP
    $ipLogs = $this->database->getLogsByIp($ip, $firstLogDate, NULL);

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
      // Skip if already in historical analysis
      if ($campaign['historical']['found'] ?? FALSE) {
        $campaign['deep_dive'] = ['performed' => FALSE, 'reason' => 'in_historical'];
        continue;
      }

      // Skip if trusted IP
      if ($this->isTrustedIp($campaign['ip'])) {
        $campaign['deep_dive'] = ['performed' => FALSE, 'reason' => 'trusted_ip'];
        continue;
      }

      // Skip if low confidence/volume
      $blockingRec = $campaign['blocking_recommendation'] ?? [];
      $confidence = $blockingRec['confidence'] ?? 'none';

      if (!in_array($confidence, ['medium', 'high', 'urgent'], TRUE)) {
        $campaign['deep_dive'] = ['performed' => FALSE, 'reason' => 'low_confidence'];
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
   * @param array $campaigns Array of campaigns
   * @param string $timeRange Time range used
   * @param string $timeStart Start timestamp
   * @param string $timeEnd End timestamp
   * @return int Number of campaigns saved
   */
  public function saveCampaigns(
    array $campaigns,
    string $timeRange,
    string $timeStart,
    string $timeEnd
  ): int {
    $saved = 0;

    foreach ($campaigns as $campaign) {
      // Skip saving trusted IPs and very low confidence campaigns
      if ($this->isTrustedIp($campaign['ip'])) {
        continue;
      }

      $blockingRec = $campaign['blocking_recommendation'] ?? [];
      if (($blockingRec['confidence'] ?? 'none') === 'none') {
        continue;
      }

      $fromDeepDive = $campaign['deep_dive']['performed'] ?? FALSE;

      $this->database->saveCampaignAnalysis(
        $campaign,
        $timeRange,
        $timeStart,
        $timeEnd,
        $fromDeepDive
      );

      $saved++;
    }

    return $saved;
  }

}
