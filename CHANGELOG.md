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
  - Status code filters: `--code=404`, `--code=5`, etc. (replaces `--404`, `--5xx`, etc.)
  - Time shortcuts: `--2w` → `--time=2w`, `--hour` → `--time=hour` (convenient shorthand)
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
