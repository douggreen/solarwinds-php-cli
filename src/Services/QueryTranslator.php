<?php

/**
 * @file QueryTranslator.php
 * @brief Translates SolarWinds query syntax to SQL WHERE clauses
 *
 * @class QueryTranslator
 * @brief Converts SolarWinds API query syntax to SQLite WHERE clause syntax
 *
 * Handles translation of the legacy SolarWinds query format to SQL WHERE clauses
 * for efficient database queries. Supports field matching, text search, exclusions,
 * and boolean logic.
 */

namespace SolarWinds\Services;

/**
 * Query Translator - Converts SolarWinds syntax to SQL
 *
 * Translates SolarWinds API query patterns into SQLite-compatible WHERE clauses.
 */
class QueryTranslator
{
  /**
   * Translate SolarWinds query to SQL WHERE clause.
   *
   * Converts patterns like:
   * - { json.field:value } => field = 'value' or field LIKE '%value%'
   * - "text" => data LIKE '%text%'
   * - -pattern => NOT (condition)
   * - OR => SQL OR
   * - AND => SQL AND (implicit)
   *
   * @param string $solarWindsQuery Original SolarWinds query string
   * @return array Array with 'where' (SQL WHERE clause) and 'params' (PDO parameters)
   */
  public function translate(string $solarWindsQuery): array
  {
    if (empty(trim($solarWindsQuery))) {
      return ['where' => '1=1', 'params' => []];
    }

    $conditions = $params = [];
    $paramCounter = 0;

    // Split query by OR (case-sensitive for now)
    $orParts = $this->splitByOr($solarWindsQuery);

    foreach ($orParts as $orPart) {
      $andConditions = [];
      $orPart = trim($orPart);

      // Parse individual conditions within this OR branch
      $tokens = $this->tokenize($orPart);

      foreach ($tokens as $token) {
        $isNegated = FALSE;

        // Check for negation prefix
        if (str_starts_with($token, '-')) {
          $isNegated = TRUE;
          $token = substr($token, 1);
        }

        // Parse the token
        $condition = $this->parseToken($token, $params, $paramCounter);

        if ($condition) {
          if ($isNegated) {
            $condition = "NOT ({$condition})";
          }
          $andConditions[] = $condition;
        }
      }

      if (!empty($andConditions)) {
        if (count($andConditions) === 1) {
          $conditions[] = $andConditions[0];
        }
        else {
          $conditions[] = '(' . implode(' AND ', $andConditions) . ')';
        }
      }
    }

    if (empty($conditions)) {
      return ['where' => '1=1', 'params' => []];
    }

    $whereClause = count($conditions) === 1 ? $conditions[0] : '(' . implode(' OR ', $conditions) . ')';

    return [
      'where' => $whereClause,
      'params' => $params,
    ];
  }

  /**
   * Split query by OR operators.
   *
   * Handles OR logic while respecting quoted strings and braces.
   *
   * @param string $query Query string
   * @return array Array of OR-separated parts
   */
  protected function splitByOr(string $query): array
  {
    // Simple implementation: split by " OR " (case-insensitive)
    // TODO: Handle OR within quotes/braces properly
    $parts = preg_split('/\s+OR\s+/i', $query);
    return array_map('trim', $parts);
  }

  /**
   * Tokenize a query part into individual conditions.
   *
   * Extracts:
   * - { json.field:value } patterns
   * - Quoted strings
   * - Unquoted terms
   *
   * @param string $queryPart Query part to tokenize
   * @return array Array of tokens
   */
  protected function tokenize(string $queryPart): array
  {
    $tokens = [];
    $remaining = $queryPart;

    while (!empty(trim($remaining))) {
      $remaining = ltrim($remaining);

      // Match { json.field:value } pattern
      if (preg_match('/^(\{[^}]+\})/', $remaining, $matches)) {
        $tokens[] = $matches[1];
        $remaining = substr($remaining, strlen($matches[1]));
        continue;
      }

      // Match quoted string "text" or 'text'
      if (preg_match('/^(["\'])([^"\']*)\1/', $remaining, $matches)) {
        $tokens[] = $matches[0];
        $remaining = substr($remaining, strlen($matches[0]));
        continue;
      }

      // Match parentheses group
      if (preg_match('/^(\([^)]+\))/', $remaining, $matches)) {
        $tokens[] = $matches[1];
        $remaining = substr($remaining, strlen($matches[1]));
        continue;
      }

      // Match unquoted term (until space or special char)
      if (preg_match('/^([^\s{}()]+)/', $remaining, $matches)) {
        $tokens[] = $matches[1];
        $remaining = substr($remaining, strlen($matches[1]));
        continue;
      }

      // Skip unexpected characters
      $remaining = substr($remaining, 1);
    }

    return $tokens;
  }

  /**
   * Parse a single token into SQL condition.
   *
   * @param string $token Token to parse
   * @param array $params PDO parameters array (passed by reference)
   * @param int $paramCounter Parameter counter (passed by reference)
   * @return string|null SQL condition or NULL if token is invalid
   */
  protected function parseToken(string $token, array &$params, int &$paramCounter): ?string
  {
    $token = trim($token);

    // Parse { json.field:value } pattern
    if (preg_match('/^\{\s*json\.([^:}]+):([^}]+)\s*\}$/', $token, $matches)) {
      $field = trim($matches[1]);
      $value = trim($matches[2]);

      return $this->buildFieldCondition($field, $value, $params, $paramCounter);
    }

    // Parse parenthesized group ( ... )
    if (preg_match('/^\((.+)\)$/', $token, $matches)) {
      $innerQuery = $matches[1];
      $result = $this->translate($innerQuery);
      // Merge params
      $params = array_merge($params, $result['params']);
      return '(' . $result['where'] . ')';
    }

    // Parse quoted string - full-text search
    if (preg_match('/^["\'](.+)["\']$/', $token, $matches)) {
      $searchText = $matches[1];
      $paramName = ':search_' . $paramCounter++;
      $params[$paramName] = '%' . $searchText . '%';
      return "data LIKE {$paramName}";
    }

    // Parse unquoted path pattern (starts with /)
    if (str_starts_with($token, '/')) {
      $paramName = ':path_' . $paramCounter++;
      $params[$paramName] = '%' . $token . '%';
      return "req_uri LIKE {$paramName}";
    }

    // Parse unquoted text - treat as full-text search
    if (!empty($token)) {
      $paramName = ':text_' . $paramCounter++;
      $params[$paramName] = '%' . $token . '%';
      return "data LIKE {$paramName}";
    }

    return NULL;
  }

  /**
   * Build SQL condition for a field match.
   *
   * @param string $field Field name (without json. prefix)
   * @param string $value Field value or pattern
   * @param array $params PDO parameters array
   * @param int $paramCounter Parameter counter
   * @return string SQL condition
   */
  protected function buildFieldCondition(string $field, string $value, array &$params, int &$paramCounter): string
  {
    // Check for status code range patterns (e.g., -30, -40 means NOT 3xx, NOT 4xx)
    if (preg_match('/^-(\d)(\d)?$/', $value, $matches)) {
      $firstDigit = $matches[1];
      $secondDigit = $matches[2] ?? '0';
      $rangeStart = $firstDigit . $secondDigit;
      $rangeEnd = $firstDigit . '9';

      if ($field === 'resp_status') {
        return "resp_status NOT BETWEEN {$rangeStart} AND {$rangeEnd}";
      }
    }

    // Check for wildcard patterns
    if (str_contains($value, '*') || str_contains($value, '?')) {
      $sqlPattern = str_replace(['*', '?'], ['%', '_'], $value);
      $paramName = ':field_' . $paramCounter++;
      $params[$paramName] = $sqlPattern;
      return "{$field} LIKE {$paramName}";
    }

    // Exact match
    $paramName = ':field_' . $paramCounter++;
    $params[$paramName] = $value;
    return "{$field} = {$paramName}";
  }
}
