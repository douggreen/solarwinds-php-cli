# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **BREAKING**: Simplified command-line option syntax to `--option=value` format
  - Attack type filters: `--show-type=xss`, `--show-type=sqli`, etc.
  - Action filters: `--show-action=block-maybe`, `--show-action=review`, `--show-action=allow`, `--show-action=all`
  - Severity filters: `--show-severity=low`, `--show-severity=medium`, `--show-severity=high`, `--show-severity=critical`
  - Site filters: `--site=mtc`, `--site=abag`, etc. (comma-separated: `--site=mtc,abag`)
  - Display columns: `--cols=host,path,status` (replaces `--host`, `--path`, `--status`, etc.)
  - Filter options: All filters now use `--filter-*` prefix for consistency and discoverability
    - `--filter-status-code=404` (replaces `--status-code-filter`, `--code`, `--status-code`)
    - `--filter-country=US` (replaces `--country-filter`)
    - `--filter-city=London` (replaces `--city-filter`)
    - `--filter-ip=1.2.3.4` (replaces `--ip-filter`)
    - `--filter-path=/api` (replaces `--path-filter`)
    - `--filter-user-agent=bot` (replaces `--user-agent-filter`)
    - `--filter-min-count=10` (replaces `--min-count`, with clearer description)
  - Time shortcuts: `--2w` → `--time=2w` (convenient shorthand)
  - Time range syntax: Unified `--time` option with `..` range separator
    - `--time=1h` → last hour (shorthand for `1h..now`)
    - `--time=2d..1d` → from 2 days ago to 1 day ago
    - `--time=2024-11-20..2024-11-25` → date range
    - Removed: `--since` and `--until` (use range syntax instead)
    - Removed aliases: `hour`, `day`, `week` (use explicit: `1h`, `1d`, `1w`)
    - Special values still supported: `yesterday`, `all`
  - Removed: `--summary-only` (summary is default behavior)

### Added
- Upgraded to Symfony Console 7.3.6
- Help output reduced from 770 lines to 141 lines (82% reduction)
- Multi-value option support for filters (e.g., `--show-type=xss --show-type=sqli`)

### Benefits
- Clean, predictable syntax
- Tab-completion friendly
- Simpler to remember and use
- Time shortcuts for frequently-used patterns
