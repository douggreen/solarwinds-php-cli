<?php

/**
 * @file SyncStatusCommand.php
 * @brief Reports database coverage, sync history, and detected gaps
 *
 * @class SyncStatusCommand
 * @brief Read-only coverage report wrapping SyncTrackingService
 *
 * Provides a friendly view of what's in the local SQLite database, what
 * sync ranges have been recorded, and what gaps remain. Pure read-only —
 * does not modify any state.
 */

namespace SolarWinds\Commands;

use PDO;
use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;
use SolarWinds\Services\SyncTrackingService;
use SolarWinds\Services\TimeSpecifications;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncStatusCommand extends Command
{
  /**
   * Time-shortcut flags exposed as command options (e.g. --1d, --1w).
   * Matched against TimeSpecifications::convertToTimeRange() at runtime.
   */
  protected const TIME_SHORTCUTS = [
    '15m',
    '1h',
    '6h',
    '1d',
    '2d',
    '7d',
    '1w',
    '2w',
    '14d',
    '1M',
  ];

  protected ConfigurationService $config;
  protected DatabaseService $database;
  protected SyncTrackingService $syncTracking;

  public function __construct()
  {
    $this->config = new ConfigurationService();
    parent::__construct();
    $this->database = new DatabaseService($this->config);
    $this->syncTracking = new SyncTrackingService($this->config, $this->database);
  }

  protected function configure(): void
  {
    $this
      ->setName('sync:status')
      ->setDescription('Show database coverage, sync history, and detected gaps')
      ->setHelp("
        Reports what's in the local SolarWinds SQLite database without
        fetching anything from the API.

        <comment>Examples:</comment>
        <info>solarwinds sync:status</info>            # Default: coverage over API retention window
        <info>solarwinds sync:status --1d</info>       # Coverage over the last day
        <info>solarwinds sync:status --1w</info>       # Coverage over the last week
        <info>solarwinds sync:status --full-history</info>  # Coverage over all stored data
        <info>solarwinds sync:status --gaps-only</info>     # Print just the gap list
        <info>solarwinds sync:status --json</info>          # Machine-readable output
      ")
      ->addOption('json', NULL, InputOption::VALUE_NONE, 'Output as JSON')
      ->addOption('gaps-only', NULL, InputOption::VALUE_NONE, 'Print only the gap list')
      ->addOption('full-history', NULL, InputOption::VALUE_NONE, 'Cover all stored data, not just API retention')
      ->addOption('time', 't', InputOption::VALUE_REQUIRED, 'Time window (e.g., 1d, 1w, 2w)');

    // Allow common --Nd / --Nh / --Nw shortcuts as flags too, matching the
    // rest of the project's commands.
    foreach (static::TIME_SHORTCUTS as $shortcut) {
      $this->addOption($shortcut, NULL, InputOption::VALUE_NONE, "Time shortcut: --$shortcut");
    }
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $json = (bool) $input->getOption('json');
    $gapsOnly = (bool) $input->getOption('gaps-only');
    $fullHistory = (bool) $input->getOption('full-history');

    // Resolve coverage window. Priority: explicit --time, shortcut flag,
    // --full-history, default (API retention window).
    [$windowStart, $windowEnd, $windowLabel] = $this->resolveWindow($input, $fullHistory);

    $data = $this->collect($windowStart, $windowEnd, $windowLabel, $fullHistory);

    if ($json) {
      $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      return 0;
    }

    $io = new SymfonyStyle($input, $output);
    if ($gapsOnly) {
      $this->renderGapsOnly($io, $data);
    }
    else {
      $this->renderFull($io, $data);
    }
    return 0;
  }

  /**
   * Resolve the coverage window from CLI options.
   *
   * @return array{0: int, 1: int, 2: string} [start, end, human label]
   */
  protected function resolveWindow(InputInterface $input, bool $fullHistory): array
  {
    $now = time();

    if ($fullHistory) {
      $earliestLog = $this->syncTracking->getEarliestLogDate();
      return [
        $earliestLog > 0 ? $earliestLog : $now,
        $now,
        'full history',
      ];
    }

    $timeArg = $input->getOption('time');
    if ($timeArg === NULL) {
      // Look for shortcut flag.
      foreach (static::TIME_SHORTCUTS as $shortcut) {
        if ($input->getOption($shortcut)) {
          $timeArg = $shortcut;
          break;
        }
      }
    }

    if ($timeArg !== NULL) {
      $range = TimeSpecifications::convertToTimeRange($timeArg);
      if ($range !== NULL) {
        // convertToTimeRange returns string time expressions (e.g., "24 hours
        // ago", "now") in some cases and unix timestamps in others; normalize
        // to int.
        return [
          is_int($range[0]) ? $range[0] : (int) strtotime($range[0]),
          is_int($range[1]) ? $range[1] : (int) strtotime($range[1]),
          "last $timeArg",
        ];
      }
    }

    // Default: API retention window.
    $retention = $this->config->getApiRetentionLimit();
    return [
      $now - $retention,
      $now,
      sprintf('last %d days (API retention)', (int) ($retention / 86400)),
    ];
  }

  /**
   * Gather all status data from SyncTrackingService and the DB.
   *
   * @return array Structured payload, also used by JSON output
   */
  protected function collect(int $windowStart, int $windowEnd, string $windowLabel, bool $fullHistory): array
  {
    $dbPath = $this->config->getDatabasePath();
    $dbSize = is_file($dbPath) ? filesize($dbPath) : 0;

    // Cheap aggregate query.
    $logs = $this->database->query(
      'SELECT MIN(time) AS earliest, MAX(time) AS latest, COUNT(*) AS count FROM logs'
    )->fetch(PDO::FETCH_ASSOC);

    // Gap detection (read-only) over the requested window.
    $rangeAnalysis = $this->syncTracking->detectMissingRanges($windowStart, $windowEnd);
    $gaps = [];
    foreach ($rangeAnalysis['ranges'] ?? [] as $gap) {
      $gaps[] = [
        'start_time' => (int) $gap['start_time'],
        'end_time' => (int) $gap['end_time'],
        'start_iso' => gmdate('Y-m-d\TH:i:s\Z', (int) $gap['start_time']),
        'end_iso' => gmdate('Y-m-d\TH:i:s\Z', (int) $gap['end_time']),
        'duration_seconds' => (int) $gap['end_time'] - (int) $gap['start_time'],
        'reason' => $gap['reason'],
      ];
    }

    // Sync activity summary — last 30 days by status.
    $activity = [];
    $stmt = $this->database->query(<<<'SQL'
SELECT status, COUNT(*) AS n
FROM sync_ranges
WHERE started_at > datetime('now', '-30 days')
GROUP BY status
SQL
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $activity[$row['status']] = (int) $row['n'];
    }

    // Most recent sync row.
    $recent = $this->database->query(<<<'SQL'
SELECT id, status, started_at, completed_at, records_inserted,
       CAST(start_time AS INTEGER) AS start_time,
       CAST(end_time AS INTEGER) AS end_time
FROM sync_ranges
ORDER BY started_at DESC
LIMIT 1
SQL
    )->fetch(PDO::FETCH_ASSOC);

    return [
      'database' => [
        'path' => $dbPath,
        'size_bytes' => $dbSize,
        'size_human' => $this->humanBytes($dbSize),
        'earliest_log_time' => $logs['earliest'] !== NULL ? (int) $logs['earliest'] : NULL,
        'earliest_log_iso' => $logs['earliest'] !== NULL ? gmdate('Y-m-d\TH:i:s\Z', (int) $logs['earliest']) : NULL,
        'latest_log_time' => $logs['latest'] !== NULL ? (int) $logs['latest'] : NULL,
        'latest_log_iso' => $logs['latest'] !== NULL ? gmdate('Y-m-d\TH:i:s\Z', (int) $logs['latest']) : NULL,
        'total_records' => (int) $logs['count'],
      ],
      'api_retention' => [
        'days' => (int) ($this->config->getApiRetentionLimit() / 86400),
        'earliest_available_time' => time() - $this->config->getApiRetentionLimit(),
        'earliest_available_iso' => gmdate('Y-m-d\TH:i:s\Z', time() - $this->config->getApiRetentionLimit()),
      ],
      'coverage' => [
        'window_label' => $windowLabel,
        'window_start_time' => $windowStart,
        'window_start_iso' => gmdate('Y-m-d\TH:i:s\Z', $windowStart),
        'window_end_time' => $windowEnd,
        'window_end_iso' => gmdate('Y-m-d\TH:i:s\Z', $windowEnd),
        'gap_count' => count($gaps),
        'gaps' => $gaps,
        'full_history' => $fullHistory,
      ],
      'activity_last_30_days' => $activity,
      'most_recent_sync' => $recent ?: NULL,
    ];
  }

  protected function renderFull(SymfonyStyle $io, array $data): void
  {
    $io->title('SolarWinds Log Database Status');

    $db = $data['database'];
    $io->section('Database');
    $io->definitionList(
      ['Path' => $db['path']],
      ['Size' => $db['size_human']],
      ['Earliest log' => $db['earliest_log_iso'] ?? '(empty)'],
      ['Latest log' => $db['latest_log_iso'] ?? '(empty)'],
      ['Total records' => number_format($db['total_records'])],
    );

    $ret = $data['api_retention'];
    $io->section('API retention');
    $io->definitionList(
      ['Retention window' => $ret['days'] . ' days'],
      ['Earliest available from API' => $ret['earliest_available_iso']],
    );

    $cov = $data['coverage'];
    $io->section('Coverage — ' . $cov['window_label']);
    $io->text(sprintf('Window: %s → %s', $cov['window_start_iso'], $cov['window_end_iso']));
    if ($cov['gap_count'] === 0) {
      $io->success('No gaps detected — full coverage');
    }
    else {
      $rows = [];
      foreach ($cov['gaps'] as $gap) {
        $rows[] = [
          $gap['start_iso'],
          $gap['end_iso'],
          $this->humanDuration($gap['duration_seconds']),
          $gap['reason'],
        ];
      }
      $io->table(
        [
          'Start',
          'End',
          'Duration',
          'Reason',
        ],
        $rows
      );
    }

    $act = $data['activity_last_30_days'];
    if (!empty($act)) {
      $io->section('Sync activity — last 30 days');
      $rows = [];
      foreach ($act as $status => $count) {
        $rows[] = [$status, number_format($count)];
      }
      $io->table(['Status', 'Count'], $rows);
    }

    if ($data['most_recent_sync']) {
      $r = $data['most_recent_sync'];
      $io->section('Most recent sync');
      $io->definitionList(
        ['ID' => $r['id']],
        ['Status' => $r['status']],
        ['Started' => $r['started_at']],
        ['Completed' => $r['completed_at'] ?? '(in progress)'],
        ['Records inserted' => number_format((int) ($r['records_inserted'] ?? 0))],
      );
    }
  }

  protected function renderGapsOnly(SymfonyStyle $io, array $data): void
  {
    $cov = $data['coverage'];
    if ($cov['gap_count'] === 0) {
      $io->writeln('# no gaps in ' . $cov['window_label']);
      return;
    }
    foreach ($cov['gaps'] as $gap) {
      $io->writeln(sprintf(
        '%s %s %s %s',
        $gap['start_iso'],
        $gap['end_iso'],
        $this->humanDuration($gap['duration_seconds']),
        $gap['reason']
      ));
    }
  }

  protected function humanBytes(int $bytes): string
  {
    $units = [
      'B',
      'KB',
      'MB',
      'GB',
      'TB',
    ];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
      $size /= 1024;
      $i++;
    }
    return sprintf('%.2f %s', $size, $units[$i]);
  }

  protected function humanDuration(int $seconds): string
  {
    if ($seconds < 60) {
      return $seconds . 's';
    }
    if ($seconds < 3600) {
      return sprintf('%dm %ds', intdiv($seconds, 60), $seconds % 60);
    }
    if ($seconds < 86400) {
      return sprintf('%dh %dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    return sprintf('%dd %dh', $days, $hours);
  }
}
