# Testing

This directory contains test scripts for the SolarWinds Log Analysis Tools project.

## Test Categories

### Shell Script Tests

Integration tests for command functionality and signal handling.

**Interrupt Handling Tests:**
- `test_interrupt.sh` - Basic SIGINT handling with 2-day sync
- `test_interrupt_long.sh` - Extended interrupt test with 30-day sync
- `test_interrupt_2d.sh` - Interrupt with database clearing to force sync

These tests verify that:
- Commands can be interrupted gracefully with Ctrl+C (SIGINT)
- Partial results are displayed even when interrupted
- Database state remains consistent after interruption
- Progress tracking works correctly during interrupts

**Running Shell Tests:**
```bash
# Basic interrupt test
./tests/test_interrupt.sh

# Long-running interrupt test
./tests/test_interrupt_long.sh

# Forced sync interrupt test
./tests/test_interrupt_2d.sh
```

### PHP Unit Tests

Direct service and functionality tests.

**Blocking Configuration Tests:**
- `test_blocking_config.php` - IP allowlist and CIDR range parsing
- `test_blocking_service.php` - Blocking decision logic and threshold validation

**Database and Range Detection Tests:**
- `test_gap_calculation.php` - Time gap detection and range calculation
- `test_cross_timeframe.php` - Cross-timeframe data handling

**Running PHP Tests:**
```bash
# Run specific test
php tests/test_blocking_config.php

# Run all PHP tests
for test in tests/test_*.php; do php "$test"; done
```

## Test Patterns

### Shell Script Test Pattern

Shell tests follow this structure:

```bash
#!/bin/bash
# Test description

# Setup (optional - clear database, set conditions)
sqlite3 ~/.solarwinds/logs.db "DELETE FROM logs WHERE ..."

# Execute command in background
php bin/solarwinds <command> <options> &
PID=$!

# Simulate user action (interrupt, wait, etc.)
sleep <duration>
kill -INT $PID

# Verify results
wait $PID
EXIT_CODE=$?
echo "Process exited with code: $EXIT_CODE"
```

**Key Elements:**
1. **Background execution** with `&` to get PID
2. **Signal simulation** using `kill -INT` or other signals
3. **Exit code verification** to ensure proper handling
4. **Output validation** (manual review of displayed results)

### PHP Test Pattern

PHP tests follow this structure:

```php
#!/usr/bin/env php
<?php
// Test description

require_once __DIR__ . '/../vendor/autoload.php';

use SolarWinds\Services\ServiceClass;

// Setup
$service = new ServiceClass(...);

// Test case 1
$result = $service->method($input);
assert($result === $expected, "Test case 1 failed");
echo "✓ Test case 1 passed\n";

// Test case 2
// ...

echo "\nAll tests passed!\n";
```

**Key Elements:**
1. **Direct service instantiation** without Symfony Console overhead
2. **Explicit assertions** with descriptive failure messages
3. **Visual feedback** with checkmarks for passing tests
4. **Edge case coverage** testing boundary conditions

## Creating New Tests

### For Command Integration

Create a shell script in `tests/`:

1. Make it executable: `chmod +x tests/test_myfeature.sh`
2. Add shebang: `#!/bin/bash`
3. Document what it tests in comments
4. Follow the shell script test pattern above
5. Test normal operation and edge cases
6. Verify exit codes and output format

### For Service Logic

Create a PHP script in `tests/`:

1. Make it executable: `chmod +x tests/test_myservice.php`
2. Add shebang: `#!/usr/bin/env php`
3. Require autoloader: `require_once __DIR__ . '/../vendor/autoload.php'`
4. Follow the PHP test pattern above
5. Test pure logic without I/O dependencies
6. Use assertions to validate behavior

## Test Philosophy

This project currently uses **manual test scripts** rather than a formal testing framework like PHPUnit. This approach:

**Advantages:**
- Low barrier to entry for adding tests
- No additional dependencies
- Direct execution without test runner
- Easy to understand and modify
- Suitable for integration testing

**Trade-offs:**
- No test runner automation
- Manual execution required
- Less sophisticated assertion library
- No code coverage reporting

**Future Enhancement:** The project may migrate to PHPUnit for more comprehensive testing. See [TODO.md](../docs/TODO.md) for planned improvements.

## Continuous Integration

Currently, tests are run manually during development. Future plans include:
- Automated test execution on commits
- Integration with GitHub Actions or similar CI
- Automated backward compatibility validation with original shell scripts

## Testing Best Practices

1. **Test one thing per test** - Keep tests focused on specific functionality
2. **Use descriptive names** - Test file names should indicate what they test
3. **Document expected behavior** - Comments should explain what should happen
4. **Test edge cases** - Don't just test the happy path
5. **Keep tests maintainable** - Tests should be easy to understand and modify
6. **Verify error handling** - Test failure paths, not just success
7. **Test integration points** - Verify services work together correctly

## Related Documentation

- [CONTRIBUTING.md](../docs/CONTRIBUTING.md) - Development workflow and testing strategy
- [TODO.md](../docs/TODO.md) - Planned testing framework improvements
- [README.md](../README.md) - Project overview and usage
