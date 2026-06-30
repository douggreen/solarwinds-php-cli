#!/usr/bin/env php
<?php

/**
 * Test script for SyncTrackingService::claimNextChunk()
 *
 * Verifies the per-chunk lazy claim mechanism that lets concurrent sync
 * processes cooperatively drain a single gap range without double-fetching.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;
use SolarWinds\Services\SyncTrackingService;

$testDbPath = '/tmp/solarwinds-test-claim-' . uniqid() . '.db';
echo "Test Database: $testDbPath\n\n";

$mockConfig = new class($testDbPath) extends ConfigurationService {
  protected string $testDbPath;

  /**
   * Capture the test database path.
   */
  public function __construct(string $testDbPath) {
    $this->testDbPath = $testDbPath;
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
};

$resetDb = function() use ($mockConfig, $testDbPath): array {
  @unlink($testDbPath);
  @unlink($testDbPath . '-shm');
  @unlink($testDbPath . '-wal');
  $db = new DatabaseService($mockConfig);
  $tracking = new SyncTrackingService($mockConfig, $db);
  return [$db, $tracking];
};

$assert = function(string $name, bool $cond, string $detail = ''): void {
  if ($cond) {
    echo "✅ PASS: $name\n";
  }
  else {
    echo "❌ FAIL: $name\n";
  }
  if ($detail !== '') {
    echo "   $detail\n";
  }
};

// Default chunk size used in tests below.
$chunk = 3600;

// Test 1: Range smaller than chunk size — single claim covers it all.
echo "=== Test 1: Range smaller than chunk size ===\n";
[$db, $tracking] = $resetDb();
$claim = $tracking->claimNextChunk(1000, 2000, $chunk);
$assert('claim returned', $claim !== NULL);
if ($claim !== NULL) {
  $assert(
    'covers full range when smaller than chunk',
    $claim['start_time'] === 1000 && $claim['end_time'] === 2000,
    "got [{$claim['start_time']}, {$claim['end_time']}]"
  );
}
$next = $tracking->claimNextChunk(1000, 2000, $chunk);
$assert('second call drains to NULL', $next === NULL);
echo "\n";

// Test 2: Empty range — returns NULL immediately.
echo "=== Test 2: Empty range rejected ===\n";
[$db, $tracking] = $resetDb();
$assert('start == end returns NULL', $tracking->claimNextChunk(1000, 1000, $chunk) === NULL);
$assert('start > end returns NULL', $tracking->claimNextChunk(2000, 1000, $chunk) === NULL);
echo "\n";

// Test 3: Range larger than chunk size — claim is clipped to chunkSeconds.
echo "=== Test 3: Range larger than chunk — single chunk-sized claim ===\n";
[$db, $tracking] = $resetDb();
$claim = $tracking->claimNextChunk(1000, 1000 + 5 * $chunk, $chunk);
$assert('claim returned', $claim !== NULL);
if ($claim !== NULL) {
  $assert(
    'clipped to one chunk',
    $claim['start_time'] === 1000 && $claim['end_time'] === 1000 + $chunk,
    "got [{$claim['start_time']}, {$claim['end_time']}]"
  );
}
echo "\n";

// Test 4: Successive calls drain a multi-chunk range one chunk at a time.
echo "=== Test 4: Sequential claims drain a multi-chunk range ===\n";
[$db, $tracking] = $resetDb();
$rangeStart = 1000;
$rangeEnd = 1000 + 4 * $chunk;
$drained = [];
while (($c = $tracking->claimNextChunk($rangeStart, $rangeEnd, $chunk)) !== NULL) {
  $drained[] = [$c['start_time'], $c['end_time']];
}
$expected = [
  [1000, 1000 + $chunk],
  [1000 + $chunk, 1000 + 2 * $chunk],
  [1000 + 2 * $chunk, 1000 + 3 * $chunk],
  [1000 + 3 * $chunk, 1000 + 4 * $chunk],
];
$assert('drained four contiguous chunks', $drained === $expected, 'got ' . json_encode($drained));
echo "\n";

// Test 5: Two interleaved sequences (simulating two cooperating processes).
echo "=== Test 5: Interleaved claims — two processes cooperate ===\n";
[$db, $tracking] = $resetDb();
$rangeStart = 1000;
$rangeEnd = 1000 + 4 * $chunk;
// Process A claims first, then B, then A again, alternating.
$a1 = $tracking->claimNextChunk($rangeStart, $rangeEnd, $chunk);
$b1 = $tracking->claimNextChunk($rangeStart, $rangeEnd, $chunk);
$a2 = $tracking->claimNextChunk($rangeStart, $rangeEnd, $chunk);
$b2 = $tracking->claimNextChunk($rangeStart, $rangeEnd, $chunk);
$noMore = $tracking->claimNextChunk($rangeStart, $rangeEnd, $chunk);

$assert(
  'four distinct chunks claimed',
  $a1 !== NULL && $b1 !== NULL && $a2 !== NULL && $b2 !== NULL,
  'A1, B1, A2, B2 all returned a claim'
);
$assert('fifth call returns NULL', $noMore === NULL);
if ($a1 && $b1) {
  $assert(
    'first two claims are adjacent and non-overlapping',
    $a1['end_time'] === $b1['start_time'],
    "A1 [{$a1['start_time']}, {$a1['end_time']}] vs B1 [{$b1['start_time']}, {$b1['end_time']}]"
  );
}
echo "\n";

// Test 6: Pre-existing in_progress blocker skipped.
echo "=== Test 6: Skips an in_progress blocker in the middle ===\n";
[$db, $tracking] = $resetDb();
$blocker = $tracking->claimNextChunk(1000 + $chunk, 1000 + 2 * $chunk, $chunk);
$assert('blocker established', $blocker !== NULL);
// Now claim against a wider range — should get the piece before the blocker.
$first = $tracking->claimNextChunk(1000, 1000 + 4 * $chunk, $chunk);
$assert(
  'first piece is before the blocker',
  $first !== NULL && $first['start_time'] === 1000 && $first['end_time'] === 1000 + $chunk,
  'got ' . json_encode($first)
);
$second = $tracking->claimNextChunk(1000, 1000 + 4 * $chunk, $chunk);
$assert(
  'next piece is after the blocker',
  $second !== NULL && $second['start_time'] === 1000 + 2 * $chunk && $second['end_time'] === 1000 + 3 * $chunk,
  'got ' . json_encode($second)
);
echo "\n";

// Test 7: Completed coverage blocks re-claim.
echo "=== Test 7: Completed range blocks re-claim ===\n";
[$db, $tracking] = $resetDb();
$first = $tracking->claimNextChunk(1000, 1000 + $chunk, $chunk);
$tracking->updateSyncStatus($first['id'], 'completed');
$retry = $tracking->claimNextChunk(1000, 1000 + $chunk, $chunk);
$assert('re-claim of completed range returns NULL', $retry === NULL);
echo "\n";

// Test 8: Interrupted/failed status does NOT block.
echo "=== Test 8: Interrupted claims unblock the range ===\n";
[$db, $tracking] = $resetDb();
$first = $tracking->claimNextChunk(1000, 1000 + $chunk, $chunk);
$tracking->updateSyncStatus($first['id'], 'interrupted');
$retry = $tracking->claimNextChunk(1000, 1000 + $chunk, $chunk);
$assert(
  're-claim after interrupt succeeds',
  $retry !== NULL && $retry['start_time'] === 1000 && $retry['end_time'] === 1000 + $chunk
);
echo "\n";

// Test 9: Partial overlap with completed — claim resumes from the gap.
echo "=== Test 9: Completed leading portion — claim starts after it ===\n";
[$db, $tracking] = $resetDb();
$first = $tracking->claimNextChunk(1000, 1000 + $chunk, $chunk);
$tracking->updateSyncStatus($first['id'], 'completed');
$next = $tracking->claimNextChunk(1000, 1000 + 3 * $chunk, $chunk);
$assert(
  'next claim begins after the completed window',
  $next !== NULL && $next['start_time'] === 1000 + $chunk && $next['end_time'] === 1000 + 2 * $chunk,
  'got ' . json_encode($next)
);
echo "\n";

// Test 10: Fully blocked — NULL on first call.
echo "=== Test 10: Fully blocked range ===\n";
[$db, $tracking] = $resetDb();
$first = $tracking->claimNextChunk(1000, 1000 + $chunk, $chunk);
$retry = $tracking->claimNextChunk(1000, 1000 + $chunk, $chunk);
$assert('second concurrent caller gets NULL', $retry === NULL);
echo "\n";

echo "=== Cleanup ===\n";
@unlink($testDbPath);
@unlink($testDbPath . '-shm');
@unlink($testDbPath . '-wal');
echo "Removed test database.\n";

echo "\n=== claimNextChunk tests complete ===\n";
