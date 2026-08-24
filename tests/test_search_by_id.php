#!/usr/bin/env php
<?php

/**
 * Test the search --by-id lookup mode (backing the `id` alias).
 *
 * --by-id reinterprets the positional argument as an exact log id and builds an
 * "id = :id" primary-key lookup instead of a text search. This test asserts:
 *  - --by-id + an argument builds the exact-id query,
 *  - --by-id without an argument is a clear error,
 *  - without --by-id the same argument still does a normal text search.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Commands\SearchCommand;
use SolarWinds\Services\ConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;

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

$command = new class extends SearchCommand {
  public function __construct() {
    $this->config = new ConfigurationService();
    Command::__construct();
  }

  public function exposeParse(InputInterface $input): array {
    return $this->parseScriptSpecificOptions($input);
  }

  public function exposeBuild(array $scriptSpecific): array {
    // buildSearchQuery reads script_specific and filters; supply empty filters.
    return $this->buildSearchQuery([
      'script_specific' => $scriptSpecific,
      'filters' => [
        'country_filter' => NULL,
        'city_filter' => NULL,
        'status_code_filter' => NULL,
        'user_agent_filter' => NULL,
        'path_filter' => NULL,
        'ip_filter' => NULL,
      ],
    ]);
  }
};

$definition = $command->getDefinition();

// Test 1: --by-id + argument builds an exact primary-key lookup.
echo "=== Test 1: --by-id builds an id = :id query ===\n";
$input = new ArrayInput(['search_term' => '2028542974996185104', '--by-id' => TRUE], $definition);
$parsed = $command->exposeParse($input);
$assert(
  '--by-id parses the argument as an id',
  ($parsed['query_type'] ?? NULL) === 'id' && ($parsed['id'] ?? NULL) === '2028542974996185104',
  'parsed=' . json_encode($parsed)
);
$sql = $command->exposeBuild($parsed);
$assert(
  'query is id = :id with the argument bound',
  ($sql['where'] ?? NULL) === 'id = :id' && ($sql['params'][':id'] ?? NULL) === '2028542974996185104',
  'sql=' . json_encode($sql)
);
echo "\n";

// Test 2: --by-id with no argument is a clear error, not a full scan.
echo "=== Test 2: --by-id without an id errors ===\n";
$emptyInput = new ArrayInput(['--by-id' => TRUE], $definition);
$threw = FALSE;
try {
  $command->exposeBuild($command->exposeParse($emptyInput));
}
catch (\InvalidArgumentException $e) {
  $threw = TRUE;
}
$assert('missing id throws InvalidArgumentException', $threw);
echo "\n";

// Test 3: without --by-id the argument is still a normal text search.
echo "=== Test 3: no --by-id keeps text search behavior ===\n";
$textInput = new ArrayInput(['search_term' => 'error'], $definition);
$textParsed = $command->exposeParse($textInput);
$textSql = $command->exposeBuild($textParsed);
$assert(
  'plain argument builds a text (data LIKE) search',
  ($textParsed['query_type'] ?? NULL) === 'text' && ($textSql['where'] ?? NULL) === 'data LIKE :search',
  'parsed=' . json_encode($textParsed) . ' sql=' . json_encode($textSql)
);
echo "\n";

echo "=== search --by-id tests complete ===\n";
exit($failures === 0 ? 0 : 1);
