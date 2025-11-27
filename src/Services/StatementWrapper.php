<?php

/**
 * @file StatementWrapper.php
 * @brief Fluent wrapper around PDOStatement for method chaining
 */

namespace SolarWinds\Services;

use PDO;
use PDOStatement;

/**
 * Statement Wrapper
 *
 * Provides a fluent interface for database queries while maintaining
 * full PDOStatement compatibility through method delegation.
 *
 * Enables patterns like:
 *   $row = $db->prepare($sql)->execute($params)->fetch();
 *   $rows = $db->query($sql)->fetchAll();
 */
class StatementWrapper
{
  /**
   * The wrapped PDOStatement.
   */
  protected PDOStatement $stmt;

  /**
   * Constructor.
   *
   * @param PDOStatement $stmt The PDOStatement to wrap
   */
  public function __construct(PDOStatement $stmt)
  {
    $this->stmt = $stmt;
  }

  /**
   * Execute the prepared statement with parameters.
   *
   * @param array $params Parameters to bind
   * @return self For method chaining
   */
  public function execute(array $params = []): self
  {
    $this->stmt->execute($params);
    return $this;
  }

  /**
   * Fetch a single row.
   *
   * @param int $mode Fetch mode (default: PDO::FETCH_ASSOC)
   * @return mixed Row data or FALSE
   */
  public function fetch(int $mode = PDO::FETCH_ASSOC): mixed
  {
    return $this->stmt->fetch($mode);
  }

  /**
   * Fetch all rows.
   *
   * @param int $mode Fetch mode (default: PDO::FETCH_ASSOC)
   * @return array Array of rows
   */
  public function fetchAll(int $mode = PDO::FETCH_ASSOC): array
  {
    return $this->stmt->fetchAll($mode);
  }

  /**
   * Fetch a single column from the next row.
   *
   * @param int $column Column number (0-indexed)
   * @return mixed Column value or FALSE
   */
  public function fetchColumn(int $column = 0): mixed
  {
    return $this->stmt->fetchColumn($column);
  }

  /**
   * Delegate all other method calls to the wrapped PDOStatement.
   *
   * This ensures full PDOStatement compatibility for methods not
   * explicitly defined in this wrapper.
   *
   * @param string $method Method name
   * @param array $args Method arguments
   * @return mixed Method return value
   */
  public function __call(string $method, array $args): mixed
  {
    return $this->stmt->$method(...$args);
  }
}
