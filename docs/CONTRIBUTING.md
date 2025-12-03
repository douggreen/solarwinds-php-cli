# Contributing

This document provides guidelines for developers working on the SolarWinds Log Analysis Tools codebase.


## Quick Start for New Contributors (AI or Human)

When starting work on this project, follow this sequence to get properly oriented:

1. **Read [README.md](README.md) first** - Understand the project overview and basic usage
2. **Follow all documentation links from [README.md](README.md)** - Especially [CLAUDE-MUST-READ-FIRST.md](CLAUDE-MUST-READ-FIRST.md) for AI behavioral context
3. **Read the code** - Familiarize yourself with the architecture, particularly:
   - `BaseSolarWindsCommand.php` - Abstract base class with shared functionality
   - The four core commands: `StatusCommand.php`, `SearchCommand.php`, `BotCommand.php`, `ExploitsCommand.php`
   - Service layer: `ConfigurationService.php`, `ApiService.php`, `DisplayService.php`, `DatabaseService.php`
4. **Read [TODO.md](TODO.md)** - Understand current priorities and remaining work
5. **Ask clarifying questions** - Before starting implementation work

This orientation sequence ensures you understand both the technical architecture and the collaborative patterns that have proven effective for this project.
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

**Base Class Inheritance:** `BaseSolarWindsCommand` provides common functionality while allowing command-specific implementations through abstract methods.

### Base Class Implementation

All commands extend `BaseSolarWindsCommand` and implement three abstract methods:

```php
abstract protected function buildSearchQuery(array $options): string;
abstract protected function parseScriptSpecificOptions(InputInterface $input): array;
abstract protected function validateQuery(string $query, array $options): void;
```

This pattern ensures:
- **Consistent behavior** across all commands (time parsing, caching, output)
- **Code reuse** for common functionality (API calls, progress bars, error handling)
- **Extensibility** for command-specific logic while maintaining shared infrastructure

### Service Layer Architecture

**Dependency Injection:** Services are injected through constructor parameters:

```php
public function __construct(
    ConfigurationService $configService,
    ApiService $apiService,
    DisplayService $displayService,
    DatabaseService $databaseService
) {
    parent::__construct();
    // ...
}
```

**Single Responsibility:** Each service has a focused responsibility:
- Configuration parsing and validation
- API communication and response handling
- Display formatting and color schemes
- Database storage with range detection and auto-sync

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

## Coding Standards

### PHP Standards

**PSR Compliance:**
- **PSR-4 Autoloading** - Namespace structure matches directory structure
- **PSR-12 Extended Coding Style** - Method naming, class structure, indentation

**Type Safety:**
- **Explicit type declarations** for all parameters and return values
- **Strict typing** enabled where appropriate
- **Comprehensive DocBlocks** for all public methods

**Code Style:**
- **Use uppercase NULL, TRUE, FALSE** - Always capital letters for PHP constants
- **Use protected instead of private** - Better extensibility for inheritance
- **Comments are sentences** - Start with capital letter, end with period
- **No trailing whitespace** - Clean all files with `sed -i 's/ *$//' filename`

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
- **Consistent indentation** - 4 spaces, no tabs
- **Error handling** - Graceful failure with informative error messages

**Documentation Standards:**
- **Class-level DocBlocks** with purpose and architectural context
- **Method documentation** with parameter types, return values, and behavior
- **Inline comments** for complex logic and design decisions
- **README updates** for any user-facing changes

### Architecture-Specific Guidelines

**Base Class Architecture:**
- Keep abstract methods focused and single-purpose
- Implement validation in child classes, not base class
- Use protected methods for extension points
- Document the base class contract clearly

**Service Layer:**
- Services should be stateless where possible
- Use dependency injection rather than static methods
- Handle errors gracefully and provide meaningful messages
- Use database storage for persistent data across queries

**Configuration Handling:**
- Validate configuration on load, not on use
- Provide clear error messages for configuration problems
- Support both development and production configuration patterns
- Document all configuration options with examples

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

### Original Shell Scripts

The original shell scripts are preserved for reference and provide authoritative specifications for:
- **Exact API parameters** and query syntax
- **Output formatting** and display patterns
- **Validation logic** and error handling
- **Performance characteristics** and optimization patterns

### Development Context

- **[CASE STUDY](CASE_STUDY.md)** - Lessons learned about AI-assisted development
- **[CLAUDE MUST READ FIRST](CLAUDE-MUST-READ-FIRST.md)** - AI development context and behavioral patterns
- **[TODO](TODO.md)** - Additional planned features and improvements

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
