<?php

/**
 * @file LockService.php
 * @brief Cross-process advisory locking for coordinating DB-heavy operations
 *
 * @class LockService
 * @brief flock()-based reader/writer locks held on a local sentinel file
 *
 * Coordinates operations that must not overlap on the SQLite database — most
 * importantly the archive command's VACUUM (which takes an exclusive SQLite
 * lock) against sync runs. Uses a local lock file (NOT on a network mount, so
 * flock semantics are reliable).
 *
 * Reader/writer model:
 * - Syncs acquire a SHARED lock — many run at once (they already coordinate
 *   at the row level via SyncTrackingService::claimNextChunk).
 * - Archive acquires an EXCLUSIVE lock — it waits for in-flight syncs and
 *   blocks new ones for the brief window it needs.
 */

namespace SolarWinds\Services;

/**
 * Advisory lock service backed by flock() on a local sentinel file.
 */
class LockService
{
  /**
   * Directory holding lock sentinel files.
   *
   * @var string
   */
  protected string $lockDir;

  /**
   * Constructor.
   *
   * @param ConfigurationService $config Configuration service (for the DB dir)
   */
  public function __construct(protected ConfigurationService $config)
  {
    // Keep locks next to the database, on local disk — flock over a network
    // filesystem is unreliable, so this must not be the archive storage path.
    $this->lockDir = dirname($this->config->getDatabasePath()) . '/locks';
    if (!is_dir($this->lockDir)) {
      mkdir($this->lockDir, 0755, TRUE);
    }
  }

  /**
   * Acquire a lock, waiting up to $timeoutSeconds for it.
   *
   * @param string $name Lock name (sentinel file basename)
   * @param bool $exclusive TRUE for a writer (exclusive) lock, FALSE for a
   *                        shared (reader) lock
   * @param int $timeoutSeconds How long to wait before giving up
   * @return LockHandle|null A handle while held, or NULL if not acquired in time
   */
  public function acquire(string $name, bool $exclusive, int $timeoutSeconds = 120): ?LockHandle
  {
    $path = $this->lockDir . '/' . $name . '.lock';
    $fp = fopen($path, 'c');
    if ($fp === FALSE) {
      return NULL;
    }

    $operation = ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB;
    // microtime() for a sub-second-accurate deadline; time() would round to
    // whole seconds and make short timeouts wildly imprecise.
    $deadline = microtime(TRUE) + $timeoutSeconds;

    while (TRUE) {
      if (flock($fp, $operation)) {
        return new LockHandle($fp, $path);
      }
      if (microtime(TRUE) >= $deadline) {
        fclose($fp);
        return NULL;
      }
      // Poll rather than block so the timeout is honored.
      usleep(50000);
    }
  }
}
