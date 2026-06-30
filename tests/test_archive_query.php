#!/usr/bin/env php
<?php

/**
 * Test script for LogQueryService archive spanning.
 *
 * Verifies that with archive inclusion enabled, queries transparently span
 * the main logs table plus attached archive shards, and that with it off,
 * only the hot tier is returned. Builds its own main DB + shard so it does
 * not depend on the user's live data.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;
use SolarWinds\Services\LogQueryService;

$testDir = '/tmp/solarwinds-archq-' . uniqid();
mkdir($testDir, 0755, TRUE);
$mainPath = "$testDir/main.db";
$shardPath = "$testDir/logs-2026-05.db";

echo "Test dir: $testDir\n\n";

$mockConfig = new class($mainPath) extends ConfigurationService {
  protected string $p;

  /**
   * Capture the main database path.
   */
  public function __construct(string $p) {
    $this->p = $p;
  }

  /**
   * Return the main database path.
   */
  public function getDatabasePath(): string {
    return $this->p;
  }
};

$db = new DatabaseService($mockConfig);
$query = new LogQueryService($db);

$assert = function(string $name, bool $cond, string $detail = ''): void {
  echo ($cond ? "✅ PASS: " : "❌ FAIL: ") . $name . "\n";
  if ($detail !== '') {
    echo "   $detail\n";
  }
};

$mkLog = function(string $id, int $t): array {
  $iso = gmdate('Y-m-d\TH:i:s\Z', $t);
  return [
    'id' => $id,
    'time' => $iso,
    'message' => json_encode([
      'client_ip' => '9.9.9.9',
      'req_uri' => '/x',
      'time' => $iso,
    ]),
  ];
};

// Hot tier: two recent logs.
$hotA = 1748000000; // arbitrary fixed timestamps
$hotB = 1748003600;
$db->insertLogs([
  $mkLog('hot-1', $hotA),
  $mkLog('hot-2', $hotB),
]);

// Build an archive shard with the same schema and three older logs, then
// register it in archive_files.
$archA = 1745000000;
$archB = 1745003600;
$archC = 1745007200;
$shard = new PDO("sqlite:$shardPath");
$shard->exec('CREATE TABLE logs AS SELECT * FROM (SELECT NULL) WHERE 0'); // placeholder, replaced below
// Recreate proper schema by copying from main via ATTACH for an exact match.
$shard = NULL;
$db->exec("ATTACH DATABASE '$shardPath' AS shard");
$db->exec('DROP TABLE IF EXISTS shard.logs');
$db->exec('CREATE TABLE shard.logs AS SELECT * FROM main.logs WHERE 0');
$db->exec('DETACH DATABASE shard');

// Insert the three archived logs directly into the shard.
$sp = new PDO("sqlite:$shardPath");
foreach ([['arch-1', $archA], ['arch-2', $archB], ['arch-3', $archC]] as [$id, $t]) {
  $iso = gmdate('Y-m-d\TH:i:s\Z', $t);
  $sp->prepare('INSERT INTO logs (id, time, data, client_ip) VALUES (?,?,?,?)')
     ->execute([$id, $t, json_encode(['req_uri' => '/x']), '9.9.9.9']);
}
$sp = NULL;

// Register the shard.
$db->prepare(<<<'SQL'
INSERT INTO archive_files (file_path, year_month, start_time, end_time, record_count, size_bytes)
VALUES (:p, '2026-05', :s, :e, 3, 0)
SQL
)->execute([':p' => $shardPath, ':s' => $archA, ':e' => $archC]);

// === Test 1: Without archive inclusion, only hot tier is returned ===
echo "=== Test 1: Archive OFF — hot tier only ===\n";
$query->setIncludeArchive(FALSE);
$rows = $query->getLogs($archA, $hotB);
$assert('returns only the 2 hot logs', count($rows) === 2, 'got ' . count($rows));
echo "\n";

// === Test 2: With archive inclusion, spans hot + shard ===
echo "=== Test 2: Archive ON — spans hot + shard ===\n";
$query->setIncludeArchive(TRUE);
$rows = $query->getLogs($archA, $hotB);
$assert('returns all 5 logs (2 hot + 3 archived)', count($rows) === 5, 'got ' . count($rows));
$ids = array_column($rows, 'id');
$assert('includes an archived id', in_array('arch-1', $ids, TRUE));
$assert('includes a hot id', in_array('hot-1', $ids, TRUE));
echo "\n";

// === Test 3: getLogsWithQuery spans archive and counts correctly ===
echo "=== Test 3: getLogsWithQuery spans archive ===\n";
$query->setIncludeArchive(TRUE);
$rows = $query->getLogsWithQuery(NULL, [], $archA, $hotB);
$assert('getLogsWithQuery returns 5', count($rows) === 5, 'got ' . count($rows));
$timing = $query->getLastQueryTiming();
$assert('row_count timing is 5', ($timing['row_count'] ?? 0) === 5, 'got ' . ($timing['row_count'] ?? 0));
echo "\n";

// === Test 4: getLogsByIp spans archive ===
echo "=== Test 4: getLogsByIp spans archive ===\n";
$query->setIncludeArchive(TRUE);
$rows = $query->getLogsByIp('9.9.9.9', $archA, $hotB);
$assert('getLogsByIp returns 5', count($rows) === 5, 'got ' . count($rows));
echo "\n";

// === Test 5: cleanup detached — no lingering attach after a query ===
echo "=== Test 5: shards detached after query ===\n";
$attached = $db->query("PRAGMA database_list")->fetchAll(PDO::FETCH_ASSOC);
$arcStill = array_filter($attached, fn($d) => str_starts_with($d['name'], 'arc_'));
$assert('no arc_ databases left attached', count($arcStill) === 0, 'got ' . count($arcStill));
echo "\n";

// === Test 6: Seamless — default-on, range-driven, no flag needed ===
echo "=== Test 6: Seamless auto-span by time range ===\n";
$fresh = new LogQueryService($db); // default includeArchive = TRUE
// Narrow range entirely within the hot tier must NOT reach the shard.
$narrow = $fresh->getLogs($hotA, $hotB);
$assert('narrow (recent) range returns hot only', count($narrow) === 2, 'got ' . count($narrow));
$attachedAfterNarrow = $db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
$arcAfterNarrow = array_filter($attachedAfterNarrow, fn($d) => str_starts_with($d['name'], 'arc_'));
$assert('narrow range did not attach any shard', count($arcAfterNarrow) === 0);
// Wide range reaching into archived time auto-spans the shard.
$wide = $fresh->getLogs($archA, $hotB);
$assert('wide range auto-spans archive (5)', count($wide) === 5, 'got ' . count($wide));
echo "\n";

// Cleanup.
echo "=== Cleanup ===\n";
foreach (glob("$testDir/*") as $f) {
  @unlink($f);
}
@rmdir($testDir);
echo "Removed test dir.\n";

echo "\n=== archive query tests complete ===\n";
