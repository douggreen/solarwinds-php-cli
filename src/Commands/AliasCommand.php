<?php

/**
 * @file AliasCommand.php
 * @brief Dynamic command generation from YAML configuration aliases
 *
 * @class AliasCommand
 * @brief Creates dynamic commands from YAML configuration without requiring code changes
 *
 * This class enables configuration-driven command extension by creating fully functional
 * commands from YAML alias definitions. It preserves all functionality of target commands
 * while adding pre-configured arguments and options.
 *
 * @section dynamic_generation Dynamic Command Generation
 *
 * **Configuration-Based Creation:**
 * - Reads alias definitions from ~/.solarwinds.yml
 * - Creates commands with pre-configured arguments
 * - Preserves all target command functionality
 * - Enables user customization without code modification
 *
 * **Alias Definition Format:**
 * - Key: Alias command name
 * - Value: Target command with arguments and options
 * - Supports complex argument combinations
 * - Handles quoted arguments and option values
 *
 * @section argument_handling Argument Handling
 *
 * **Argument Merging Strategy:**
 * - Alias arguments are applied first (lower priority)
 * - User arguments override alias arguments (higher priority)
 * - Options are merged with user options taking precedence
 * - Maintains all original command validation
 *
 * **Supported Argument Types:**
 * - Simple flags: --1h, --status, --host
 * - Options with values: --query="value", --min-count=5
 * - Positional arguments: search terms, patterns
 * - Complex combinations: Multiple options and flags
 *
 * @section validation_inheritance Validation Inheritance
 *
 * **Target Command Validation:**
 * - Inherits all validation from target command
 * - Preserves argument definitions and constraints
 * - Maintains help text and usage information
 * - Applies target command's specific validation logic
 *
 * **Conflict Prevention:**
 * - Validates alias names don't conflict with built-in commands
 * - Ensures target commands exist before creating aliases
 * - Provides meaningful error messages for configuration issues
 *
 * @section example Configuration Examples
 * @code{.yaml}
 * aliases:
 *   # Simple shortcuts
 *   errors: 'search "error" --status --1h'
 *   quickbot: 'bot --15m --ua'
 *   404s: 'status --404 --host --path'
 *
 *   # Complex analysis commands
 *   security: 'search "unauthorized|forbidden|attack" --country --day'
 *   performance: 'status --5xx --by-hour --2h --min-count=10'
 *
 *   # Site-specific shortcuts
 *   site1-errors: 'search "error" --site1 --status --1h'
 *   site1-bots: 'bot --site1 --country --day'
 * @endcode
 *
 * @section usage_examples Usage Examples
 * @code{.bash}
 * # Using predefined aliases
 * solarwinds errors              # Equivalent to: search "error" --status --1h
 * solarwinds 404s --day          # Extends: status --404 --host --path --day
 * solarwinds quickbot spider     # Extends: bot --15m --ua spider
 *
 * # User arguments override alias defaults
 * solarwinds errors --2h         # Changes time from --1h to --2h
 * solarwinds 404s --country      # Adds --country to existing options
 * @endcode
 *
 * @section command_creation Command Creation Process
 *
 * **Registration Flow:**
 * 1. Parse alias definition into command and arguments
 * 2. Validate target command exists in application
 * 3. Create AliasCommand instance with parsed configuration
 * 4. Copy argument definitions from target command
 * 5. Register alias command in application
 *
 * **Execution Flow:**
 * 1. Parse user-provided arguments
 * 2. Merge with pre-configured alias arguments
 * 3. Execute target command with merged arguments
 * 4. Apply target command's validation and logic
 *
 * @section debug_support Debug Support
 *
 * **Debug Output:**
 * - Shows alias resolution when --debug flag is used
 * - Displays argument merging process
 * - Reveals target command execution details
 * - Helps troubleshoot alias configuration issues
 *
 * **Troubleshooting:**
 * - Clear error messages for invalid alias definitions
 * - Validation of target command availability
 * - Helpful suggestions for configuration corrections
 *
 * @section extensibility_benefits Extensibility Benefits
 *
 * **User Customization:**
 * - Create shortcuts for frequently used command combinations
 * - Standardize analysis patterns across teams
 * - Customize default options for specific environments
 * - Build domain-specific command vocabularies
 *
 * **Maintenance Advantages:**
 * - No code changes required for new command variants
 * - Configuration-driven functionality extension
 * - Easy sharing of command configurations
 * - Version control for analysis patterns
 *
 * @see SolarWindsApplication For alias registration and management
 * @see ConfigurationService For alias definition parsing
 * @see BaseSolarWindsCommand For target command functionality
 *
 * @note Alias commands inherit all functionality from their target commands
 * @warning Alias names cannot conflict with built-in command names
 */

namespace SolarWinds\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Alias Command - Dynamic command wrapper for configured aliases
 *
 * Creates commands from user-defined aliases in ~/.solarwinds.yml
 * Forwards execution to target commands with merged arguments.
 */
class AliasCommand extends Command
{
  protected string $targetCommand;
  protected array $aliasArgs;

  public function __construct(string $aliasName, string $targetCommand, array $aliasArgs)
  {
    $this->targetCommand = $targetCommand;
    $this->aliasArgs = $aliasArgs;

    parent::__construct($aliasName);

    $this->setDescription("Alias for: {$targetCommand} " . implode(' ', $aliasArgs));
    $this->setHelp("This is an alias that runs: <info>{$targetCommand} " . implode(' ', $aliasArgs) . "</info>\n\nYou can add additional arguments that will be appended to the alias command.");
  }

  /**
   * Copy target command's definition after application is available.
   */
  public function setApplication($application = NULL): void
  {
    parent::setApplication($application);

    if ($application && $application->has($this->targetCommand)) {
      $targetCommand = $application->find($this->targetCommand);
      $targetDefinition = $targetCommand->getDefinition();

      // Copy all options from target command
      foreach ($targetDefinition->getOptions() as $option) {
        if (!$this->getDefinition()->hasOption($option->getName())) {
          $this->getDefinition()->addOption($option);
        }
      }

      // Copy all arguments from target command
      foreach ($targetDefinition->getArguments() as $argument) {
        if (!$this->getDefinition()->hasArgument($argument->getName())) {
          $this->getDefinition()->addArgument($argument);
        }
      }
    }
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $application = $this->getApplication();
    if (!$application) {
      throw new \RuntimeException('Application not set for alias command');
    }

    // Get the target command
    $command = $application->find($this->targetCommand);

    // Debug output when --debug is enabled
    if ($input->getOption('debug')) {
      $output->writeln("<comment>DEBUG [AliasCommand]: Alias '{$this->getName()}' targeting '{$this->targetCommand}'</comment>");
      $output->writeln("<comment>DEBUG [AliasCommand]: Raw alias args: " . implode(', ', $this->aliasArgs) . "</comment>");
    }

    // Start with target command name
    $mergedArgs = ['command' => $this->targetCommand];

    // Parse alias arguments into proper format
    foreach ($this->aliasArgs as $arg) {
      if ($input->getOption('debug')) {
        $output->writeln("<comment>DEBUG [AliasCommand]: Processing alias arg: '$arg'</comment>");
      }

      if (strpos($arg, '--') === 0) {
        // This is an option
        if (strpos($arg, '=') !== FALSE) {
          // Option with value: --query="value"
          [$optionName, $optionValue] = explode('=', $arg, 2);
          $optionName = ltrim($optionName, '-');

          // Check if this option is defined as VALUE_IS_ARRAY in the target command
          // If so, wrap the value in an array
          if ($application && $application->has($this->targetCommand)) {
            $targetCommand = $application->find($this->targetCommand);
            $targetDefinition = $targetCommand->getDefinition();
            if ($targetDefinition->hasOption($optionName)) {
              $option = $targetDefinition->getOption($optionName);
              if ($option->isArray()) {
                $optionValue = [$optionValue];
              }
            }
          }

          $mergedArgs['--' . $optionName] = $optionValue;
          if ($input->getOption('debug')) {
            $output->writeln("<comment>DEBUG [AliasCommand]: Added option '--{$optionName}' = " . var_export($optionValue, TRUE) . "</comment>");
          }
        } else {
          // Boolean option: --host
          $optionName = ltrim($arg, '-');
          $mergedArgs['--' . $optionName] = TRUE;
          if ($input->getOption('debug')) {
            $output->writeln("<comment>DEBUG [AliasCommand]: Added boolean option '--{$optionName}' = true</comment>");
          }
        }
      } else {
        // This is a positional argument
        if ($this->targetCommand === 'search') {
          $mergedArgs['search_term'] = $arg;
          if ($input->getOption('debug')) {
            $output->writeln("<comment>DEBUG [AliasCommand]: Added search_term: '{$arg}'</comment>");
          }
        } elseif ($this->targetCommand === 'bot') {
          $mergedArgs['agent'] = $arg;
          if ($input->getOption('debug')) {
            $output->writeln("<comment>DEBUG [AliasCommand]: Added agent: '{$arg}'</comment>");
          }
        }
      }
    }

    // Add user-provided options and arguments (they override alias values)
    if ($input->getOption('debug')) {
      $output->writeln("<comment>DEBUG [AliasCommand]: Processing user input options...</comment>");
    }

    // List of options where NULL is a valid "present but no value" indicator (VALUE_OPTIONAL options)
    $nullableOptions = ['vars', 'path', 'cached', 'path-filter'];

    // Special handling: if we see option '28' = true, this is likely --500 being incorrectly mapped
    // Check if --500 was actually specified by the user
    $has500Option = FALSE;
    if (isset($_SERVER['argv'])) {
      foreach ($_SERVER['argv'] as $arg) {
        if ($arg === '--500') {
          $has500Option = TRUE;
          break;
        }
      }
    }

    foreach ($input->getOptions() as $name => $value) {
      if ($input->getOption('debug')) {
        $output->writeln("<comment>DEBUG [AliasCommand]: User option '{$name}' = " . var_export($value, TRUE) . "</comment>");
      }

      // Skip symfony built-in options
      if (in_array($name, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction'])) {
        continue;
      }

      // Skip the problematic '28' option that seems to be incorrectly mapped from --500
      if ($name === '28') {
        continue;
      }

      // For VALUE_OPTIONAL options (like --vars, --path), NULL means the flag was present without a value
      // FALSE means the flag was not present at all
      // We want to include NULL (flag present) but exclude FALSE (flag absent)
      if ($value === FALSE) {
        // Option not specified - skip it
        continue;
      }

      // Skip NULL values unless it's an option that uses NULL to mean "present without value"
      // This prevents passing --query=NULL which causes "option requires a value" errors
      if ($value === NULL && !in_array($name, $nullableOptions)) {
        if ($input->getOption('debug')) {
          $output->writeln("<comment>DEBUG [AliasCommand]: Skipping NULL value for non-nullable option '{$name}'</comment>");
        }
        continue;
      }

      // For VALUE_IS_ARRAY options (like --filter), an empty array means the user didn't specify the option
      // Skip empty arrays to avoid overwriting alias values
      if (is_array($value) && empty($value)) {
        if ($input->getOption('debug')) {
          $output->writeln("<comment>DEBUG [AliasCommand]: Skipping empty array for option '{$name}'</comment>");
        }
        continue;
      }

      // Include all other values (NULL for nullable options, true, strings, numbers, non-empty arrays)
      $mergedArgs['--' . $name] = $value;
      if ($input->getOption('debug')) {
        $output->writeln("<comment>DEBUG [AliasCommand]: Added user option '--{$name}' = " . var_export($value, TRUE) . "</comment>");
      }
    }

    // If we detected --500 was used but got mapped to '28', add it correctly
    if ($has500Option) {
      $mergedArgs['--500'] = TRUE;
      if ($input->getOption('debug')) {
        $output->writeln("<comment>DEBUG [AliasCommand]: Corrected --500 option mapping</comment>");
      }
    }

    foreach ($input->getArguments() as $name => $value) {
      if ($input->getOption('debug')) {
        $output->writeln("<comment>DEBUG [AliasCommand]: User argument '{$name}' = " . var_export($value, TRUE) . "</comment>");
      }

      if ($value !== NULL && $name !== 'command') {
        $mergedArgs[$name] = $value;
        if ($input->getOption('debug')) {
          $output->writeln("<comment>DEBUG [AliasCommand]: Added user argument '{$name}' = " . var_export($value, TRUE) . "</comment>");
        }
      }
    }

    if ($input->getOption('debug')) {
      $output->writeln("<comment>DEBUG [AliasCommand]: Final merged arguments:</comment>");
      foreach ($mergedArgs as $key => $value) {
        $output->writeln("<comment>DEBUG [AliasCommand]: '{$key}' => " . var_export($value, TRUE) . "</comment>");
      }
    }

    // Create new input and execute
    $mergedInput = new ArrayInput($mergedArgs);

    if ($input->getOption('debug')) {
      $output->writeln("<comment>DEBUG [AliasCommand]: About to execute target command '{$this->targetCommand}'</comment>");
    }

    return $command->run($mergedInput, $output);
  }
}
