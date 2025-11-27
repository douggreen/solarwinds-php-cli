#!/usr/bin/env php
<?php

/**
 * Test BlockingService IP allowlist and user-agent classification
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\BlockingService;

echo "=== BlockingService Test ===\n\n";

// Create a test config with allowlist
$testConfig = '/tmp/test-solarwinds-blocking-' . uniqid() . '.yml';
file_put_contents($testConfig, <<<'YAML'
token: "test-token"
api_base_url: "https://api.test.com"

blocking:
  allowlist:
    - "10.0.0.0/8"
    - "192.168.1.0/24"
    - "203.0.113.50"
  trusted_bots:
    - "MyCustomBot"
YAML
);

try {
    $config = new ConfigurationService($testConfig);
    $blocking = new BlockingService($config);

    // Test 1: IP in CIDR range
    echo "Test 1: IP in CIDR Range\n";
    $tests = [
        ['10.0.0.1', TRUE, 'in 10.0.0.0/8'],
        ['10.255.255.255', TRUE, 'in 10.0.0.0/8'],
        ['11.0.0.1', FALSE, 'not in 10.0.0.0/8'],
        ['192.168.1.100', TRUE, 'in 192.168.1.0/24'],
        ['192.168.2.100', FALSE, 'not in 192.168.1.0/24'],
        ['203.0.113.50', TRUE, 'exact match'],
        ['203.0.113.51', FALSE, 'not exact match'],
    ];

    $passed = 0;
    $failed = 0;

    foreach ($tests as [$ip, $expected, $desc]) {
        $result = $blocking->isAllowlisted($ip);
      if ($result === $expected) {
          echo "  ✅ $ip - $desc\n";
          $passed++;
      } else {
          echo "  ❌ $ip - Expected " . ($expected ? 'TRUE' : 'FALSE') . ", got " . ($result ? 'TRUE' : 'FALSE') . "\n";
          $failed++;
      }
    }

    echo "\n";

    // Test 2: User-Agent Classification
    echo "Test 2: User-Agent Classification\n";
    $uaTests = [
        ['Mozilla/5.0 (compatible; Googlebot/2.1)', 'trusted_bot', 'Googlebot'],
        ['Mozilla/5.0 (compatible; bingbot/2.0)', 'trusted_bot', 'Bingbot'],
        ['MyCustomBot/1.0', 'trusted_bot', 'Custom bot'],
        ['python-requests/2.28.1', 'suspicious', 'Python requests'],
        ['curl/7.68.0', 'suspicious', 'Curl'],
        ['', 'suspicious', 'Empty UA'],
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'unknown', 'Normal browser'],
    ];

    foreach ($uaTests as [$ua, $expected, $desc]) {
        $result = $blocking->classifyUserAgent($ua);
      if ($result === $expected) {
          echo "  ✅ $expected - $desc\n";
          $passed++;
      } else {
          echo "  ❌ Expected $expected, got $result - $desc\n";
          $failed++;
      }
    }

    echo "\n";

    // Summary
    echo "=== Test Summary ===\n";
    echo "Passed: $passed\n";
    echo "Failed: $failed\n";

    if ($failed === 0) {
        echo "\n✅ All tests passed!\n";
    } else {
        echo "\n❌ Some tests failed\n";
        exit(1);
    }

    // Cleanup
    unlink($testConfig);

} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    if (file_exists($testConfig)) unlink($testConfig);
    exit(1);
}
