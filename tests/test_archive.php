#!/usr/bin/env php
<?php

/**
 * Test script for ArchiveCommand
 *
 * Verifies the archive pipeline end-to-end:
 *  1. Logs spanning multiple months are spread to monthly shard files
 *  2. Main DB has the archived rows removed
 *  3. archive_files registry has correct entries
 *  4. Shards are readable and contain the right data
 *  5. Re-running archive is idempotent (INSERT OR IGNORE handles duplicates)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;
use SolarWinds\Commands\ArchiveCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

$testDir = '/tmp/solarwinds-archive-test-' . uniqid();
mkdir($testDir, 0755, TRUE);
$testDbPath = "$testDir/main.db";
$storageDir = "$testDir/storage";
mkdir($storageDir, 0755, TRUE);

echo "Test dir: $testDir\n\n";

// Mock config with archive enabled and pointing at our temp storage.
$mockConfig = new class($testDbPath, $storageDir) extends ConfigurationService {
  protected string $testDbPath;
  protected string $storageDir;

  /**
   * Capture the test database path and archive storage directory.
   */
  public function __construct(string $testDbPath, string $storageDir) {
    $this->testDbPath = $testDbPath;
    $this->storageDir = $storageDir;
  }

  /**
   * Return the test database path.
   */
  public function getDatabasePath(): string {
    return $this->testDbPath;
  }

  /**
   * Return a fixed retention window for testing.
   */
  public function getApiRetentionLimit(): int {
    return 14 * 86400;
  }

  /**
   * Shard period for this mock (set by the test).
   *
   * @var string
   */
  public string $shardPeriod = 'monthly';

  /**
   * Return archive config enabled and pointing at the test storage dir.
   */
  public function getArchiveConfig(): array {
    return [
      'enabled' => TRUE,
      'local_keep_days' => 60,
      'longterm_storage' => $this->storageDir,
      'shard_period' => $this->shardPeriod,
      'include_in_queries' => FALSE,
      'fail_on_unavailable' => TRUE,
    ];
  }
};

$db = new DatabaseService($mockConfig);

$assert = function(string $name, bool $cond, string $detail = ''): void {
  echo ($cond ? "✅ PASS: " : "❌ FAIL: ") . $name . "\n";
  if ($detail !== '') {
    echo "   $detail\n";
  }
};

// Insert ~9 test logs spanning 3 distinct months that are all OLDER than the
// 60-day cutoff. We use times anchored 120-200 days ago so they fall safely
// inside "should be archived" territory.
$now = time();
$daysAgo = function(int $d) use ($now): int { return $now - $d * 86400; };

$logs = [];
$buckets = [
  ['ts' => $daysAgo(200), 'label' => 'oldest-month'],
  ['ts' => $daysAgo(199), 'label' => 'oldest-month'],
  ['ts' => $daysAgo(198), 'label' => 'oldest-month'],
  ['ts' => $daysAgo(150), 'label' => 'middle-month'],
  ['ts' => $daysAgo(149), 'label' => 'middle-month'],
  ['ts' => $daysAgo(148), 'label' => 'middle-month'],
  ['ts' => $daysAgo(100), 'label' => 'recent-month'],
  ['ts' => $daysAgo(99),  'label' => 'recent-month'],
  ['ts' => $daysAgo(98),  'label' => 'recent-month'],
  // One log INSIDE the keep window — should NOT be archived.
  ['ts' => $daysAgo(30),  'label' => 'hot'],
];

foreach ($logs as $_) { }
foreach ($buckets as $i => $b) {
  $iso = gmdate('Y-m-d\TH:i:s\Z', $b['ts']);
  $logs[] = [
    'id' => "log-$i-{$b['ts']}",
    'time' => $iso,
    'message' => json_encode([
      'client_ip' => '1.2.3.4',
      'req_uri' => '/test',
      'time' => $iso,
    ]),
  ];
}
$result = $db->insertLogs($logs);
echo "Inserted {$result['inserted']} logs\n\n";

// === Test 1: Dry run reports work without touching anything ===
echo "=== Test 1: Dry run ===\n";
$cmd = new class($mockConfig, $db) extends ArchiveCommand {
  /**
   * Inject the mocked config and database into the command under test.
   */
  public function __construct(ConfigurationService $config, DatabaseService $database) {
    parent::__construct();
    $this->config = $config;
    $this->database = $database;
  }
};
$app = new Application();
$app->add($cmd);
$tester = new CommandTester($cmd);
$exit = $tester->execute(['--dry-run' => TRUE]);
$assert('dry-run exits 0', $exit === 0);
$output = $tester->getDisplay();
$assert('dry-run mentions Dry run yes', strpos($output, 'Dry run') !== FALSE);
$mainCount = (int) $db->query('SELECT COUNT(*) FROM logs')->fetchColumn();
$assert('dry-run leaves all 10 logs in main', $mainCount === 10, "got $mainCount");
echo "\n";

// === Test 2: Real archive moves the old logs and leaves the hot one ===
echo "=== Test 2: Real archive ===\n";
$tester2 = new CommandTester($cmd);
$exit2 = $tester2->execute([]);
$assert('archive exits 0', $exit2 === 0);
$mainCount = (int) $db->query('SELECT COUNT(*) FROM logs')->fetchColumn();
$assert('main DB now has only the 1 hot log', $mainCount === 1, "got $mainCount");

// Calculate expected shard count from the test data — depends on calendar
// month boundaries, not on how many "buckets" the test conceptually has.
$expectedMonths = [];
foreach ($buckets as $b) {
  if ($b['label'] !== 'hot') {
    $expectedMonths[gmdate('Y-m', $b['ts'])] = TRUE;
  }
}
$expectedShardCount = count($expectedMonths);

$shardFiles = glob("$storageDir/logs-*.db");
$assert("shard files created (expected $expectedShardCount)", count($shardFiles) === $expectedShardCount,
  'got ' . count($shardFiles) . ': ' . implode(', ', array_map('basename', $shardFiles)));

$registryRows = $db->query('SELECT file_path, record_count FROM archive_files ORDER BY year_month')->fetchAll(PDO::FETCH_ASSOC);
$assert("archive_files has $expectedShardCount entries", count($registryRows) === $expectedShardCount);
$totalArchived = array_sum(array_column($registryRows, 'record_count'));
$assert('archive_files total = 9 rows moved', $totalArchived === 9, "got $totalArchived");
echo "\n";

// === Test 3: Each shard contains its month's logs and is independently readable ===
echo "=== Test 3: Shard contents ===\n";
$shardTotal = 0;
foreach ($shardFiles as $sf) {
  $pdo = new PDO("sqlite:$sf");
  $count = (int) $pdo->query('SELECT COUNT(*) FROM logs')->fetchColumn();
  $shardTotal += $count;
}
$assert('shards together contain 9 logs', $shardTotal === 9, "got $shardTotal");
echo "\n";

// === Test 4: Re-running archive is idempotent (no errors, no double-move) ===
echo "=== Test 4: Idempotency ===\n";
$tester3 = new CommandTester($cmd);
$exit3 = $tester3->execute([]);
$assert('second archive exits 0', $exit3 === 0);
$mainCount = (int) $db->query('SELECT COUNT(*) FROM logs')->fetchColumn();
$assert('main DB still has just 1 hot log after re-run', $mainCount === 1, "got $mainCount");
$shardFiles2 = glob("$storageDir/logs-*.db");
$assert("still $expectedShardCount shard files (no new ones)", count($shardFiles2) === $expectedShardCount);
echo "\n";

// === Test 5: Weekly shard period names shards logs-YYYY-Www.db ===
echo "=== Test 5: Weekly shard period ===\n";
$weekDir = '/tmp/solarwinds-archive-test-wk-' . uniqid();
mkdir($weekDir, 0755, TRUE);
$weekStorage = "$weekDir/storage";
mkdir($weekStorage, 0755, TRUE);
$weekDbPath = "$weekDir/main.db";
$weekConfig = clone $mockConfig;
// Point the clone at a fresh DB + storage and switch to weekly.
(function() use ($weekConfig, $weekDbPath, $weekStorage) {
  $r = new ReflectionObject($weekConfig);
  $r->getProperty('testDbPath')->setValue($weekConfig, $weekDbPath);
  $r->getProperty('storageDir')->setValue($weekConfig, $weekStorage);
})();
$weekConfig->shardPeriod = 'weekly';
$weekDb = new DatabaseService($weekConfig);
// Insert logs in two distinct, fully-aged ISO weeks (~120 and ~127 days ago).
$weekLogs = [];
foreach ([120, 127] as $i => $d) {
  $ts = $now - $d * 86400;
  $iso = gmdate('Y-m-d\TH:i:s\Z', $ts);
  $weekLogs[] = [
    'id' => "wk-$i",
    'time' => $iso,
    'message' => json_encode(['client_ip' => '1.2.3.4', 'req_uri' => '/x', 'time' => $iso]),
  ];
}
$weekDb->insertLogs($weekLogs);
$weekCmd = new class($weekConfig, $weekDb) extends ArchiveCommand {
  /**
   * Inject the mocked config and database into the command under test.
   */
  public function __construct(ConfigurationService $config, DatabaseService $database) {
    parent::__construct();
    $this->config = $config;
    $this->database = $database;
  }
};
(new CommandTester($weekCmd))->execute([]);
$weekShards = glob("$weekStorage/logs-*-W*.db");
$assert('weekly shards named logs-YYYY-Www.db', count($weekShards) === 2,
  'got ' . count($weekShards) . ': ' . implode(', ', array_map('basename', $weekShards)));
$weekMain = (int) $weekDb->query('SELECT COUNT(*) FROM logs')->fetchColumn();
$assert('weekly archive emptied main', $weekMain === 0, "got $weekMain");
echo "\n";

// === Cleanup ===
echo "=== Cleanup ===\n";
foreach (array_merge(glob("$testDir/*"), glob("$testDir/archive-tmp/*")) as $f) {
  if (is_file($f)) @unlink($f);
}
foreach (glob("$storageDir/*") as $f) {
  if (is_file($f)) @unlink($f);
}
@rmdir("$testDir/archive-tmp");
@rmdir($storageDir);
@rmdir($testDir);
foreach (array_merge(glob("$weekDir/*"), glob("$weekDir/archive-tmp/*"), glob("$weekStorage/*")) as $f) {
  if (is_file($f)) @unlink($f);
}
@rmdir("$weekDir/archive-tmp");
@rmdir($weekStorage);
@rmdir($weekDir);
echo "Removed test dirs.\n";

echo "\n=== archive tests complete ===\n";
