#!/usr/bin/env php
<?php

/**
 * Test script for LockService reader/writer semantics.
 *
 * Verifies:
 *  - Multiple shared (reader) locks coexist.
 *  - An exclusive (writer) lock is blocked while a shared lock is held.
 *  - A shared lock is blocked while an exclusive lock is held.
 *  - Releasing a handle frees the lock.
 *  - acquire() times out and returns NULL when it cannot get the lock.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\LockService;

$testDir = '/tmp/solarwinds-lock-test-' . uniqid();
mkdir($testDir, 0755, TRUE);

$mockConfig = new class($testDir) extends ConfigurationService {
  protected string $dir;

  /**
   * Capture the test directory used to derive the lock dir.
   */
  public function __construct(string $dir) {
    $this->dir = $dir;
  }

  /**
   * Return a fake database path; LockService derives locks/ alongside it.
   */
  public function getDatabasePath(): string {
    return $this->dir . '/logs.db';
  }
};

$lock = new LockService($mockConfig);

$assert = function(string $name, bool $cond, string $detail = ''): void {
  echo ($cond ? "✅ PASS: " : "❌ FAIL: ") . $name . "\n";
  if ($detail !== '') {
    echo "   $detail\n";
  }
};

// Test 1: Two shared locks coexist.
echo "=== Test 1: Shared locks coexist ===\n";
$a = $lock->acquire('m', FALSE, 2);
$b = $lock->acquire('m', FALSE, 2);
$assert('first shared acquired', $a !== NULL);
$assert('second shared acquired (coexists)', $b !== NULL);
echo "\n";

// Test 2: Exclusive blocked while shared held (times out).
echo "=== Test 2: Exclusive blocked by shared ===\n";
$start = microtime(TRUE);
$x = $lock->acquire('m', TRUE, 1);
$waited = microtime(TRUE) - $start;
$assert('exclusive NOT acquired while shared held', $x === NULL);
$assert('waited ~1s for the timeout', $waited >= 0.9 && $waited <= 2.0, sprintf('%.2fs', $waited));
echo "\n";

// Test 3: After releasing shared locks, exclusive can be acquired.
echo "=== Test 3: Exclusive after shared released ===\n";
$a->release();
$b->release();
$x = $lock->acquire('m', TRUE, 2);
$assert('exclusive acquired once shared released', $x !== NULL);
echo "\n";

// Test 4: Shared blocked while exclusive held.
echo "=== Test 4: Shared blocked by exclusive ===\n";
$s = $lock->acquire('m', FALSE, 1);
$assert('shared NOT acquired while exclusive held', $s === NULL);
echo "\n";

// Test 5: Releasing exclusive frees the lock.
echo "=== Test 5: Shared after exclusive released ===\n";
$x->release();
$s = $lock->acquire('m', FALSE, 2);
$assert('shared acquired once exclusive released', $s !== NULL);
$s->release();
echo "\n";

// Test 6: Cross-process — child holds exclusive, parent's shared times out.
echo "=== Test 6: Cross-process exclusive blocks shared ===\n";
$holder = __DIR__ . '/_lock_holder.php';
file_put_contents($holder, <<<PHP
<?php
require_once __DIR__ . '/../vendor/autoload.php';
use SolarWinds\\Services\\ConfigurationService;
use SolarWinds\\Services\\LockService;
\$cfg = new class extends ConfigurationService {
  public function __construct() {}
  public function getDatabasePath(): string { return '$testDir/logs.db'; }
};
\$l = new LockService(\$cfg);
\$h = \$l->acquire('m', TRUE, 2);
echo "held\\n";
sleep(2);
PHP
);
$proc = proc_open("php $holder", [1 => ['pipe', 'w']], $pipes);
// Wait for the child to report it holds the exclusive lock.
$line = fgets($pipes[1]);
$childHolds = trim((string) $line) === 'held';
$assert('child acquired exclusive', $childHolds);
$start = microtime(TRUE);
$blocked = $lock->acquire('m', FALSE, 1);
$waited = microtime(TRUE) - $start;
$assert('parent shared blocked while child holds exclusive', $blocked === NULL, sprintf('waited %.2fs', $waited));
proc_close($proc);
@unlink($holder);
echo "\n";

// Cleanup.
echo "=== Cleanup ===\n";
foreach (glob("$testDir/locks/*") as $f) {
  @unlink($f);
}
@rmdir("$testDir/locks");
@rmdir($testDir);
echo "Removed test dir.\n";

echo "\n=== lock service tests complete ===\n";
