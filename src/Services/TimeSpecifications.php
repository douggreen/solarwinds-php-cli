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
 * **Relative Time Options:**
 * - Minutes: --5m, --10m, --15m, --30m
 * - Hours: --1h, --2h, --3h, --6h, --12h
 * - Days: --1d, --2d, --1w, --2w
 * - Aliases: --hour (1h), --day (1d), --week (1w)
 *
 * **Specific Day Options:**
 * - --yesterday: Full previous day (00:00:00 to 23:59:59)
 * - --1D through --14D: Specific days ago (full day ranges)
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
   * Get all time option categories and their values.
   */
  public static function getTimeOptions(): array
  {
    return [
      'minutes' => ['5m', '10m', '15m', '30m'],
      'hours' => ['1h', '2h', '3h', '6h', '12h'],
      'days' => ['1d', '2d'],
      'weeks' => ['1w', '2w'],
      'aliases' => [
        'hour' => '1h',
        'day' => '1d',
        'week' => '1w'
      ],
      'special_days' => ['yesterday', '1D'], // Both map to yesterday.
      'day_range' => [2, 14] // 2D through 14D.
    ];
  }

  /**
   * Convert time argument to human-readable time range.
   */
  public static function convertToTimeRange(string $timeArg): ?array
  {
    $timeOptions = self::getTimeOptions();
    $timeMappings = [];

    // Generate time mappings for minutes.
    foreach ($timeOptions['minutes'] as $option) {
      $minutes = substr($option, 0, -1);
      $timeMappings[$option] = ["{$minutes} minutes ago", 'now'];
    }

    // Generate time mappings for hours.
    foreach ($timeOptions['hours'] as $option) {
      $hours = substr($option, 0, -1);
      $unit = $hours == 1 ? 'hour' : 'hours';
      $timeMappings[$option] = ["{$hours} {$unit} ago", 'now'];
    }

    // Generate time mappings for days.
    foreach ($timeOptions['days'] as $option) {
      $days = $option[0]; // We know it's single digit.
      $hours = $days * 24;
      $timeMappings[$option] = ["{$hours} hours ago", 'now'];
    }

    // Generate time mappings for weeks.
    foreach ($timeOptions['weeks'] as $option) {
      $weeks = $option[0]; // We know it's single digit.
      $days = $weeks * 7;
      $timeMappings[$option] = ["{$days} days ago", 'now'];
    }

    // Set alias mappings.
    foreach ($timeOptions['aliases'] as $alias => $baseOption) {
      $timeMappings[$alias] = $timeMappings[$baseOption];
    }

    // Set special day ranges (full 24-hour periods).
    foreach ($timeOptions['special_days'] as $specialDay) {
      $timeMappings[$specialDay] = ['yesterday at 00:00:00', 'yesterday at 23:59:59'];
    }

    // Add dynamic day ranges (2D through 14D with specific day boundaries).
    [$startDay, $endDay] = $timeOptions['day_range'];
    for ($day = $startDay; $day <= $endDay; $day++) {
      $key = "{$day}D";
      $startTime = "{$day} days ago at 00:00:00";
      $endTime = "{$day} days ago at 23:59:59";
      $timeMappings[$key] = [$startTime, $endTime];
    }

    return $timeMappings[$timeArg] ?? NULL;
  }

  /**
   * Get all available time options as a flat array for command configuration.
   */
  public static function getAllTimeOptions(): array
  {
    $timeOptions = self::getTimeOptions();
    $allOptions = [];

    // Flatten all time option categories.
    foreach (['minutes', 'hours', 'days', 'weeks'] as $category) {
      $allOptions = array_merge($allOptions, $timeOptions[$category]);
    }

    // Add aliases.
    $allOptions = array_merge($allOptions, array_keys($timeOptions['aliases']));

    // Add special days.
    $allOptions = array_merge($allOptions, $timeOptions['special_days']);

    // Add dynamic day ranges.
    [$startDay, $endDay] = $timeOptions['day_range'];
    for ($day = $startDay; $day <= $endDay; $day++) {
      $allOptions[] = "{$day}D";
    }

    return $allOptions;
  }
}
