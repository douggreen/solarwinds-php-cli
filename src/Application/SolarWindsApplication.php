<?php

/**
 * @file SolarWindsApplication.php
 * @brief Main Symfony Console application for SolarWinds log analysis tools
 *
 * @class SolarWindsApplication
 * @brief Primary application controller that orchestrates command registration
 *        and alias management
 *
 * This file contains the main application class that serves as the entry point
 * for the entire SolarWinds log analysis system. The application provides a
 * modern PHP/Symfony Console architecture for comprehensive log analysis and
 * monitoring capabilities.
 *
 * ARCHITECTURE OVERVIEW:
 *
 * This application implements a hybrid architecture strategy that combines core
 * commands with dynamic alias generation to provide both flexibility and ease
 * of use.
 *
 * DIRECTORY STRUCTURE:
 *
 * src/
 * ├── Application/           # Main Symfony application (this file)
 * ├── Commands/             # Command classes using inheritance
 * │   ├── BaseSolarWindsCommand.php  # Abstract base with shared functionality
 * │   ├── BotCommand.php            # Bot traffic analysis
 * │   ├── SearchCommand.php         # General log search
 * │   ├── StatusCommand.php         # HTTP status code analysis
 * │   └── AliasCommand.php          # Dynamic commands from YAML config
 * └── Services/             # Service layer with single responsibilities
 *     ├── ConfigurationService.php  # YAML configuration and site mappings
 *     ├── ApiService.php           # SolarWinds API integration
 *     ├── DisplayService.php       # Result formatting and display logic
 *     ├── CacheService.php         # Time-based caching
 *     └── TimeSpecifications.php   # Centralized time handling
 *
 * KEY DESIGN PATTERNS:
 *
 * 1. Abstract Base Class Inheritance: BaseSolarWindsCommand provides common
 *    functionality, while child commands implement only 3 abstract methods:
 *    - buildSearchQuery(array $options): string
 *    - parseScriptSpecificOptions(InputInterface $input): array
 *    - validateQuery(string $query, array $options): void
 *
 * 2. Service Layer: Separate services handle configuration, API calls, display
 *    formatting, and caching with clear separation of concerns.
 *
 * 3. Dynamic Command Registration: AliasCommand creates commands from YAML
 *    configuration, allowing users to extend functionality without code changes.
 *
 * ADDING NEW COMMANDS:
 *
 * 1. Create a new command class extending BaseSolarWindsCommand
 * 2. Implement the required abstract methods:
 *    - buildSearchQuery(array $options): string
 *    - parseScriptSpecificOptions(InputInterface $input): array
 *    - validateQuery(string $query, array $options): void
 * 3. Register the command in this SolarWindsApplication class
 *
 * Example:
 *
 * <?php
 * namespace SolarWinds\Commands;
 *
 * class MyNewCommand extends BaseSolarWindsCommand
 * {
 *     protected string $defaultTime = '1h';
 *     protected array $defaultDisplayOptions = ['host'];
 *
 *     protected function configure(): void
 *     {
 *         $this->setName('mynew')
 *              ->setDescription('My new command description');
 *         parent::configure();
 *     }
 *
 *     protected function buildSearchQuery(array $options): string
 *     {
 *         return "your search query";
 *     }
 *
 *     // ... implement other abstract methods
 * }
 *
 * Then register in this file:
 * $this->addCommands([
 *     new BotCommand(),
 *     new SearchCommand(),
 *     new StatusCommand(),
 *     new MyNewCommand(),  // Add your new command here
 * ]);
 *
 * RECOMMENDED DEVELOPMENT TOOLS:
 * - PHP-CS-Fixer: Automated code style enforcement
 * - PHPStan: Static analysis for type safety
 * - Composer: Dependency management and autoloading
 */

namespace SolarWinds\Application;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use SolarWinds\Commands\BotCommand;
use SolarWinds\Commands\ExploitsCommand;
use SolarWinds\Commands\SearchCommand;
use SolarWinds\Commands\StatusCommand;
use SolarWinds\Commands\ArchiveCommand;
use SolarWinds\Commands\SyncCommand;
use SolarWinds\Commands\SyncStatusCommand;
use SolarWinds\Commands\AliasCommand;
use SolarWinds\Services\ConfigurationService;

/**
 * SolarWinds Console Application
 *
 * Main application class that registers all available commands and aliases.
 */
class SolarWindsApplication extends Application
{
  /**
   * Constructor.
   *
   * Initializes application and registers all core commands and aliases.
   */
  public function __construct()
  {
    parent::__construct('SolarWinds Log Analysis Tools', '2.0.0');

    // Register core commands.
    $this->addCommands([
      new ArchiveCommand(),
      new BotCommand(),
      new ExploitsCommand(),
      new SearchCommand(),
      new StatusCommand(),
      new SyncCommand(),
      new SyncStatusCommand(),
    ]);

    // Register aliases from configuration.
    $this->registerAliases();

    // Set default command to list available commands.
    $this->setDefaultCommand('list');
  }

  /**
   * Override run() to transform shorthand time options.
   *
   * This keeps help output clean by only registering --time option,
   * while allowing convenient shortcuts like --2w, --15m, --yesterday.
   *
   * Transformations:
   * - --2w → --time=2w
   * - --15m → --time=15m
   * - --yesterday → --time=yesterday
   * - --all → --time=all
   *
   * @param InputInterface|null $input Input interface
   * @param OutputInterface|null $output Output interface
   * @return int Exit code
   */
  public function run(?InputInterface $input = NULL, ?OutputInterface $output = NULL): int
  {
    // Transform shorthand time options to --time=value.
    if ($input === NULL && isset($_SERVER['argv'])) {
      $argv = $_SERVER['argv'];
      $transformed = [];

      foreach ($argv as $arg) {
        // Match time patterns: --2w, --15m, --3M, --7D, etc.
        if (preg_match('/^--(\d+[mhdwMyD])$/', $arg, $matches)) {
          $transformed[] = '--time=' . $matches[1];
        }
        // Match special time keywords: --yesterday, --all.
        elseif (preg_match('/^--(yesterday|all)$/', $arg, $matches)) {
          $transformed[] = '--time=' . $matches[1];
        }
        else {
          $transformed[] = $arg;
        }
      }

      // Update $_SERVER['argv'] for ArgvInput to use.
      $_SERVER['argv'] = $transformed;
    }

    return parent::run($input, $output);
  }

  /**
   * Register aliases from configuration.
   *
   * Loads and registers dynamic alias commands from YAML configuration.
   */
  protected function registerAliases(): void
  {
    try {
      $configService = new ConfigurationService();
      $aliases = $configService->getAliases();

      foreach ($aliases as $aliasName => $aliasDefinition) {
        $this->createAliasCommand($aliasName, $aliasDefinition);
      }
    }
    catch (\Exception $e) {
      // Silently continue if configuration fails - aliases are optional
    }
  }

  /**
   * Create and register an alias command.
   *
   * @param string $aliasName Name of the alias command
   * @param string $aliasDefinition Alias definition string from configuration
   * @throws \RuntimeException If alias conflicts with built-in command or target is unknown
   */
  protected function createAliasCommand(string $aliasName, string $aliasDefinition): void
  {
    // Check for conflicts with built-in commands
    if ($this->has($aliasName)) {
      throw new \RuntimeException("Alias '$aliasName' conflicts with built-in command");
    }

    // Parse alias definition: "command arg1 arg2 --flag"
    $parts = $this->parseAliasDefinition($aliasDefinition);
    if (empty($parts)) {
      throw new \RuntimeException("Invalid alias definition for '$aliasName': '$aliasDefinition'");
    }

    $targetCommand = array_shift($parts);
    $aliasArgs = $parts;

    // Validate target command exists
    if (!$this->has($targetCommand)) {
      throw new \RuntimeException("Alias '$aliasName' targets unknown command '$targetCommand'");
    }

    // Create and register alias command
    $aliasCommand = new AliasCommand($aliasName, $targetCommand, $aliasArgs);
    $this->add($aliasCommand);
  }

  /**
   * Parse alias definition into command and arguments.
   *
   * Handles quoted arguments and splits definition into command parts.
   *
   * @param string $definition Alias definition string
   * @return array Array of command parts [command, arg1, arg2, ...]
   */
  protected function parseAliasDefinition(string $definition): array
  {
    // Simple parsing: split on spaces, handle quoted arguments
    $parts = [];
    $current = '';
    $inQuotes = FALSE;
    $quoteChar = '';
    $definitionLen = strlen($definition);

    for ($i = 0; $i < $definitionLen; $i++) {
      $char = $definition[$i];

      if (!$inQuotes && ($char === '"' || $char === "'")) {
        $inQuotes = TRUE;
        $quoteChar = $char;
      }
      elseif ($inQuotes && $char === $quoteChar) {
        $inQuotes = FALSE;
        $quoteChar = '';
      }
      elseif (!$inQuotes && $char === ' ') {
        if ($current !== '') {
          $parts[] = $current;
          $current = '';
        }
      }
      else {
        $current .= $char;
      }
    }

    if ($current !== '') {
      $parts[] = $current;
    }

    return $parts;
  }
}
