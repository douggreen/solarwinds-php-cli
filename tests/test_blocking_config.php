#!/usr/bin/env php
<?php

/**
 * Test blocking configuration loading
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;

echo "=== Blocking Configuration Test ===\n\n";

// Create a temporary config file
$testConfig = '/tmp/test-solarwinds-' . uniqid() . '.yml';
file_put_contents($testConfig, <<<'YAML'
token: "test-token"
base_url: "https://api.test.com"

blocking:
  allowlist:
    - "10.0.0.0/8"
    - "192.168.1.100"
  trusted_bots:
    - "MyCustomBot"
    - "TestScanner"
  thresholds:
    min_requests: 50
    min_duration_hours: 1
    high_confidence_40x_ratio: 0.9
YAML
);

try {
    $config = new ConfigurationService($testConfig);

    // Test 1: Allowlist
    echo "Test 1: IP Allowlist\n";
    $allowlist = $config->getBlockingAllowlist();
  if (count($allowlist) === 2 && in_array('10.0.0.0/8', $allowlist) && in_array('192.168.1.100', $allowlist)) {
      echo "✅ PASS: Allowlist loaded correctly\n";
  } else {
      echo "❌ FAIL: Allowlist = " . json_encode($allowlist) . "\n";
  }
    echo "\n";

    // Test 2: Trusted Bots (should include defaults + custom)
    echo "Test 2: Trusted Bots\n";
    $bots = $config->getTrustedBots();
    $hasGooglebot = in_array('Googlebot', $bots);
    $hasCustomBot = in_array('MyCustomBot', $bots);
    $hasTestScanner = in_array('TestScanner', $bots);

  if ($hasGooglebot && $hasCustomBot && $hasTestScanner) {
      echo "✅ PASS: Trusted bots include defaults + custom\n";
      echo "   Total bots: " . count($bots) . "\n";
  } else {
      echo "❌ FAIL: Missing bots\n";
      echo "   Googlebot: " . ($hasGooglebot ? 'yes' : 'no') . "\n";
      echo "   MyCustomBot: " . ($hasCustomBot ? 'yes' : 'no') . "\n";
      echo "   TestScanner: " . ($hasTestScanner ? 'yes' : 'no') . "\n";
  }
    echo "\n";

    // Test 3: Thresholds (custom values)
    echo "Test 3: Custom Thresholds\n";
    $thresholds = $config->getBlockingThresholds();
  if ($thresholds['min_requests'] === 50 &&
        $thresholds['min_duration_hours'] === 1 &&
        $thresholds['high_confidence_40x_ratio'] === 0.9) {
      echo "✅ PASS: Custom thresholds loaded correctly\n";
  } else {
      echo "❌ FAIL: Thresholds = " . json_encode($thresholds) . "\n";
  }
    echo "\n";

    // Test 4: Default thresholds (no config)
    echo "Test 4: Default Thresholds (empty config)\n";
    $emptyConfig = '/tmp/test-solarwinds-empty-' . uniqid() . '.yml';
    file_put_contents($emptyConfig, <<<'YAML'
token: "test-token"
base_url: "https://api.test.com"
YAML
    );

    $config2 = new ConfigurationService($emptyConfig);
    $defaults = $config2->getBlockingThresholds();

  if ($defaults['min_requests'] === 100 &&
        $defaults['min_duration_hours'] === 2 &&
        $defaults['high_confidence_40x_ratio'] === 0.8) {
      echo "✅ PASS: Default thresholds applied\n";
  } else {
      echo "❌ FAIL: Defaults = " . json_encode($defaults) . "\n";
  }
    echo "\n";

    // Test 5: Empty allowlist
    echo "Test 5: Empty Allowlist (no config)\n";
    $emptyAllowlist = $config2->getBlockingAllowlist();
  if (empty($emptyAllowlist)) {
      echo "✅ PASS: Empty allowlist returns empty array\n";
  } else {
      echo "❌ FAIL: Expected empty array, got " . json_encode($emptyAllowlist) . "\n";
  }
    echo "\n";

    // Test 6: Default bots only
    echo "Test 6: Default Bots Only (no custom)\n";
    $defaultBots = $config2->getTrustedBots();
  if (in_array('Googlebot', $defaultBots) && in_array('bingbot', $defaultBots)) {
      echo "✅ PASS: Default bots loaded\n";
      echo "   Default bot count: " . count($defaultBots) . "\n";
  } else {
      echo "❌ FAIL: Default bots missing\n";
  }
    echo "\n";

    // Cleanup
    unlink($testConfig);
    unlink($emptyConfig);

    echo "=== All Tests Complete ===\n";

} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    if (file_exists($testConfig)) unlink($testConfig);
    exit(1);
}
