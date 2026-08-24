#!/usr/bin/env php
<?php

/**
 * Test the search --raw flag.
 *
 * --raw must make individual log entries visible instead of grouped counts.
 * The mechanism: parseDisplayOptions() short-circuits to an empty display when
 * --raw is set, and both renderers route an empty display to their per-entry
 * "raw" path (DisplayService::displayResults -> displayRawJson, and
 * formatResultsForJson). This test asserts:
 *  - --raw yields a display that filters to empty (raw path fires),
 *  - without --raw the search default (host grouping) is applied unchanged,
 *  - the empty display prints each entry as JSON in both JSON and text output.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Commands\SearchCommand;
use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DisplayService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

$failures = 0;
$assert = function(string $name, bool $cond, string $detail = '') use (&$failures): void {
  echo ($cond ? "✅ PASS: " : "❌ FAIL: ") . $name . "\n";
  if ($detail !== '') {
    echo "   $detail\n";
  }
  if (!$cond) {
    $failures++;
  }
};

// A search command that skips the heavy API/DB service setup (not needed to
// exercise option parsing) and exposes the protected parser. Calling the
// Symfony Command constructor directly still triggers configure(), which builds
// the input definition (including --raw and --cols) from real config.
$command = new class extends SearchCommand {
  public function __construct() {
    $this->config = new ConfigurationService();
    Command::__construct();
  }

  public function exposeParseDisplayOptions(InputInterface $input): array {
    return $this->parseDisplayOptions($input);
  }
};

$definition = $command->getDefinition();

// Test 1: --raw marks the display raw (plain, no drupal), routing to per-entry.
echo "=== Test 1: --raw marks the display raw ===\n";
$rawInput = new ArrayInput(['--raw' => TRUE], $definition);
$rawDisplay = $command->exposeParseDisplayOptions($rawInput);
$assert(
  '--raw sets the _raw marker',
  !empty($rawDisplay['_raw']),
  'display=' . json_encode($rawDisplay)
);
$assert(
  '--raw alone is not a drupal stream',
  empty($rawDisplay['drupal']),
  'display=' . json_encode($rawDisplay)
);
echo "\n";

// Test 2: Without --raw, the search default (host grouping) still applies.
echo "=== Test 2: default (no --raw) still groups by host ===\n";
$plainInput = new ArrayInput([], $definition);
$plainDisplay = $command->exposeParseDisplayOptions($plainInput);
$assert(
  'default display groups by host',
  !empty($plainDisplay['host']),
  'display=' . json_encode($plainDisplay)
);
$assert(
  'default display is NOT empty (grouped path)',
  !empty(array_filter($plainDisplay)),
  'display=' . json_encode($plainDisplay)
);
echo "\n";

// Sample entries resembling the target use case: importer timing logs whose
// grouping key (orig_host) is empty, so grouped output would collapse them.
$logs = [
  [
    'time' => 1786000000,
    'orig_host' => '',
    'log_type' => 'mtc_importer',
    'message' => 'Import finished @elapsed=1.2s',
  ],
  [
    'time' => 1786000060,
    'orig_host' => '',
    'log_type' => 'mtc_importer',
    'message' => 'Import finished @elapsed=3.4s',
  ],
];

$display = new DisplayService($command->config ?? new ConfigurationService());

// Test 3: JSON output returns each entry, not a grouped count.
echo "=== Test 3: empty display -> per-entry JSON output ===\n";
$json = $display->formatResultsForJson($logs, $rawDisplay, [], NULL);
$assert(
  'JSON items are the individual entries',
  ($json['items'] ?? NULL) === $logs,
  'items=' . json_encode($json['items'] ?? NULL)
);
$assert(
  'JSON reports no grouping',
  ($json['totals']['groups'] ?? -1) === 0 && empty($json['grouping']),
  'totals=' . json_encode($json['totals'] ?? NULL)
);
echo "\n";

// Test 4: Text output prints each entry as JSON (the displayRawJson path).
echo "=== Test 4: empty display -> per-entry text output ===\n";
$buffer = new BufferedOutput();
$io = new SymfonyStyle(new ArrayInput([]), $buffer);
$display->displayResults($logs, $rawDisplay, $io, FALSE, [], NULL);
$out = $buffer->fetch();
$assert(
  'text output contains the first entry payload',
  strpos($out, '@elapsed=1.2s') !== FALSE,
  'output=' . trim($out)
);
$assert(
  'text output contains the second entry payload',
  strpos($out, '@elapsed=3.4s') !== FALSE
);
echo "\n";

// Test 5: raw output decodes the data payload. Drupal/importer content (the
// message template and its variables, e.g. @elapsed) lives in the data blob,
// not the HTTP columns, so raw output must surface it as nested structure.
echo "=== Test 5: raw output decodes the data blob ===\n";
$payload = ['message' => 'import in @elapsed s', 'variables' => ['@elapsed' => '3.4']];
$withData = [
  [
    'id' => '1',
    'time' => 1786000000,
    'log_type' => 'mtc_importer',
    'data' => json_encode($payload),
  ],
];
$jsonRaw = $display->formatResultsForJson($withData, $rawDisplay, [], NULL);
$assert(
  'JSON raw decodes data to a nested structure',
  is_array($jsonRaw['items'][0]['data'] ?? NULL)
    && (($jsonRaw['items'][0]['data']['variables']['@elapsed'] ?? NULL) === '3.4'),
  'data=' . json_encode($jsonRaw['items'][0]['data'] ?? NULL)
);
$buffer2 = new BufferedOutput();
$io2 = new SymfonyStyle(new ArrayInput([]), $buffer2);
$display->displayResults($withData, $rawDisplay, $io2, FALSE, [], NULL);
$out2 = $buffer2->fetch();
$assert(
  'text raw output surfaces the decoded elapsed value',
  strpos($out2, '"@elapsed": "3.4"') !== FALSE,
  'output=' . trim($out2)
);
echo "\n";

// Test 6: --raw --drupal marks the substituted-message stream.
echo "=== Test 6: --raw --drupal selects the message stream ===\n";
$streamInput = new ArrayInput(['--raw' => TRUE, '--drupal' => TRUE], $definition);
$streamDisplay = $command->exposeParseDisplayOptions($streamInput);
$assert(
  '--raw --drupal is raw AND drupal',
  !empty($streamDisplay['_raw']) && !empty($streamDisplay['drupal']),
  'display=' . json_encode($streamDisplay)
);
echo "\n";

// Test 7: the stream fills the message template from data.variables and carries
// time/id/severity so a specific entry can be looked up.
echo "=== Test 7: stream renders one substituted line per entry ===\n";
$streamLogs = [
  [
    'id' => '42',
    'time' => 1786000000,
    'log_severity' => 'Warning',
    'message' => 'File download failed, preserving existing field value for @count file(s)',
    'data' => json_encode(['variables' => ['@count' => 3]]),
  ],
];
$expected = 'File download failed, preserving existing field value for 3 file(s)';
$buffer3 = new BufferedOutput();
$io3 = new SymfonyStyle(new ArrayInput([]), $buffer3);
$display->displayResults($streamLogs, $streamDisplay, $io3, FALSE, [], NULL);
$out3 = $buffer3->fetch();
$assert(
  'stream line substitutes @count and carries id + severity',
  strpos($out3, $expected) !== FALSE
    && strpos($out3, '42') !== FALSE
    && strpos($out3, '[Warning]') !== FALSE,
  'output=' . trim($out3)
);
$assert(
  'stream prints a drill-down hint carrying the entry id',
  strpos($out3, "--sql-where=\"id='42'\" --raw") !== FALSE,
  'output=' . trim($out3)
);
$jsonStream = $display->formatResultsForJson($streamLogs, $streamDisplay, [], NULL);
$assert(
  'JSON stream attaches the substituted message_rendered',
  ($jsonStream['items'][0]['message_rendered'] ?? NULL) === $expected,
  'message_rendered=' . json_encode($jsonStream['items'][0]['message_rendered'] ?? NULL)
);
echo "\n";

echo "=== search --raw tests complete ===\n";
exit($failures === 0 ? 0 : 1);
