#!/usr/bin/env php
<?php

/**
 * Demonstration of Bot IP Verification
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\BlockingService;
use SolarWinds\Services\BotIpService;
use SolarWinds\Services\DatabaseService;

echo "=== Bot IP Verification Demo ===\n\n";
echo "This demonstrates how the system detects bot spoofing.\n\n";

// Create test config
$testConfig = '/tmp/test-bot-verification-' . uniqid() . '.yml';
file_put_contents($testConfig, <<<'YAML'
token: "test-token"
api_base_url: "https://api.test.com"

blocking:
  trusted_bots:
    - "Googlebot"
    - "bingbot"
YAML
);

$config = new ConfigurationService($testConfig);
$database = new DatabaseService($config);
$botIpService = new BotIpService($database);
$blockingService = new BlockingService($config, $botIpService);

// Update Googlebot ranges from GitHub
echo "Updating Googlebot IP ranges from GitHub...\n";
$result = $botIpService->updateBotRanges('googlebot');
echo "  " . ($result ? "✅ Updated successfully" : "❌ Update failed") . "\n\n";

// Test scenarios
$scenarios = [
    [
        'name' => 'Legitimate Googlebot (verified IP)',
        'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'ip' => '66.249.64.1',  // Known Googlebot IP range
        'expected' => 'trusted_bot'
    ],
    [
        'name' => 'Fake Googlebot (spoofed UA with random IP)',
        'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'ip' => '1.2.3.4',  // Not a Google IP
        'expected' => 'bot_spoofing'
    ],
    [
        'name' => 'Regular browser (no bot claim)',
        'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'ip' => '1.2.3.4',
        'expected' => 'unknown'
    ],
    [
        'name' => 'Suspicious tool (curl)',
        'ua' => 'curl/7.68.0',
        'ip' => '1.2.3.4',
        'expected' => 'suspicious'
    ],
];

echo "Testing Bot IP Verification:\n";
echo str_repeat("-", 80) . "\n\n";

foreach ($scenarios as $scenario) {
    echo "Scenario: {$scenario['name']}\n";
    echo "  User-Agent: {$scenario['ua']}\n";
    echo "  IP Address: {$scenario['ip']}\n";

    $result = $blockingService->classifyUserAgent($scenario['ua'], $scenario['ip']);

    echo "  Result: $result\n";

    $status = ($result === $scenario['expected']) ? "✅ PASS" : "❌ FAIL (expected: {$scenario['expected']})";
    echo "  $status\n\n";
}

// Show statistics
echo str_repeat("-", 80) . "\n";
echo "Bot IP Range Statistics:\n\n";

$stats = $botIpService->getBotStatistics();
foreach ($stats as $stat) {
  if ($stat['bot_name'] === 'googlebot') {
      echo "  Bot: {$stat['bot_name']}\n";
      echo "  IP Ranges: {$stat['range_count']}\n";
      echo "  Last Updated: {$stat['last_updated']}\n";
      echo "  Enabled: " . ($stat['enabled'] ? 'Yes' : 'No') . "\n";
  }
}

// Cleanup
unlink($testConfig);

echo "\n=== Demo Complete ===\n";
