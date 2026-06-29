# Contributing

This document provides guidelines for developers working on the SolarWinds Log Analysis Tools codebase.


## Quick Start for New Contributors (AI or Human)

When starting work on this project, follow this sequence to get properly oriented:

1. **Read [README.md](../README.md) first** - Understand the project overview and basic usage
2. **Read [CLAUDE.md](../CLAUDE.md)** - AI behavioral guide and collaboration patterns (essential for AI contributors)
3. **Read the code** - Familiarize yourself with the architecture, particularly:
   - `BaseSolarWindsCommand.php` - Abstract base class with shared functionality
   - The four core commands: `StatusCommand.php`, `SearchCommand.php`, `BotCommand.php`, `ExploitsCommand.php`
   - Service layer: `ConfigurationService.php`, `ApiService.php`, `DisplayService.php`, `DatabaseService.php`
4. **Read [TODO.md](TODO.md)** - Understand current priorities and remaining work
5. **Ask clarifying questions** - Before starting implementation work

This orientation sequence ensures you understand both the technical architecture and the collaborative patterns that have proven effective for this project.

## :red_circle: CRITICAL ARCHITECTURE CONSTRAINTS

These architectural principles are NON-NEGOTIABLE. Violations create technical debt and break the design. Read these FIRST before making any code changes.

### DatabaseService Layer Separation

**DatabaseService is a LOW-LEVEL service** - It provides raw database access ONLY.

**:white_check_mark: ALLOWED in DatabaseService:**
- Schema management (CREATE TABLE, CREATE INDEX)
- Raw SQL execution (`query()`, `execute()`, `prepare()`)
- Basic CRUD operations (`insertLogs()` with no business logic)
- Transaction management (`beginTransaction()`, `commit()`, `rollback()`)

**:x: FORBIDDEN in DatabaseService:**
- Business logic and domain-specific queries
- Application-specific parameters (`$whereClause`, `$since`, `$until`, `$filters`)
- Query building based on application state
- Result filtering or transformation

**Example violations to AVOID:**

```php
// :x: WRONG - Application logic in DatabaseService
public function getLogsWithQuery($whereClause, $params, $since, $until) { }
public function getLogsByIp($ip, $since, $until) { }
public function getCampaignLogs($filters) { }

// :white_check_mark: CORRECT - Raw execution only
public function query($sql, $params = []) { }
public function execute($sql, $params = []) { }
public function insertLogs(array $logs) { } // Simple CRUD only
```

**Where application logic SHOULD go:**
- **CampaignAnalysisService** - Campaign queries and analysis logic
- **SyncTrackingService** - Sync status and gap detection logic
- **LogQueryService** - Log retrieval and filtering logic
- **Command classes** - WHERE clause building, result filtering, display logic

**Before adding a method to DatabaseService, ask:**
1. Does this method contain business logic? :arrow_right: Wrong layer
2. Does this take application-specific parameters? :arrow_right: Wrong layer
3. Could this go in a specialized service? :arrow_right: Probably should

### Service Layer Principles

**Single Responsibility:**
- Each service has ONE focused responsibility
- Services should be stateless where possible
- Use dependency injection rather than static methods
- Handle errors gracefully with meaningful messages

**Service Examples:**
- **ConfigurationService** - Configuration parsing and validation ONLY
- **ApiService** - API communication and response handling ONLY
- **DisplayService** - Display formatting and color schemes ONLY
- **DatabaseService** - Raw database storage ONLY
- **CampaignAnalysisService** - Campaign analysis logic
- **RiskScoringService** - Risk calculation logic

**Dependency Injection:**
- Services are injected through constructor parameters
- Use interface-based dependencies where appropriate
- Maintain immutable service state where possible

### Base Class Architecture

**Abstract Method Contracts:**
- Keep abstract methods focused and single-purpose
- Implement validation in child classes, not base class
- Use protected methods for extension points
- Document the base class contract clearly

**BaseSolarWindsCommand Pattern:**
All commands extend `BaseSolarWindsCommand` and implement three abstract methods:

```php
abstract protected function buildSearchQuery(array $options): string;
abstract protected function parseScriptSpecificOptions(InputInterface $input): array;
abstract protected function validateQuery(string $query, array $options): void;
```

This ensures:
- **Consistent behavior** across all commands
- **Code reuse** for common functionality
- **Extensibility** for command-specific logic

### Configuration System Principles

**Configuration Handling:**
- Validate configuration on load, not on use
- Provide clear error messages for configuration problems
- Support both development and production configuration patterns
- Document all configuration options with examples

**YAML-Based Configuration:**
- Uses `~/.solarwinds.yml` for all configuration
- Site mapping with dynamic command option generation
- Alias support that appears as real commands


## Project Architecture

### Overview

This project migrated from a collection of shell scripts to a modern PHP/Symfony Console application. The architecture emphasizes maintainability, extensibility, and code reuse through established design patterns.

### Core Components

**Commands:** Four foundational commands provide comprehensive log analysis capabilities:
- **`StatusCommand`** - HTTP status code analysis with shortcuts and filtering
- **`SearchCommand`** - General-purpose log search with flexible query syntax
- **`BotCommand`** - Bot and crawler traffic analysis with user agent filtering
- **`ExploitsCommand`** - Security threat detection and IP blocking recommendations

**Services:** Modular service layer provides shared functionality:
- **`ConfigurationService`** - YAML configuration parsing, site mapping, and CMS configuration
- **`ApiService`** - SolarWinds API communication with pagination
- **`DisplayService`** - Output formatting, coloring, and display modes
- **`DatabaseService`** - SQLite storage with intelligent range detection and auto-sync
- **`BlockingService`** - IP allowlist checking, bot classification, and blocking recommendations
- **`CampaignAnalysisService`** - Campaign analysis and risk assessment
- **`RiskScoringService`** - Threat risk scoring logic
- **`SyncTrackingService`** - Sync status tracking and gap detection
- **`BotIpService`** - Bot IP verification and CIDR range management

**Base Class Inheritance:** `BaseSolarWindsCommand` provides common functionality while allowing command-specific implementations through abstract methods.

### Configuration System

**YAML-Based:** Uses `~/.solarwinds.yml` for all configuration
**Site Mapping:** Dynamic command option generation from site configuration
**Alias Support:** Configurable command aliases that appear as real commands

Example site configuration with wildcard support:

```yaml
sites:
  main:
    label: Main Site
    hosts:
      - example.com
      - "*.example.com"
  blog:
    label: Company Blog
    hosts:
      - blog.example.com
```

The new format supports:
- Wildcard patterns (`"*.example.com"` matches both base domain and all subdomains - must be quoted)
- Multiple hostnames per site
- Cleaner YAML structure with site name as the key


## Coding Standards

### PHP Standards

**PSR Compliance:**
- **PSR-4 Autoloading** - Namespace structure matches directory structure
- **PSR-12 Extended Coding Style** - Method naming, class structure, indentation

**Type Safety:**
- **Explicit type declarations** for all parameters and return values
- **Strict typing** enabled where appropriate
- **Comprehensive DocBlocks** for all public methods

**Constructor Property Promotion (PHP 8.0+):**
- Use PHP 8.0+ constructor property promotion syntax consistently
- Eliminates boilerplate property declarations and assignments
- Already used in `CampaignAnalysisService` - apply pattern consistently across all services

```php
// :white_check_mark: Good - Constructor property promotion:
public function __construct(
  protected DatabaseService $database,
  protected DisplayService $display
) {
}

// :x: Bad - Separate property declaration and assignment:
protected DatabaseService $database;
protected DisplayService $display;

public function __construct(DatabaseService $database, DisplayService $display)
{
  $this->database = $database;
  $this->display = $display;
}
```

**Array Formatting:**
- Multi-item arrays should have each item on separate lines
- Include trailing comma on last item (even the last one)
- Makes diffs cleaner and prevents merge conflicts

```php
// :white_check_mark: Good:
'regex' => [
  '/_profiler',
  '/_wdt',
  '/debug',
  '/trace',
],

// :x: Bad:
'regex' => ['/_profiler', '/_wdt', '/debug', '/trace'],
```

**Code Style:**
- **Use uppercase NULL, TRUE, FALSE** - Always capital letters for PHP constants
- **Use protected instead of private** - Better extensibility for inheritance
  - Services should be extensible, not sealed APIs
  - Allows subclassing without modifying the original class
  - Applies to ALL classes: commands, services, utilities
- **Comments are sentences** - Start with capital letter, end with period
- **No trailing whitespace** - Clean all files with `sed -i '' 's/ *$//' filename` (macOS)

**Time Handling Conventions:**
- **Variables ending in `_time`** - Unix timestamps (integers), e.g., `$startTime`, `$endTime`
- **Variables ending in `_iso8601`** - ISO 8601 formatted strings, e.g., `$startTimeIso8601`
- **Internal code uses integers** - All time values are unix timestamps throughout the application
- **API boundary converts to ISO 8601** - Only `ApiService` converts timestamps to ISO 8601 for external APIs
- **Benefits:** Faster comparisons, arithmetic operations, and consistent type handling

### Symfony Framework Conventions

**Command Structure:**
- Commands extend `Symfony\Component\Console\Command\Command`
- Use constructor dependency injection for services
- Follow standard `InputInterface`/`OutputInterface` pattern
- Implement abstract base class methods consistently

**Service Integration:**
- Register services in dependency injection container
- Use interface-based dependencies where appropriate
- Maintain immutable service state where possible

### Code Quality Requirements

**General Principles:**
- **Single responsibility** - Classes and methods should have one clear purpose
- **Descriptive naming** - Method and variable names should be self-documenting
- **Comprehensive validation** - All user inputs must be validated and sanitized
- **Consistent indentation** - 2 spaces, no tabs
- **Error handling** - Graceful failure with informative error messages

**Documentation Standards (required, not optional):**
- **Every class** has a docblock at the top describing its purpose and any architectural context (layering rules, single-responsibility scope, etc.)
- **Every public and protected method** has a docblock with a one-line description, `@param` for each parameter, and `@return` if it returns. Private methods get a docblock when their purpose isn't obvious from the name.
- **Property docblocks** when the type or purpose isn't self-evident from the property name plus its declared type.
- **Inline comments** follow the project's general comment policy: default to none; add one only when the WHY is non-obvious (a hidden constraint, a subtle invariant, a workaround for a specific bug, behavior that would surprise a reader). Don't restate what the code already says.
- **README and EXPLOITS.md updates** are required for any user-facing change.

These rules are enforced by `composer cs` (see Development Workflow below). Run it before submitting changes.


## Development Workflow

### Setting Up Development Environment

1. **Clone and install dependencies:**
   ```bash
   composer install
   chmod +x bin/solarwinds
   ```

2. **Create development configuration:**
   ```bash
   cp ~/.solarwinds.yml ~/.solarwinds-dev.yml
   # Edit with development settings
   ```

3. **Run tests and validation:**
   ```bash
   # Test basic functionality
   bin/solarwinds status --help
   bin/solarwinds search --help
   bin/solarwinds bot --help
   bin/solarwinds exploits --help

   # Run test scripts (see tests/README.md)
   ./tests/test_interrupt.sh
   php tests/test_blocking_config.php
   ```

### Version Control Integration

**Recommended Workflow:**
- Maintain local git repository for tracking changes
- Commit after each significant modification
- Use descriptive commit messages indicating what functionality was added/changed
- Review diffs before accepting changes to understand impact

**Branch Strategy:**
- Use feature branches for new command implementations
- Create experiment branches when trying different approaches
- Keep main branch stable and functional

### Testing Strategy

The project uses manual test scripts rather than a formal testing framework. See [tests/README.md](../tests/README.md) for detailed testing documentation.

**Manual Testing Requirements:**
1. **Functional testing** - Does it work for basic use cases?
2. **Specification compliance** - Does it match original requirements exactly?
3. **Edge case testing** - What happens with unusual inputs?
4. **Integration testing** - Does it work with existing systems?
5. **Performance validation** - Does it meet performance requirements?

**Validation Checklist:**
- Test with various time ranges (`--15m`, `--1h`, `--1d`)
- Verify site filtering works with configured sites
- Check display options produce expected output formats
- Validate database auto-sync behavior for long-running queries
- Test error handling with invalid inputs
- Verify interrupt handling with Ctrl+C (SIGINT)
- Test blocking recommendations with allowlist configuration


## Adding New Commands

### Implementation Checklist

1. **Extend BaseSolarWindsCommand**
2. **Implement three abstract methods:**
   - `buildSearchQuery()` - Build SolarWinds query from options
   - `parseScriptSpecificOptions()` - Parse command-specific arguments
   - `validateQuery()` - Validate query and options for consistency

3. **Add command configuration:**
   - Set name, description, and arguments in `configure()`
   - Add any command-specific options
   - Follow naming conventions consistent with existing commands

4. **Test thoroughly:**
   - Verify basic functionality with common use cases
   - Test edge cases and error conditions
   - Compare output with original shell script (if migrating)
   - Validate performance with large datasets

### Command Development Guidelines

**Query Building:**
- Use SolarWinds query syntax consistently
- Handle multiple filter conditions properly
- Support time range filtering through base class
- Validate query syntax before API calls

**Option Parsing:**
- Parse and validate all command-specific options
- Provide meaningful error messages for invalid combinations
- Support both short and long option formats where appropriate
- Document option behavior clearly

**Output Formatting:**
- Use DisplayService for consistent formatting
- Support multiple display modes (status, host, country, etc.)
- Implement proper color coding and visual hierarchy
- Handle empty results gracefully


## Reference Materials

### Development Context and Lessons Learned

- **[CLAUDE.md](../CLAUDE.md)** - AI behavioral guide and collaboration patterns (ESSENTIAL for AI contributors)
- **[CASE STUDY](CASE_STUDY.md)** - Initial migration lessons learned about AI-assisted development
- **[CASE STUDY 2](CASE_STUDY_2.md)** - Advanced feature development and exploit detection lessons
- **[TODO](TODO.md)** - Current priorities and planned features
- **[EXPLOITS](EXPLOITS.md)** - Exploit detection system documentation


## Troubleshooting Development Issues

### Common Problems

**Service Injection Issues:**
- Verify service registration in dependency injection container
- Check constructor parameter types match registered services
- Ensure services are properly instantiated before use

**Query Building Problems:**
- Validate SolarWinds query syntax using debug mode
- Check that time range conversion produces expected format
- Verify filter combinations don't create contradictory queries

**Display Formatting Issues:**
- Use DisplayService methods consistently across commands
- Test output with various terminal sizes and color capabilities
- Ensure proper handling of empty or large result sets

### Debug Tools

**Debug Mode:** Use `--debug` flag to see:
- Raw SolarWinds queries being executed
- API request/response details
- Database range detection information
- Service initialization and configuration

**Validation Mode:** Use `--validate` flag to:
- Verify result consistency across runs
- Check for data integrity issues
- Validate database behavior


## Attribution

**Architecture and Direction:** Doug Green (douggreen@douggreenconsulting.com)
**Implementation:** Developed collaboratively using Claude AI assistance

This project demonstrates effective patterns for AI-assisted development while maintaining professional code quality and architectural integrity.
