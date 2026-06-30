#!/usr/bin/env php
<?php

/**
 * Test the volume gate in RiskScoringService.
 *
 * The gate multiplies the weighted score by min(1, max(total/count_sat,
 * rate/rate_sat)). It must:
 *  - leave high-rate bursts untouched (gate = 1.0 via the rate threshold),
 *  - leave sustained high-count campaigns untouched (gate = 1.0 via count),
 *  - discount low-count-AND-low-rate one-off probes (gate < 1.0).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\RiskScoringService;

// Mock config with fixed risk scoring so the test is independent of ~/.solarwinds.yml.
$mockConfig = new class extends ConfigurationService {
  /**
   * Construct without reading any config file.
   */
  public function __construct() {
  }

  /**
   * Return a fixed risk-scoring config (gate saturations 30 count / 100 rate).
   */
  public function getRiskScoringConfig(): array {
    return [
      'weights' => [
        'origin_impact' => 0.30,
        'volume_rate' => 0.25,
        'attack_severity' => 0.20,
        'recency' => 0.20,
        'server_impact' => 0.05,
      ],
      'volume_gate' => [
        'count_saturation' => 30,
        'rate_saturation' => 100,
      ],
      'origin_impact' => [
        'edge_blocked_100' => 0,
        'edge_blocked_high' => 20,
        'origin_50_percent' => 60,
        'origin_high' => 100,
      ],
      'volume_rate' => [
        'thresholds' => [
          ['rate' => 10, 'score' => 10],
          ['rate' => 100, 'score' => 50],
          ['rate' => 1000, 'score' => 85],
        ],
      ],
      'attack_severity' => [
        'critical_patterns' => ['rce', 'cmdi'],
        'high_patterns' => ['lfi', 'xss'],
        'medium_patterns' => ['file', 'dbg'],
        'low_patterns' => ['scan', 'info'],
        'single_low' => 10,
        'single_medium' => 20,
        'single_high' => 40,
        'single_critical' => 60,
        'multiple_types_bonus' => 20,
        'three_plus_types_bonus' => 30,
      ],
      'recency' => [
        'active_now_hours' => 1,
        'active_now_score' => 100,
        'recent_hours' => 24,
        'recent_score' => 70,
        'this_week_days' => 7,
        'this_week_score' => 40,
        'older_score' => 10,
      ],
      'server_impact' => [
        'error_high' => 100,
        'error_medium' => 50,
      ],
    ];
  }

  /**
   * Simple threshold classifier for the test.
   */
  public function classifyRiskScore(float $score): string {
    if ($score >= 70) {
      return 'critical';
    }
    if ($score >= 50) {
      return 'high';
    }
    if ($score >= 35) {
      return 'medium';
    }
    if ($score >= 15) {
      return 'low';
    }
    return 'noise';
  }
};

$svc = new RiskScoringService($mockConfig);

$assert = function(string $name, bool $cond, string $detail = ''): void {
  echo ($cond ? "✅ PASS: " : "❌ FAIL: ") . $name . "\n";
  if ($detail !== '') {
    echo "   $detail\n";
  }
};

$now = time();
$base = [
  'scan_types' => ['lfi'],
  'origin_rate' => 0.99,
  'status_20x_rate' => 0.0,
  'status_40x_rate' => 0.02,
  'status_50x_rate' => 0.0,
  'last_seen' => $now - 1800,
];

// Test 1: High-rate burst (low count, high rate) — gate must be 1.0.
echo "=== Test 1: High-rate burst (63 req, 1191/hr) ===\n";
$r = $svc->calculateRiskScore($base + ['total_requests' => 63, 'requests_per_hour' => 1191]);
$assert('gate is 1.0 (rate saturates it)', $r['volume_gate'] === 1.0, "gate={$r['volume_gate']}");
$assert('score == pre-gate score (untouched)', $r['score'] === $r['pre_gate_score'], "{$r['score']} vs {$r['pre_gate_score']}");
echo "\n";

// Test 2: Sustained high-count (high count, low rate) — gate must be 1.0.
echo "=== Test 2: Sustained high-count (500 req, 5/hr) ===\n";
$r = $svc->calculateRiskScore($base + ['total_requests' => 500, 'requests_per_hour' => 5]);
$assert('gate is 1.0 (count saturates it)', $r['volume_gate'] === 1.0, "gate={$r['volume_gate']}");
$assert('score == pre-gate score (untouched)', $r['score'] === $r['pre_gate_score']);
echo "\n";

// Test 3: Low-count low-rate probe — gate must discount it.
echo "=== Test 3: One-off probe (6 req, 6/hr) ===\n";
$r = $svc->calculateRiskScore($base + ['total_requests' => 6, 'requests_per_hour' => 6]);
$expectedGate = max(6 / 30, 6 / 100); // 0.2
$assert('gate ~0.2', abs($r['volume_gate'] - $expectedGate) < 0.01, "gate={$r['volume_gate']}, expected ~{$expectedGate}");
$assert('score is discounted below pre-gate', $r['score'] < $r['pre_gate_score'], "{$r['score']} < {$r['pre_gate_score']}");
$assert('score ~= pre_gate * gate', abs($r['score'] - $r['pre_gate_score'] * $r['volume_gate']) < 0.2);
echo "\n";

// Test 4: Count exactly at saturation (30, slow) — gate 1.0.
echo "=== Test 4: Count at saturation (30 req, 1/hr) ===\n";
$r = $svc->calculateRiskScore($base + ['total_requests' => 30, 'requests_per_hour' => 1]);
$assert('gate is 1.0 at count saturation', $r['volume_gate'] === 1.0, "gate={$r['volume_gate']}");
echo "\n";

// Test 5: Mid both (15 req, 50/hr) — gate 0.5.
echo "=== Test 5: Mid count and rate (15 req, 50/hr) ===\n";
$r = $svc->calculateRiskScore($base + ['total_requests' => 15, 'requests_per_hour' => 50]);
$assert('gate ~0.5', abs($r['volume_gate'] - 0.5) < 0.01, "gate={$r['volume_gate']}");
echo "\n";

// Test 6: A low-count probe collapses while a same-count high-rate burst
// survives — the gate is what separates them (the burst's gate stays 1.0).
echo "=== Test 6: Gate separates probe from burst ===\n";
$probe = $svc->calculateRiskScore($base + ['total_requests' => 6, 'requests_per_hour' => 6]);
$burst = $svc->calculateRiskScore($base + ['total_requests' => 6, 'requests_per_hour' => 1191]);
$assert('probe gate < 1, burst gate == 1', $probe['volume_gate'] < 1.0 && $burst['volume_gate'] === 1.0,
  "probe gate={$probe['volume_gate']}, burst gate={$burst['volume_gate']}");
$assert('burst final score >> probe final score', $burst['score'] > $probe['score'] * 3,
  "burst={$burst['score']}, probe={$probe['score']}");
echo "\n";

echo "=== volume gate tests complete ===\n";
