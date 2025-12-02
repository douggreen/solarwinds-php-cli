<?php

/**
 * @file RiskScoringService.php
 * @brief Risk scoring service for exploit campaign analysis
 *
 * @class RiskScoringService
 * @brief Calculates risk scores for exploit campaigns using configurable weights
 *
 * This service implements a multi-factor risk scoring system that evaluates
 * exploit campaigns based on origin impact, volume rate, attack severity,
 * historical context, and server impact. Scores are normalized to 0-100
 * and classified into risk levels (critical, high, medium, low, noise).
 *
 * @section scoring_factors Scoring Factors
 *
 * **Origin Impact (default weight: 0.30):**
 * - Edge-blocked traffic: 0 points (no risk to origin)
 * - Mixed CDN/origin: 20-60 points based on ratio
 * - Direct origin hits: 100 points (bypassed CDN)
 *
 * **Volume Rate (default weight: 0.25):**
 * - 10 req/hr: 10 points (background noise)
 * - 100 req/hr: 50 points (moderate)
 * - 1000 req/hr: 85 points (very high)
 * - 5000+ req/hr: 100 points (extreme)
 *
 * **Attack Severity (default weight: 0.20):**
 * - Single low pattern: 10 points
 * - Single critical pattern: 60 points
 * - Multiple patterns: +20 bonus
 * - 3+ patterns: +30 bonus
 *
 * **Recency (default weight: 0.15):**
 * - Active now (<1h): 100 points (maximum priority)
 * - Last 6 hours: 90 points (very recent)
 * - Last 24 hours: 70 points (recent)
 * - Last 3 days: 40 points (moderately recent)
 * - Last week: 20 points (aging)
 * - Over 1 week old: 0 points (historical only)
 *
 * **Historical Context (default weight: 0.10):**
 * - No history: 0 points
 * - Seen before: 20-50 points
 * - Known bad actor: 100 points
 *
 * **Server Impact (default weight: 0.10):**
 * - All 404s: 0 points (no PHP execution)
 * - PHP errors: 80 points
 * - Successful exploitation: 100 points
 *
 * @section example Usage Example
 * @code{.php}
 * $scorer = new RiskScoringService($configService);
 *
 * $campaign = [
 *   'requests_per_hour' => 500,
 *   'scan_types' => ['sqli', 'xss', 'lfi'],
 *   'origin_rate' => 0.9,
 *   'status_40x_rate' => 0.7,
 * ];
 *
 * $result = $scorer->calculateRiskScore($campaign);
 * // Returns: ['score' => 78.5, 'level' => 'high', 'breakdown' => [...]]
 * @endcode
 *
 * @see ConfigurationService For risk scoring configuration
 * @see ExploitsCommand For campaign analysis usage
 *
 * @note All scores are normalized to 0-100 range before weighting
 * @warning Requires campaign_analysis database for historical context
 */

namespace SolarWinds\Services;

/**
 * Risk Scoring Service
 *
 * Calculates multi-factor risk scores for exploit campaigns.
 */
class RiskScoringService
{
  /**
   * Constructor.
   */
  public function __construct(protected ConfigurationService $config)
  {
  }

  /**
   * Calculate risk score for a campaign.
   *
   * Evaluates multiple risk factors and returns a weighted score (0-100)
   * along with risk level classification and detailed breakdown.
   *
   * @param array $campaign Campaign data including metrics and patterns
   * @return array Risk assessment with score, level, and breakdown
   */
  public function calculateRiskScore(array $campaign): array
  {
    $riskConfig = $this->config->getRiskScoringConfig();
    $weights = $riskConfig['weights'];

    // Calculate individual factor scores (0-100)
    $originScore = $this->calculateOriginImpactScore($campaign, $riskConfig);
    $volumeScore = $this->calculateVolumeRateScore($campaign, $riskConfig);
    $severityScore = $this->calculateAttackSeverityScore($campaign, $riskConfig);
    $recencyScore = $this->calculateRecencyScore($campaign, $riskConfig);
    $historicalScore = $this->calculateHistoricalScore($campaign, $riskConfig);
    $serverScore = $this->calculateServerImpactScore($campaign, $riskConfig);

    // Calculate weighted total
    $totalScore = (
      ($originScore * $weights['origin_impact']) +
      ($volumeScore * $weights['volume_rate']) +
      ($severityScore * $weights['attack_severity']) +
      ($recencyScore * $weights['recency']) +
      ($historicalScore * $weights['historical']) +
      ($serverScore * $weights['server_impact'])
    );

    // Classify risk level
    $riskLevel = $this->config->classifyRiskScore($totalScore);

    return [
      'score' => round($totalScore, 1),
      'level' => $riskLevel,
      'breakdown' => [
        'origin_impact' => [
          'score' => $originScore,
          'weighted' => round($originScore * $weights['origin_impact'], 1),
          'weight' => $weights['origin_impact'],
        ],
        'volume_rate' => [
          'score' => $volumeScore,
          'weighted' => round($volumeScore * $weights['volume_rate'], 1),
          'weight' => $weights['volume_rate'],
        ],
        'attack_severity' => [
          'score' => $severityScore,
          'weighted' => round($severityScore * $weights['attack_severity'], 1),
          'weight' => $weights['attack_severity'],
        ],
        'recency' => [
          'score' => $recencyScore,
          'weighted' => round($recencyScore * $weights['recency'], 1),
          'weight' => $weights['recency'],
        ],
        'historical' => [
          'score' => $historicalScore,
          'weighted' => round($historicalScore * $weights['historical'], 1),
          'weight' => $weights['historical'],
        ],
        'server_impact' => [
          'score' => $serverScore,
          'weighted' => round($serverScore * $weights['server_impact'], 1),
          'weight' => $weights['server_impact'],
        ],
      ],
    ];
  }

  /**
   * Calculate origin impact score (0-100).
   *
   * Evaluates how much traffic bypassed CDN edge protection.
   * Higher origin rate = higher risk (CDN bypass).
   *
   * @param array $campaign Campaign data
   * @param array $riskConfig Risk scoring configuration
   * @return float Score from 0-100
   */
  protected function calculateOriginImpactScore(array $campaign, array $riskConfig): float
  {
    $originRate = $campaign['origin_rate'] ?? 0.0;
    $config = $riskConfig['origin_impact'];

    // Linear interpolation based on origin rate
    if ($originRate >= 1.0) {
      return (float) $config['origin_high'];
    }
    if ($originRate >= 0.8) {
      return (float) $config['origin_high'];
    }
    if ($originRate >= 0.5) {
      return (float) $config['origin_50_percent'];
    }
    if ($originRate >= 0.2) {
      return (float) $config['edge_blocked_high'];
    }

    return (float) $config['edge_blocked_100'];
  }

  /**
   * Calculate volume rate score (0-100).
   *
   * Uses configured thresholds to score request volume.
   * Linear interpolation between threshold points.
   *
   * @param array $campaign Campaign data
   * @param array $riskConfig Risk scoring configuration
   * @return float Score from 0-100
   */
  protected function calculateVolumeRateScore(array $campaign, array $riskConfig): float
  {
    $requestsPerHour = $campaign['requests_per_hour'] ?? 0;
    $thresholds = $riskConfig['volume_rate']['thresholds'];

    // Find the appropriate threshold range
    $prevThreshold = ['rate' => 0, 'score' => 0];

    foreach ($thresholds as $threshold) {
      if ($requestsPerHour <= $threshold['rate']) {
        // Linear interpolation between previous and current threshold
        $rateDiff = $threshold['rate'] - $prevThreshold['rate'];
        $scoreDiff = $threshold['score'] - $prevThreshold['score'];

        if ($rateDiff == 0) {
          return (float) $threshold['score'];
        }

        $ratio = ($requestsPerHour - $prevThreshold['rate']) / $rateDiff;
        return (float) ($prevThreshold['score'] + ($ratio * $scoreDiff));
      }

      $prevThreshold = $threshold;
    }

    // Exceeds highest threshold
    return (float) end($thresholds)['score'];
  }

  /**
   * Calculate attack severity score (0-100).
   *
   * Evaluates pattern severity and diversity:
   * - Single pattern: Base score based on severity
   * - Multiple patterns: Base score + bonuses
   *
   * @param array $campaign Campaign data
   * @param array $riskConfig Risk scoring configuration
   * @return float Score from 0-100
   */
  protected function calculateAttackSeverityScore(array $campaign, array $riskConfig): float
  {
    $scanTypes = $campaign['scan_types'] ?? [];
    $config = $riskConfig['attack_severity'];

    if (empty($scanTypes)) {
      return 0.0;
    }

    // Determine highest severity level present
    $hasCritical = FALSE;
    $hasHigh = FALSE;
    $hasMedium = FALSE;
    $hasLow = FALSE;

    foreach ($scanTypes as $type) {
      if (in_array($type, $config['critical_patterns'])) {
        $hasCritical = TRUE;
      }
      elseif (in_array($type, $config['high_patterns'])) {
        $hasHigh = TRUE;
      }
      elseif (in_array($type, $config['medium_patterns'])) {
        $hasMedium = TRUE;
      }
      elseif (in_array($type, $config['low_patterns'])) {
        $hasLow = TRUE;
      }
    }

    // Base score from highest severity
    $baseScore = 0;
    if ($hasCritical) {
      $baseScore = $config['single_critical'];
    }
    elseif ($hasHigh) {
      $baseScore = $config['single_high'];
    }
    elseif ($hasMedium) {
      $baseScore = $config['single_medium'];
    }
    elseif ($hasLow) {
      $baseScore = $config['single_low'];
    }

    // Add bonuses for multiple patterns
    $patternCount = count($scanTypes);
    $bonus = 0;

    if ($patternCount >= 3) {
      $bonus = $config['three_plus_types_bonus'];
    }
    elseif ($patternCount >= 2) {
      $bonus = $config['multiple_types_bonus'];
    }

    return (float) min(100, $baseScore + $bonus);
  }

  /**
   * Calculate recency score (0-100).
   *
   * Evaluates how recently the campaign was active.
   * Recent/active campaigns get higher priority than old dormant ones.
   *
   * @param array $campaign Campaign data
   * @param array $riskConfig Risk scoring configuration
   * @return float Score from 0-100
   */
  protected function calculateRecencyScore(array $campaign, array $riskConfig): float
  {
    $lastSeen = $campaign['last_seen'] ?? NULL;

    if ($lastSeen === NULL) {
      // No timestamp available, assume not recent.
      return 0.0;
    }

    $config = $riskConfig['recency'] ?? [];

    // Calculate hours since last activity.
    $now = time();
    $hoursAgo = ($now - $lastSeen) / 3600;

    // Use configured thresholds with fallback to sensible defaults.
    if ($hoursAgo < ($config['active_now_hours'] ?? 1)) {
      return (float) ($config['active_now'] ?? 100);
    }
    if ($hoursAgo < ($config['very_recent_hours'] ?? 6)) {
      return (float) ($config['very_recent'] ?? 90);
    }
    if ($hoursAgo < ($config['recent_hours'] ?? 24)) {
      return (float) ($config['recent'] ?? 70);
    }
    if ($hoursAgo < ($config['moderately_recent_hours'] ?? 72)) {
      return (float) ($config['moderately_recent'] ?? 40);
    }
    if ($hoursAgo < ($config['aging_hours'] ?? 168)) {
      return (float) ($config['aging'] ?? 20);
    }

    // Over 1 week old = historical only.
    return (float) ($config['historical'] ?? 0);
  }

  /**
   * Calculate historical context score (0-100).
   *
   * Evaluates IP history from campaign_analysis database.
   * Higher score for repeat offenders.
   *
   * @param array $campaign Campaign data
   * @param array $riskConfig Risk scoring configuration
   * @return float Score from 0-100
   */
  protected function calculateHistoricalScore(array $campaign, array $riskConfig): float
  {
    $historicalRiskLevel = $campaign['historical_risk_level'] ?? NULL;
    $config = $riskConfig['historical'];

    if ($historicalRiskLevel === NULL) {
      return (float) $config['no_history'];
    }

    // Map historical risk level to score
    $mapping = [
      'critical' => $config['known_bad_actor'],
      'high' => $config['known_bad_actor'],
      'medium' => $config['seen_before_medium'],
      'low' => $config['seen_before_low'],
      'noise' => $config['no_history'],
    ];

    return (float) ($mapping[$historicalRiskLevel] ?? $config['no_history']);
  }

  /**
   * Calculate server impact score (0-100).
   *
   * Evaluates actual server impact from response status codes.
   * Higher scores for PHP execution, errors, or successful exploitation.
   *
   * @param array $campaign Campaign data
   * @param array $riskConfig Risk scoring configuration
   * @return float Score from 0-100
   */
  protected function calculateServerImpactScore(array $campaign, array $riskConfig): float
  {
    $status20xRate = $campaign['status_20x_rate'] ?? 0.0;
    $status40xRate = $campaign['status_40x_rate'] ?? 0.0;
    $status50xRate = $campaign['status_50x_rate'] ?? 0.0;
    $config = $riskConfig['server_impact'];

    // Prioritize by severity
    if ($status20xRate > 0.1) {
      // 10%+ successful responses = potential exploitation
      return (float) $config['php_success_200'];
    }
    if ($status50xRate > 0.1) {
      // 10%+ server errors
      return (float) $config['php_errors_5xx'];
    }
    if ($status40xRate > 0.8) {
      // 80%+ client errors = likely all rejected
      // But still hit PHP to generate 40x
      return (float) $config['php_execution_40x'];
    }
    if ($status40xRate > 0.0) {
      // Some client errors = at least some PHP execution
      return (float) $config['php_execution_404'];
    }

    // All blocked at edge/firewall (no status codes)
    return (float) $config['no_php_execution'];
  }
}
