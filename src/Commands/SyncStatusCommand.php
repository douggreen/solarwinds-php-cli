<?php

/**
 * @file SyncStatusCommand.php
 * @brief Reports database health: coverage span, real gaps, and freshness
 *
 * @class SyncStatusCommand
 * @brief Read-only health check wrapping SyncTrackingService
 *
 * Answers "what data do I have and is it healthy" — distinct from the sync
 * engine's "what should I fetch". Pure read-only; modifies no state.
 */

namespace SolarWinds\Commands;

use PDO;
use SolarWinds\Services\ConfigurationService;
use SolarWinds\Services\DatabaseService;
use SolarWinds\Services\SyncTrackingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports local database health: coverage span, genuine gaps, and freshness,
 * without touching the SolarWinds API. Read-only wrapper around
 * SyncTrackingService::getCoverageSummary().
 */
class SyncStatusCommand extends Command
{
  protected ConfigurationService $config;
  protected DatabaseService $database;
  protected SyncTrackingService $syncTracking;

  /**
   * Constructor.
   *
   * Initializes configuration, database, and sync-tracking services.
   * Pure read-only — no API client is created.
   */
  public function __construct()
  {
    $this->config = new ConfigurationService();
    parent::__construct();
    $this->database = new DatabaseService($this->config);
    $this->syncTracking = new SyncTrackingService($this->config, $this->database);
  }

  /**
   * Configure the command name, description, and options.
   */
  protected function configure(): void
  {
    $this
      ->setName('sync:status')
      ->setDescription('Show database health: coverage span, real gaps, and freshness')
      ->setHelp("
        A health check for the local SolarWinds database. Does not touch
        the API. Reports what data you have, whether it is contiguous, and
        how current it is.

        <comment>Examples:</comment>
        <info>solarwinds sync:status</info>             # Default health check (3-line summary)
        <info>solarwinds sync:status --gaps-only</info> # Just the real holes, one per line
        <info>solarwinds sync:status --history</info>   # Add recent sync activity
        <info>solarwinds sync:status --json</info>      # Machine-readable output
      ")
      ->addOption('json', NULL, InputOption::VALUE_NONE, 'Output as JSON')
      ->addOption('gaps-only', NULL, InputOption::VALUE_NONE, 'Print only genuine holes, one per line')
      ->addOption('history', NULL, InputOption::VALUE_NONE, 'Include recent sync activity and the latest sync row');
  }

  /**
   * Collect the health summary and render in the requested format.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $json = (bool) $input->getOption('json');
    $gapsOnly = (bool) $input->getOption('gaps-only');
    $history = (bool) $input->getOption('history');

    // Progress note — the aggregate scan over a large logs table can take a
    // moment, and silence is confusing (the command looks hung).
    if (!$json && !$gapsOnly) {
      $output->writeln('<comment>Analyzing coverage…</comment>');
    }

    $data = $this->collect($history);

    if ($json) {
      $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      return 0;
    }

    $io = new SymfonyStyle($input, $output);
    if ($gapsOnly) {
      $this->renderGapsOnly($io, $data);
    }
    else {
      $this->renderHealth($io, $data, $history);
    }
    return 0;
  }

  /**
   * Gather the health payload from SyncTrackingService and the DB.
   *
   * @param bool $history Whether to include recent sync-activity detail
   * @return array Structured payload, also used by JSON output
   */
  protected function collect(bool $history): array
  {
    $dbPath = $this->config->getDatabasePath();
    $dbSize = is_file($dbPath) ? filesize($dbPath) : 0;
    $retentionSeconds = $this->config->getApiRetentionLimit();
    $now = time();

    $summary = $this->syncTracking->getCoverageSummary();

    $holes = [];
    foreach ($summary['holes'] as $hole) {
      $holes[] = [
        'start_time' => $hole['start'],
        'end_time' => $hole['end'],
        'start_iso' => gmdate('Y-m-d\TH:i:s\Z', $hole['start']),
        'end_iso' => gmdate('Y-m-d\TH:i:s\Z', $hole['end']),
        'duration_seconds' => $hole['seconds'],
        'refillable' => $hole['refillable'],
      ];
    }

    $data = [
      'database' => [
        'path' => $dbPath,
        'size_bytes' => $dbSize,
        'size_human' => $this->humanBytes($dbSize),
        'record_count' => $summary['record_count'],
      ],
      'coverage' => [
        'has_data' => $summary['has_data'],
        'earliest_time' => $summary['earliest'],
        'earliest_iso' => $summary['earliest'] !== NULL ? gmdate('Y-m-d\TH:i:s\Z', $summary['earliest']) : NULL,
        'latest_time' => $summary['latest'],
        'latest_iso' => $summary['latest'] !== NULL ? gmdate('Y-m-d\TH:i:s\Z', $summary['latest']) : NULL,
        'span_seconds' => ($summary['earliest'] !== NULL && $summary['latest'] !== NULL) ? $summary['latest'] - $summary['earliest'] : 0,
        'fresh_seconds' => $summary['fresh_seconds'],
        'contiguous' => count($holes) === 0,
        'holes' => $holes,
        'local_count' => $summary['local']['record_count'] ?? 0,
        'archived_count' => $summary['archived']['record_count'] ?? 0,
        'archived_files' => $summary['archived']['file_count'] ?? 0,
        'archived_earliest_time' => $summary['archived']['earliest'] ?? NULL,
        'archived_latest_time' => $summary['archived']['latest'] ?? NULL,
        'archive_path' => $this->config->getArchiveConfig()['longterm_storage'],
      ],
      'refill' => [
        'retention_days' => (int) ($retentionSeconds / 86400),
        'refetchable_after_time' => $now - $retentionSeconds,
        'refetchable_after_iso' => gmdate('Y-m-d\TH:i:s\Z', $now - $retentionSeconds),
      ],
    ];

    if ($history) {
      $data['history'] = $this->collectHistory();
    }

    return $data;
  }

  /**
   * Gather recent sync-activity detail (only when --history is given).
   *
   * @return array Activity counts and the most recent sync row
   */
  protected function collectHistory(): array
  {
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

    $recent = $this->database->query(<<<'SQL'
SELECT id, status, started_at, completed_at, records_inserted
FROM sync_ranges
ORDER BY started_at DESC
LIMIT 1
SQL
    )->fetch(PDO::FETCH_ASSOC);

    return [
      'activity_last_30_days' => $activity,
      'most_recent_sync' => $recent ?: NULL,
    ];
  }

  /**
   * Render the default health check: a compact summary that stays quiet when
   * healthy and only gets loud when something is actually wrong.
   *
   * @param SymfonyStyle $io Output styler
   * @param array $data Structured payload from collect()
   * @param bool $history Whether to also render the history section
   */
  protected function renderHealth(SymfonyStyle $io, array $data, bool $history): void
  {
    $io->title('SolarWinds Log Database Status');

    $db = $data['database'];
    $cov = $data['coverage'];

    if (!$cov['has_data']) {
      $io->warning('Database is empty — no logs synced yet.');
      return;
    }

    // Status verdict first — the one thing you actually check at a glance.
    $io->writeln('  <info>Status</info>   ' . $this->healthLine($cov));

    // Span duration and the human-readable date range, on separate lines.
    $io->writeln('  <info>Spans</info>    ' . $this->humanDuration($cov['span_seconds']));
    $io->writeln(sprintf(
      '  <info>Range</info>    %s → %s',
      $this->humanDate($cov['earliest_time']),
      $this->humanDate($cov['latest_time'])
    ));

    // Volume. When some data is archived, split the total into local vs
    // archived so it's clear what lives where.
    $recordsLine = number_format($db['record_count']);
    if (($cov['archived_count'] ?? 0) > 0) {
      $recordsLine .= sprintf(
        '  (%s local · %s archived)',
        number_format($cov['local_count']),
        number_format($cov['archived_count'])
      );
    }
    $io->writeln('  <info>Records</info>  ' . $recordsLine);
    $io->writeln('  <info>Size</info>     ' . $db['size_human'] . ' local');

    // When archived, a line showing how many shards, the archived span, and
    // where they live.
    if (($cov['archived_count'] ?? 0) > 0) {
      $io->writeln(sprintf(
        '  <info>Archive</info>  %d shard%s, %s → %s, in %s',
        $cov['archived_files'],
        $cov['archived_files'] === 1 ? '' : 's',
        $this->humanDate($cov['archived_earliest_time']),
        $this->humanDate($cov['archived_latest_time']),
        $cov['archive_path']
      ));
    }

    // Only when there are real holes do we list them and explain the refill
    // window — when data is contiguous, both would just be noise.
    if (!$cov['contiguous']) {
      $io->newLine();
      $rows = [];
      foreach ($cov['holes'] as $hole) {
        $rows[] = [
          $this->humanDate($hole['start_time']),
          $this->humanDate($hole['end_time']),
          $this->humanDuration($hole['duration_seconds']),
          $hole['refillable'] ? 'refillable' : 'PERMANENT',
        ];
      }
      $io->table(
        [
          'Hole start',
          'Hole end',
          'Duration',
          'Recoverable?',
        ],
        $rows
      );

      $r = $data['refill'];
      $io->writeln(sprintf(
        '  <comment>Refill:</comment> API retains %d days. Missing data after %s can be re-fetched; older holes are permanent.',
        $r['retention_days'],
        $this->humanDate($r['refetchable_after_time'])
      ));
    }

    if ($history && isset($data['history'])) {
      $this->renderHistory($io, $data['history']);
    }
  }

  /**
   * Build the one-line health verdict combining contiguity and freshness.
   *
   * @param array $cov The 'coverage' sub-array from collect()
   * @return string A pango-marked-up status string
   */
  protected function healthLine(array $cov): string
  {
    $parts = [];

    if ($cov['contiguous']) {
      $parts[] = '<info>✓ contiguous</info>';
    }
    else {
      $permanent = 0;
      foreach ($cov['holes'] as $h) {
        if (!$h['refillable']) {
          $permanent++;
        }
      }
      $n = count($cov['holes']);
      $tag = $permanent > 0
        ? sprintf('<error>✗ %d hole%s (%d permanent)</error>', $n, $n === 1 ? '' : 's', $permanent)
        : sprintf('<comment>⚠ %d hole%s (refillable)</comment>', $n, $n === 1 ? '' : 's');
      $parts[] = $tag;
    }

    // Freshness: flag as stale if the latest log is older than ~2 hours
    // (the hourly cron should keep it well under that).
    $fresh = $cov['fresh_seconds'];
    if ($fresh === NULL) {
      $parts[] = 'freshness unknown';
    }
    elseif ($fresh > 7200) {
      $parts[] = sprintf('<error>⚠ stale — last log %s ago (cron may have failed)</error>', $this->humanDuration($fresh));
    }
    else {
      $parts[] = sprintf('<info>current</info> (last log %s ago)', $this->humanDuration($fresh));
    }

    return implode(', ', $parts);
  }

  /**
   * Render the optional sync-activity section (only with --history).
   *
   * @param SymfonyStyle $io Output styler
   * @param array $history The 'history' sub-array from collect()
   */
  protected function renderHistory(SymfonyStyle $io, array $history): void
  {
    $act = $history['activity_last_30_days'];
    if (!empty($act)) {
      $io->section('Sync activity — last 30 days');
      $rows = [];
      foreach ($act as $status => $count) {
        $rows[] = [
          $status,
          number_format($count),
        ];
      }
      $io->table(
        [
          'Status',
          'Count',
        ],
        $rows
      );
    }

    if ($history['most_recent_sync']) {
      $r = $history['most_recent_sync'];
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

  /**
   * Render only genuine holes, one per line, for piping to other tools.
   * Each line: ISO start, ISO end, duration, recoverable flag.
   *
   * @param SymfonyStyle $io Output styler
   * @param array $data Structured payload from collect()
   */
  protected function renderGapsOnly(SymfonyStyle $io, array $data): void
  {
    $holes = $data['coverage']['holes'];
    if (empty($holes)) {
      $io->writeln('# no holes — data is contiguous');
      return;
    }
    foreach ($holes as $hole) {
      $io->writeln(sprintf(
        '%s %s %s %s',
        $hole['start_iso'],
        $hole['end_iso'],
        $this->humanDuration($hole['duration_seconds']),
        $hole['refillable'] ? 'refillable' : 'permanent'
      ));
    }
  }

  /**
   * Format a unix timestamp in the project's human date style (local time),
   * e.g. "Jun 12, 7:00pm". Matches the format used by the sync command.
   *
   * @param int $timestamp Unix timestamp
   * @return string Formatted local date/time
   */
  protected function humanDate(int $timestamp): string
  {
    return date('M j, g:ia', $timestamp);
  }

  /**
   * Format a byte count as a human-readable string (e.g., "4.98 GB").
   *
   * @param int $bytes Raw byte count
   * @return string Formatted size with unit suffix
   */
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

  /**
   * Format a duration in seconds as a human-readable string (e.g., "2h 22m",
   * "1d 7h", "12m 17s").
   *
   * @param int $seconds Duration in seconds
   * @return string Formatted duration
   */
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
