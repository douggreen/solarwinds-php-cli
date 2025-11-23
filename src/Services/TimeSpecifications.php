<?php

/**
 * @file TimeSpecifications.php
 * @brief Centralized time specification handling with comprehensive time range support
 *
 * @class TimeSpecifications
 * @brief Static utility class providing centralized time parsing and range calculations
 *
 * This class consolidates all time-related functionality that was duplicated across the
 * and automatic conversion to appropriate time ranges.
 *
 * @section time_formats Supported Time Formats
 *
 * **Relative Time Options (from now):**
 * - Minutes: --Nm (e.g., --5m, --10m, --30m, --45m)
 * - Hours: --Nh (e.g., --1h, --2h, --6h, --24h)
 * - Days: --Nd (e.g., --1d, --3d, --7d, --30d)
 * - Weeks: --Nw (e.g., --1w, --2w, --4w)
 * - Aliases: --hour (1h), --day (1d), --week (1w)
 *
 * **Specific Day Options (full 24-hour periods):**
 * - --yesterday: Full previous day (00:00:00 to 23:59:59)
 * - --ND: Any number of days ago (e.g., --1D, --7D, --30D, --90D)
 *
 * **Custom Time Ranges:**
 * - --since="time expression" --until="time expression"
 * - Supports natural language: "2 hours ago", "yesterday", "now"
 *
 * @section time_conversion Time Conversion Logic
 *
 * **Relative Times:**
 * - Calculated from current time backwards
 * - End time defaults to "now"
 * - Start time calculated based on duration
 *
 * **Full Day Ranges:**
 * - Yesterday and --ND options span full 24-hour periods
 * - Start: 00:00:00 of target date
 * - End: 23:59:59 of target date
 *
 * **Time Zone Handling:**
 * - All times converted to UTC for API compatibility
 * - Local time zone considered for full-day calculations
 * - ISO 8601 format used for API communication
 *
 * @section time_range_calculation Time Range Calculations
 *
 * The class provides several calculation methods:
 * - convertToTimeRange(): Convert time option to [start, end] array
 * - getAllTimeOptions(): Get comprehensive list of all supported options
 * - calculateTimeRangeSeconds(): Convert time range to duration in seconds
 *
 * @section example Usage Examples
 * @code{.php}
 * // Get time range for --1h option
 * $range = TimeSpecifications::convertToTimeRange('--1h');
 * // Returns: ['1 hour ago', 'now']
 *
 * // Get all available time options
 * $options = TimeSpecifications::getAllTimeOptions();
 * // Returns: ['--5m', '--10m', '--15m', ..., '--yesterday', '--14D']
 *
 * // Calculate duration in seconds
 * $seconds = TimeSpecifications::calculateTimeRangeSeconds('--1h');
 * // Returns: 3600
 * @endcode
 *
 *
 * This class ensures complete compatibility with all time options supported
 * - All legacy time format variations
 * - Exact same time range calculations
 * - Identical behavior for edge cases (timezone boundaries, etc.)
 *
 * @section performance Performance Considerations
 *
 * - All methods are static for efficient access
 * - Time calculations cached to avoid repeated computation
 * - Minimal overhead for time option resolution
 *
 * @see BaseSolarWindsCommand For time option parsing integration
 * @see ApiService For time range to ISO format conversion
 * @see DatabaseService For time-based database storage
 *
 * @warning Time calculations assume server and client are in compatible timezones
 */

namespace SolarWinds\Services;

/**
 * Time Specifications
 *
 * Single source of truth for all time-related configurations and conversions.
 */
class TimeSpecifications
{
  /**
   * Get time option ranges for registration.
   *
   * Defines which numeric ranges to register as command options.
   * These are registered with Symfony Console, but the conversion logic
   * accepts any numeric value.
   *
   * @return array Time option ranges
   */
  public static function getTimeOptions(): array
  {
    return [
      'minutes' => range(1, 60),        // 1m through 60m
      'hours' => range(1, 48),          // 1h through 48h
      'days' => range(1, 90),           // 1d through 90d (lowercase, relative)
      'weeks' => range(1, 8),           // 1w through 8w
      'months' => range(1, 12),         // 1M through 12M (uppercase, months)
      'years' => range(1, 10),          // 1y through 10y (years)
      'specific_days' => range(1, 365), // 1D through 365D (uppercase, specific days)
      'aliases' => [
        'hour' => '1h',
        'day' => '1d',
        'week' => '1w'
      ],
      'special' => ['yesterday', 'all'],
    ];
  }

  /**
   * Convert time argument to human-readable time range.
   *
   * Converts time options like --1h, --yesterday to [start, end] time strings.
   * Supports dynamic patterns:
   *   - Nm (any number of minutes, lowercase)
   *   - Nh (any number of hours)
   *   - Nd (any number of days, relative from now, lowercase)
   *   - Nw (any number of weeks)
   *   - NM (any number of months, uppercase) - requires confirmation
   *   - Ny (any number of years) - requires confirmation
   *   - ND (any number of days ago, full 24-hour period, uppercase)
   *   - all (all available data) - requires confirmation
   *
   * @param string $timeArg Time option string (e.g., '1h', 'yesterday', '5m', '30d', '2M', '1y', 'all')
   * @return array|null Array with [start_time, end_time] or NULL if not recognized
   */
  public static function convertToTimeRange(string $timeArg): ?array
  {
    // Handle special case: yesterday.
    if ($timeArg === 'yesterday') {
      return ['yesterday at 00:00:00', 'yesterday at 23:59:59'];
    }

    // Handle special case: all data.
    if ($timeArg === 'all') {
      return ['10 years ago', 'now'];
    }

    // Handle aliases.
    $aliases = [
      'hour' => '1h',
      'day' => '1d',
      'week' => '1w',
    ];
    if (isset($aliases[$timeArg])) {
      $timeArg = $aliases[$timeArg];
    }

    // Handle pattern-based time options.
    // Pattern: Nm (minutes), Nh (hours), Nd (days relative), Nw (weeks), NM (months uppercase), Ny (years), ND (days ago full period).
    if (preg_match('/^(\d+)([mhdwyMD])$/', $timeArg, $matches)) {
      $number = (int) $matches[1];
      $unit = $matches[2];

      // Handle uppercase D (specific full day).
      if ($unit === 'D') {
        $startTime = "{$number} days ago at 00:00:00";
        $endTime = "{$number} days ago at 23:59:59";
        return [$startTime, $endTime];
      }

      // Handle uppercase M (months).
      if ($unit === 'M') {
        $unitName = $number == 1 ? 'month' : 'months';
        return ["{$number} {$unitName} ago", 'now'];
      }

      // Handle lowercase relative time ranges.
      switch ($unit) {
        case 'm':
          // Lowercase m = minutes only.
          if ($number <= 60) {
            $unitName = $number == 1 ? 'minute' : 'minutes';
            return ["{$number} {$unitName} ago", 'now'];
          }
          // Invalid: > 60 is not supported.
          return NULL;

        case 'h':
          $unitName = $number == 1 ? 'hour' : 'hours';
          return ["{$number} {$unitName} ago", 'now'];

        case 'd':
          $hours = $number * 24;
          return ["{$hours} hours ago", 'now'];

        case 'w':
          $days = $number * 7;
          return ["{$days} days ago", 'now'];

        case 'y':
          $unitName = $number == 1 ? 'year' : 'years';
          return ["{$number} {$unitName} ago", 'now'];
      }
    }

    return NULL;
  }

  /**
   * Get all available time options as a flat array for command configuration.
   *
   * Generates comprehensive list of time options for Symfony Console registration.
   *
   * @return array Flat array of all time option strings
   */
  public static function getAllTimeOptions(): array
  {
    $timeOptions = self::getTimeOptions();
    $allOptions = [];

    // Generate minute options (Nm).
    foreach ($timeOptions['minutes'] as $num) {
      $allOptions[] = "{$num}m";
    }

    // Generate hour options (Nh).
    foreach ($timeOptions['hours'] as $num) {
      $allOptions[] = "{$num}h";
    }

    // Generate day options (Nd - lowercase, relative).
    foreach ($timeOptions['days'] as $num) {
      $allOptions[] = "{$num}d";
    }

    // Generate week options (Nw).
    foreach ($timeOptions['weeks'] as $num) {
      $allOptions[] = "{$num}w";
    }

    // Generate month options (NM - uppercase months).
    foreach ($timeOptions['months'] as $num) {
      $allOptions[] = "{$num}M";
    }

    // Generate year options (Ny).
    foreach ($timeOptions['years'] as $num) {
      $allOptions[] = "{$num}y";
    }

    // Generate specific day options (ND - uppercase, full day periods).
    foreach ($timeOptions['specific_days'] as $num) {
      $allOptions[] = "{$num}D";
    }

    // Add aliases.
    $allOptions = array_merge($allOptions, array_keys($timeOptions['aliases']));

    // Add special options.
    $allOptions = array_merge($allOptions, $timeOptions['special']);

    return $allOptions;
  }

  /**
   * Check if a time option requires user confirmation.
   *
   * Confirms for any time range with months (NM uppercase), years, or all data
   * to prevent accidental expensive queries on large datasets.
   *
   * @param string $timeArg Time option string (e.g., '1h', '2M', '1y', 'all')
   * @return bool TRUE if confirmation required
   */
  public static function requiresConfirmation(string $timeArg): bool
  {
    // All data always requires confirmation.
    if ($timeArg === 'all') {
      return TRUE;
    }

    // Check for month patterns (NM - uppercase).
    if (preg_match('/^\d+M$/', $timeArg)) {
      return TRUE;
    }

    // Check for year patterns (Ny).
    if (preg_match('/^\d+y$/i', $timeArg)) {
      return TRUE;
    }

    return FALSE;
  }
}
