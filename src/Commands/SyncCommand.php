<?php

/**
 * @file SyncCommand.php
 * @brief Sync logs to database without analysis
 *
 * @class SyncCommand
 * @brief Syncs logs from SolarWinds API to SQLite database for specified time range
 */

namespace SolarWinds\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sync Command - Downloads and stores logs without analysis.
 *
 * Useful for:
 * - Pre-populating database for faster queries
 * - Testing gap detection system
 * - Background data collection
 */
class SyncCommand extends BaseSolarWindsCommand
{
  protected string $defaultTime = '1h';

  protected function configure(): void
  {
    $this
      ->setName('sync')
      ->setDescription('Sync logs to database without analysis')
      ->setHelp("
        The <info>sync</info> command downloads logs from SolarWinds API and stores them
        in the local SQLite database without performing any analysis.

        This is useful for:
        - Pre-populating the database with historical data
        - Testing the gap detection system
        - Background data collection for later analysis

        <comment>Examples:</comment>
        <info>solarwinds sync --1d</info>        # Sync last 24 hours
        <info>solarwinds sync --1w</info>        # Sync last week
        <info>solarwinds sync --all</info>       # Sync all available data
        <info>solarwinds sync --2w</info>        # Sync last 2 weeks

        The command will automatically detect and fill gaps in the database.
" . self::getTimeRangeHelp() . "
      ")
    ;

    // Call parent configure to add common options.
    parent::configure();

    // Add sync-specific option.
    $this->addOption(
      'force',
      'f',
      InputOption::VALUE_NONE,
      'Force re-sync even if data already exists'
    );
  }

  /**
   * Build search query (not used for sync, but required by parent).
   */
  protected function buildSearchQuery(array $options): array
  {
    // No query needed - we sync all logs
    return ['where' => '', 'params' => []];
  }

  /**
   * Parse script-specific options.
   */
  protected function parseScriptSpecificOptions(InputInterface $input): array
  {
    return [
      'force' => $input->getOption('force'),
    ];
  }

  /**
   * Validate query (not used for sync).
   */
  protected function validateQuery(array $sqlQuery, array $options): void
  {
    // No validation needed for sync
  }

  /**
   * Execute the sync command.
   *
   * Override to only perform sync without analysis.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $this->io = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);
    $this->jsonMode = $input->getOption('json');

    try {
      // Parse and validate arguments.
      $options = $this->parseArguments($input);

      // Display sync information.
      $this->io->title('SolarWinds Log Sync');
      $this->io->text("Time range: {$options['time']['human_readable']}");
      $this->io->text("Database: " . $this->config->getDatabasePath());
      $this->io->newLine();

      // Register signal handlers for graceful interruption.
      $this->registerSignalHandlers();

      // Check coverage BEFORE sync.
      $this->io->writeln('<comment>Checking current database coverage...</comment>');
      $startTime = gmdate('Y-m-d\TH:i:s\Z', strtotime($options['time']['start_time']));
      $endTime = gmdate('Y-m-d\TH:i:s\Z', strtotime($options['time']['end_time']));
      $beforeSync = $this->databaseService->detectMissingRanges($startTime, $endTime);

      // Sync logs to database (this will fill gaps).
      $this->syncLogsToDatabase($options);

      // Check coverage AFTER sync.
      $afterSync = $this->databaseService->detectMissingRanges($startTime, $endTime);

      // Show completion message.
      $this->io->success('Sync completed');

      // Show comprehensive coverage report.
      $this->displayCoverageReport($options, $beforeSync, $afterSync);

      return 0;
    }
    catch (\Exception $e) {
      $this->io->error($e->getMessage());
      return 1;
    }
  }

  /**
   * Display comprehensive coverage report showing what was synced and what remains.
   */
  protected function displayCoverageReport(array $options, array $beforeSync, array $afterSync): void
  {
    $startTime = $options['time']['start_time'];
    $endTime = $options['time']['end_time'];

    // Get record count for the synced range.
    $count = $this->databaseService->getRecordCount($startTime, $endTime);

    $this->io->section('Coverage Report');
    $this->io->text([
      sprintf('Time range: %s to %s',
        date('M j, g:ia', strtotime($startTime)),
        date('M j, g:ia', strtotime($endTime))
      ),
      sprintf('Total records: %s', number_format($count)),
    ]);
    $this->io->newLine();

    // Show what was filled during this sync.
    $gapsBefore = $beforeSync['ranges'] ?? [];
    $gapsAfter = $afterSync['ranges'] ?? [];

    if (empty($gapsBefore)) {
      $this->io->text('✓ Database already had complete coverage');
      return;
    }

    // Calculate what was filled.
    $filled = $remaining = [];

    foreach ($gapsBefore as $before) {
      $wasExcluded = $before['reason'] === 'beyond_retention';
      $wasFilled = TRUE;

      // Check if this gap still exists after sync.
      foreach ($gapsAfter as $after) {
        if ($after['start'] === $before['start'] && $after['end'] === $before['end']) {
          $wasFilled = FALSE;
          $remaining[] = [
            'start' => $after['start'],
            'end' => $after['end'],
            'reason' => $after['reason'],
          ];
          break;
        }
      }

      if ($wasFilled && !$wasExcluded) {
        $filled[] = [
          'start' => $before['start'],
          'end' => $before['end'],
          'reason' => $before['reason'],
        ];
      }
    }

    // Display what was successfully synced.
    if (!empty($filled)) {
      $this->io->success('Synced:');
      foreach ($filled as $gap) {
        $label = $this->getGapLabel($gap['reason']);
        $this->io->text(sprintf('  ✓ %s to %s  %s',
          date('M j, g:ia', strtotime($gap['start'])),
          date('M j, g:ia', strtotime($gap['end'])),
          $label
        ));
      }
      $this->io->newLine();
    }

    // Display what still needs attention.
    if (!empty($remaining)) {
      $actionable = $informational = [];

      foreach ($remaining as $gap) {
        $item = sprintf('%s to %s  %s',
          date('M j, g:ia', strtotime($gap['start'])),
          date('M j, g:ia', strtotime($gap['end'])),
          $this->getGapLabel($gap['reason'])
        );

        if (in_array($gap['reason'], ['recent_data', 'beyond_retention'])) {
          $informational[] = $item;
        }
        else {
          $actionable[] = $item;
        }
      }

      if (!empty($actionable)) {
        $this->io->warning('Still Missing (run sync again):');
        foreach ($actionable as $item) {
          $this->io->text('  ⚠ ' . $item);
        }
        $this->io->newLine();
      }

      if (!empty($informational)) {
        $this->io->note('Cannot Sync:');
        foreach ($informational as $item) {
          $this->io->text('  ℹ ' . $item);
        }
      }
    }
    else {
      $this->io->text('✓ All gaps filled - complete coverage achieved');
    }
  }

  /**
   * Get user-friendly label for gap reason.
   */
  protected function getGapLabel(string $reason): string
  {
    return match($reason) {
      'recent_data' => '(too recent - still processing)',
      'beyond_retention' => '(beyond 2-week API retention)',
      'no_sync_history' => '(historical data)',
      'gap_between_syncs' => '(gap in coverage)',
      default => "($reason)",
    };
  }
}
