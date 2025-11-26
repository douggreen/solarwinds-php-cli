# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **BREAKING**: Standardized command-line options to consistent `--show-{category}-{value}` pattern
  - Attack type filters: `--xss-only` → `--show-type-xss`, `--sqli-only` → `--show-type-sqli`, etc.
  - Action filters: `--show-all-actions` → `--show-action-all` (plus new `--show-action-block-maybe`, `--show-action-review`, `--show-action-allow`)
  - Severity filters: `--min-severity=level` → `--show-severity-{low|medium|high|critical}`
  - Removed: `--summary-only` (summary is default behavior)

### Benefits
- Predictable, self-documenting flag names
- Tab-completion friendly (all filters grouped under `--show-*`)
- Easier to remember and discover options
- More flexible action filtering (can show specific combinations)
