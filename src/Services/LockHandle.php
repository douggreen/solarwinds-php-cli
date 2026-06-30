<?php

/**
 * @file LockHandle.php
 * @brief Handle representing a held advisory lock
 *
 * @class LockHandle
 * @brief Releases its flock when explicitly released or when destroyed
 */

namespace SolarWinds\Services;

/**
 * A held advisory lock. The lock is released by release() or automatically
 * when the handle is destroyed (or the process exits, which closes the fd).
 */
class LockHandle
{
  /**
   * Open file pointer holding the flock, or NULL once released.
   *
   * @var resource|null
   */
  protected $handle;

  /**
   * Constructor.
   *
   * @param resource $handle Open file pointer with an active flock
   * @param string $path Lock file path (for reference/debugging)
   */
  public function __construct($handle, protected string $path)
  {
    $this->handle = $handle;
  }

  /**
   * Release the lock. Safe to call more than once.
   */
  public function release(): void
  {
    if (is_resource($this->handle)) {
      flock($this->handle, LOCK_UN);
      fclose($this->handle);
      $this->handle = NULL;
    }
  }

  /**
   * Release on destruction so a forgotten handle still frees the lock.
   */
  public function __destruct()
  {
    $this->release();
  }
}
