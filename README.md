# SolarWinds Log Analysis Tools

Professional SolarWinds log analysis tools built with Symfony Console, migrated from a collection of shell scripts to provide better maintainability and extensibility.

## Documentation

- **README.md** (this file) - Project overview and usage for developers
- **[EXPLOITS.md](docs/EXPLOITS.md)** - Comprehensive security exploit detection and campaign analysis guide
- **[CONTRIBUTING.md](docs/CONTRIBUTING.md)** - Developer guidelines and architecture
- **[TODO.md](docs/TODO.md)** - Remaining features and development roadmap
- **[tests/README.md](tests/README.md)** - Testing patterns and test scripts
- **[CASE_STUDY.md](docs/CASE_STUDY.md)** - Lessons learned about AI-assisted development
- **[CLAUDE.md](CLAUDE.md)** - AI behavioral guide and development context

## Project Overview

This project provides a modern PHP/Symfony Console application for comprehensive SolarWinds log analysis and monitoring. The system offers powerful log analysis capabilities through a clean command-line interface with support for flexible querying, custom site configurations, and configurable command aliases.

## Installation

### Prerequisites
- PHP 8.1 or higher
- Composer
- SolarWinds API access

### Install Dependencies
```bash
composer install
```

**Note on Dependencies:** This project excludes `composer.lock` from version control to allow dependency versions to update within the constraints specified in `composer.json`. This is appropriate for a CLI tool where using the latest compatible versions is preferred over strict version locking.

### Configuration
Create a configuration file at `~/.solarwinds.yml`:

```yaml
# Required: Your SolarWinds API token
token: "your-solarwinds-api-token"

# Required: SolarWinds API endpoint (region-specific)
base_url: "https://api.na-01.cloud.solarwinds.com"

# Optional: Default behavior settings
progress: true         # Show progress bars during API calls
debug: false           # Enable debug output
validate: false        # Enable result validation
api_retention_days: 14 # API data retention limit (varies by license)

# Optional: Global CMS type (can be overridden per-site)
cms: drupal            # Valid values: drupal, wordpress, other, unknown

# Optional: Custom database location
database:
  path: ~/.solarwinds/logs.db
```

See [.solarwinds.yml.example](.solarwinds.yml.example) for a complete configuration example including site definitions, command aliases, and blocking configuration.

### Common Base URLs by Region

**North America (most common):**
```yaml
base_url: "https://api.na-01.cloud.solarwinds.com"
```

**Europe:**
```yaml
base_url: "https://api.eu-01.cloud.solarwinds.com"
```

### API Retention Limit

The `api_retention_days` configuration specifies how many days of historical data your SolarWinds API license retains. This varies by plan:

- **Standard plans**: Typically 14 days (2 weeks)
- **Enterprise plans**: May offer longer retention (30+ days)

**Why this matters:**

When you request data older than your retention limit (e.g., using `--1m` or `--all` when your limit is 14 days), the system will:

1. **Automatically clamp API queries** to within the retention window
2. **Mark older ranges** as `beyond_retention` to avoid futile API calls
3. **Still analyze local data** from your SQLite database if available

This prevents wasted API calls and confusing "3635 days of gap_between_syncs" messages when requesting historical data beyond your API's retention capability.

**Example:**
```yaml
api_retention_days: 14  # Your plan retains 14 days of data
```

Then running:
```bash
solarwinds exploits --1m  # Requests 30 days
```

Will automatically fetch only the last 14 days from the API (the maximum available), but will still analyze all 30 days if older data exists in your local database.

## CMS Configuration

The system can be configured with CMS type information to enable CMS-specific features and optimizations. This is particularly useful for Drupal and WordPress sites.

### Global CMS Configuration

Set a default CMS type for all sites:

```yaml
# Global CMS setting (applies to all sites unless overridden)
cms: drupal  # Valid values: drupal, wordpress, other, unknown
```

### Per-Site CMS Configuration

Override the global CMS setting for specific sites:

```yaml
cms: drupal  # Global default

sites:
  example.com:
    name: main
    label: Main Site
    cms: drupal  # Uses global default (can be explicit)

  blog.example.com:
    name: blog
    label: Company Blog
    cms: wordpress  # Different CMS for this site

  api.example.com:
    name: api
    label: API Server
    cms: other  # Non-CMS application
```

### CMS Type Benefits

- **Drupal**: Enables `--drupal` display mode for formatted PHP error output with file:line grouping
- **WordPress**: Future enhancements for WordPress-specific log patterns
- **Other/Unknown**: Standard log processing without CMS-specific features

## Site Configuration

This project supports configurable site mappings, allowing you to define organization-specific hostnames and aliases in your `~/.solarwinds.yml` file. Once configured, these sites can be used with the `--site` option (e.g., `--site=main`, `--site=blog`) for filtering log analysis to specific hosts.

### Configuring Sites

Add a `sites:` section to your configuration file:

```yaml
# ~/.solarwinds.yml
token: "your-api-token"
base_url: "https://api.na-01.cloud.solarwinds.com"
debug: false
progress: true

# Site mappings (customize for your organization)
sites:
  example.com:
    name: main
    label: Main Site
  blog.example.com:
    name: blog
    label: Company Blog
  app.example.com:
    name: app
    label: Web App
  api.example.com:
    name: api
    label: API Server
  docs.example.com:
    name: docs
    label: Documentation
```

### Site Configuration Structure

Each site entry has the following structure:

- **Hostname (key)**: The actual hostname to filter on in logs (e.g., `example.com`)
- **`name`**: Short alias used with the `--site` option (e.g., `main` → `--site=main`)
- **`label`**: Human-readable display name for output and descriptions

### How Site Configuration Works

1. **Command Line Options**: Each site can be referenced by its `name` field via the `--site` option:
   ```bash
   bin/solarwinds 5xx --site=main,blog  # Filters to main site and blog
   ```

1. **Log Filtering**: Uses the hostname (key) to filter SolarWinds logs:
   ```
   { json.orig_host:example.com } OR { json.orig_host:blog.example.com }
   ```

1. **Display Shortening**: Output uses the `label` for shortened, colorized display:
   ```
   Host: Main Site (instead of example.com)
   ```

### Usage Examples

```bash
# Filter to specific sites
bin/solarwinds 5xx --site=main,api              # Main site and API server only
bin/solarwinds 5xx --site=blog --cols=status    # Blog site with status codes
bin/solarwinds 5xx --site=docs --cols=country --time=1h  # Documentation site by country (1 hour)
```

### Adding Your Own Sites

To add sites for your organization:

1. Edit `~/.solarwinds.yml`
1. Add entries under the `sites:` section
1. Choose meaningful `name` values (used with `--site` option)
1. Set descriptive `label` values (shown in output)

Example for a different organization:
```yaml
sites:
  mycompany.com:
    name: main
    label: Main Site
  blog.mycompany.com:
    name: blog
    label: Company Blog
  app.mycompany.com:
    name: app
    label: Web App
```

These sites can then be used with the `--site` option (e.g., `--site=main`, `--site=blog`, `--site=app`) to filter logs for the respective hostnames.

### Benefits of Configurable Sites

- **Organization-specific**: Customize for your infrastructure
- **No code changes**: Add/remove sites through configuration only
- **Consistent filtering**: Uses actual hostnames from your logs
- **Clean output**: Shortened labels improve readability
- **Command completion**: All sites become available as command options

## IP Blocking Configuration

The `exploits` command can analyze traffic patterns and recommend IPs for blocking. Configure allowlists and trusted bots to prevent false positives.

### Allowlist Configuration

Specify IP addresses and CIDR ranges that should never be blocked:

```yaml
blocking:
  allowlist:
    - "10.0.0.0/8"          # Internal network
    - "192.168.1.0/24"      # Office network
    - "203.0.113.50"        # Specific trusted IP
```

### Trusted Bots Configuration

The system includes default trusted bots (Googlebot, bingbot, Slackbot, etc.). Add custom trusted bot patterns:

```yaml
blocking:
  trusted_bots:
    - "MyCustomBot"
    - "PartnerCrawler"
    - "MonitoringService"
```

### Blocking Thresholds

Customize the criteria for blocking recommendations. The system uses **timeframe-relative thresholds** that automatically scale based on the scan window:

```yaml
blocking:
  thresholds:
    # Scaled thresholds (auto-adjust for timeframe)
    min_requests_per_hour: 10         # 10 req/hr minimum for short scans
    min_requests_per_day: 70          # ~1000 over 2 weeks minimum
    urgent_requests_per_hour: 5000    # Immediate alert threshold

    # Duration as % of scan window
    min_duration_ratio: 0.1           # Must span 10% of window
                                      # (1.5min for 15min scan, 16.8hr for 1wk scan)

    # Behavior thresholds
    high_confidence_40x_ratio: 0.8    # 80% failure rate = scanning
    exploit_ratio_threshold: 0.7      # 70% exploit patterns = attack

    # Single-event filtering
    single_event_threshold_hours: 1   # Bursts <1hr = single event
    single_event_critical_volume: 10000  # Unless >10k reqs + critical
```

**How Scaling Works:**
- **Short scans** (--15m, --1h): Uses hourly rate threshold (10 req/hr = 150 requests in 15 minutes)
- **Long scans** (--1d, --1w): Uses daily rate threshold (70 req/day = 490 requests in 1 week)
- **Duration**: Requires attack to span at least 10% of scan window (prevents single-event bursts)

**Adjusting Thresholds:**
- **Increase `min_requests_per_hour`** (e.g., 50) for higher-traffic sites to reduce noise in short scans
- **Decrease `min_requests_per_hour`** (e.g., 5) for low-traffic sites to catch smaller attacks
- **Adjust `min_duration_ratio`** (e.g., 0.2 = 20%) to require more sustained attacks
- **Tune `high_confidence_40x_ratio`** for sensitivity (lower = more strict, e.g., 0.6 = 60%+ failures)

**Default Trusted Bots:**
- Googlebot, bingbot, DuckDuckBot (search engines)
- Slackbot, facebookexternalhit, Twitterbot (social media)
- LinkedInBot, WhatsApp, TelegramBot (messaging)
- Discordbot, Applebot (other services)
- Baiduspider, YandexBot (international search engines)

## Command Aliases

This project supports configurable command aliases that allow you to create custom shortcuts and replace some built-in commands with more flexible alternatives. Aliases are defined in your `~/.solarwinds.yml` file and appear as real commands in the application.

### Core Commands

The application includes 4 core commands that provide the foundation for all log analysis:

- **`bot`** - Analyze bot and crawler traffic with dynamic user agent filtering
- **`exploits`** - Advanced security threat detection with pattern-based analysis
- **`search`** - General-purpose log search with flexible query syntax
- **`status`** - HTTP status code analysis with built-in shortcuts

### Configuring Aliases

Add aliases to your `~/.solarwinds.yml` file under the `aliases:` section:

```yaml
aliases:
  errors: search "error" --cols=status --time=1h
  quickbot: bot --time=1h
  404s: status --filter-status-code=404 --cols=host,path
  mysite: search --sql-where="message->>'$.orig_host' = 'example.com'" --cols=host,status
```

### Recommended Aliases

These aliases provide the same functionality as commands from the original shell scripts:

```yaml
aliases:
  # HTTP error analysis
  5xx: status --filter-status-code=5 --time=1d --cols=host,path
  500image: search --filter-status-code=500 --filter-path=/sites/default/files --cols=host,path --time=1d

  # Request analysis
  posts: search --filter-path=/sites/default/files --cols=host,path --time=1d
  login: search "Login attempt failed" --drupal --vars=ip,post.name --time=1d --cols=host

  # Geographic analysis
  country: search --cols=country --time=15m

  # Security and blocking analysis
  ban: status --filter-status-code=403 --cols=host,ip --time=1d

  # Security threat detection (with client-side filtering)
  xss: 'search --filter="req_uri:\?.*</?script" --cols=host,path --time=1d'
  sql-injection: 'search --filter="req_uri:\?.*(select[\s\*\(]+|insert[\s\*\(]+|update[\s\*\(]+|delete[\s\*\(]+|union[\s\*\(]+|drop[\s\*\(]+)" --cols=host,path --time=1d'
  pentest: 'search --filter="req_uri:\?.*(select[\s\*\(]|insert[\s\(]into|update[\s\(].*set|delete[\s\(]from|union[\s\(]select|drop[\s\(]table|or[\s]+\w+[\s]*=|and[\s]+\w+[\s]*=|'\''[\s]*--|--[\s]*$|\d'\''[\s]*(or|and)|</?script)" --cols=host,path --time=1d'

  # Drupal/PHP error analysis
  cron: search "cron" --drupal --time=1d --cols=host
  drupal: search "logger" --drupal --time=1d --cols=host
  drupal-php: search "php" --drupal --vars=file,line,function --time=1d --cols=host
  drupal-error: search "Error" --drupal --time=1d --cols=host,path
  drupal-warning: search "Warning" --drupal --time=1d --cols=host,path
  drupal-notice: search "Notice" --drupal --time=1d --cols=host

  # Convenience shortcuts
  errors: search "error" --cols=status --time=1h
  quickbot: bot --time=1h
  404s: status --filter-status-code=404 --cols=host,path
```

### How Aliases Work

1. **Alias arguments come first**: `errors: search "error" --cols=status --time=1h`
1. **User arguments are appended**: `solarwinds errors --cols=host` becomes `search "error" --cols=status --time=1h --cols=host`
1. **Validation applies**: The target command's validation prevents conflicting options
1. **No override protection**: Aliases cannot replace built-in commands (`bot`, `search`, `status`)

### Alias Examples

```bash
# Using recommended aliases
solarwinds 5xx --time=2h                    # HTTP 5xx errors (last 2 hours)
solarwinds posts --cols=country             # POST requests by country
solarwinds login --time=1h                  # Failed logins (last hour)

# Drupal/PHP error analysis
solarwinds drupal --time=2h                 # All PHP errors (last 2 hours)
solarwinds drupal-error --time=1d           # PHP errors only (last day)
solarwinds drupal-warning --time=1h         # PHP warnings (last hour)
solarwinds drupal-notice --time=1d          # PHP notices (last day)

# Security threat detection
solarwinds xss --time=2h                    # XSS attack detection (last 2 hours)
solarwinds sql-injection --time=1d          # SQL injection detection (last day)
solarwinds pentest --time=1w                # Combined security scan (last week)

# Custom aliases
solarwinds errors --cols=country            # Error patterns by country
solarwinds quickbot --cols=country          # Bot traffic by country
solarwinds 404s --time=1d                   # 404 errors (last day)
```

### Make Binary Executable
```bash
chmod +x bin/solarwinds
```

## Usage

### List Available Commands
```bash
bin/solarwinds list
```

### Get Help for a Command
```bash
bin/solarwinds status --help
```

### Examples (Core Commands)

```bash
# Status code analysis
bin/solarwinds status                                           # All status codes (last day)
bin/solarwinds status --filter-status-code=404 --time=15m      # 404 errors from last 15 min
bin/solarwinds status --filter-status-code=5 --cols=country    # 5xx errors by country

# General search
bin/solarwinds search "error"                                   # Text search for "error"
bin/solarwinds search "timeout" --cols=host                     # Text search with host column

# Bot analysis
bin/solarwinds bot                                              # Bot traffic (last 15m)
bin/solarwinds bot crawler --cols=host                          # Crawler traffic by host
```

### Examples (Using Aliases)

```bash
# Using recommended aliases (if configured)
bin/solarwinds 5xx --time=2h          # HTTP 5xx errors (last 2 hours)
bin/solarwinds posts --cols=country   # POST requests by country
bin/solarwinds login --time=1h        # Failed logins (last hour)
```

## Security Threat Detection

The `exploits` command provides comprehensive security threat detection and campaign analysis. For detailed information about exploit detection, automated monitoring, and blocking recommendations, see **[EXPLOITS.md](docs/EXPLOITS.md)**.

### Quick Start

```bash
# Analyze recent activity
solarwinds exploits --time=1h                      # Last hour
solarwinds exploits --time=15m --show-severity=high  # Recent critical threats

# Automated monitoring (cron)
*/15 * * * * /path/to/solarwinds exploits --time=15m >> /var/log/solarwinds/realtime.log 2>&1
```

The system detects 14+ attack types (XSS, SQLi, RCE, LFI, etc.), groups them into campaigns, performs automatic deep-dive analysis for suspicious IPs, and provides actionable blocking recommendations with confidence levels.

**See [EXPLOITS.md](docs/EXPLOITS.md) for:**
- Complete attack pattern reference
- Campaign analysis system
- Blocking recommendation engine
- Automated monitoring setup
- Configuration and threshold tuning
- Database schema and queries

## Features

### Time Options
Supports comprehensive time range options:
- Relative: `--5m`, `--1h`, `--1d`, `--1w`, etc.
- Specific days: `--yesterday`, `--2D` (2 days ago), through `--14D`
- Custom ranges: `--since="2 hours ago" --until="now"`

### Display Options
- `--cols=COLUMNS` - Comma-separated list of columns to display: `status`, `host`, `path`, `ua`, `ip`, `country`, `region`
  - Example: `--cols=status,host,path`
- `--drupal` - Format PHP/Drupal watchdog errors with file:line grouping
- `--vars[=FIELDS]` - Show variable replacements from Drupal watchdog logs. Supports:
  - `--vars` - Show all variables
  - `--vars=user,ip` - Show specific Drupal variables
  - `--vars=post.name,post.pass` - Access nested JSON fields using dot notation
  - Deep array references work with any nested structure (e.g., `geoip.country_code2`)
- `--filter=PATTERN` - Client-side regex filtering in format "field:regex" (can be used multiple times, AND logic)

### Filter Options
- `--filter-status-code=CODE` - Filter by HTTP status code (e.g., `404`, `5` for 5xx)
- `--filter-country=CODE` - Filter by country code (e.g., `US`, `GB`)
- `--filter-city=CITY` - Filter by city name
- `--filter-ip=ADDRESS` - Filter by IP address (single or comma-separated)
- `--filter-path=PATH` - Filter by request path
- `--filter-user-agent=PATTERN` - Filter by user agent pattern

### Client-Side Filtering

The `--filter` option enables post-processing of API results with regex patterns to narrow down results that can't be filtered by the SolarWinds API directly.

**Format:** `--filter="field:regex"`

**Examples:**
```bash
# Filter for script tags in URLs (XSS detection)
bin/solarwinds search "script" --filter="req_uri:\?.*<script" --cols=host,path

# Filter for SQL keywords followed by operators
bin/solarwinds search "select" --filter="req_uri:select[\s\*\(]" --cols=host,path

# Multiple filters (AND logic)
bin/solarwinds search "error" --filter="req_uri:/admin" --filter="resp_status:50[0-9]" --cols=host
```

**Features:**
- URL-decodes values before matching (catches encoded attacks like `%3Cscript%3E`)
- Supports multiple filters with AND logic (all must match)
- Works with any field in the log data (req_uri, resp_status, etc.)
- Especially useful for security analysis (XSS, SQL injection detection)
- Shows filtered count in output: "Found 98 results (filtered from 629 results)"

### Database Storage & Auto-Sync

**Automatic Database Storage** - All fetched logs are permanently stored in a SQLite database for fast querying.

**Intelligent Range Detection** - The system automatically detects missing time ranges in the database:
- Queries the database for existing data in the requested time range
- Identifies ranges before existing data (historical logs)
- Identifies ranges after existing data (recent logs)
- Fetches ONLY the missing time ranges from the API
- Merges new data with existing database records

**Auto-Sync Behavior:**
```bash
solarwinds exploits --1h           # Fetches last hour, stores in database
# ... wait a few minutes ...
solarwinds exploits --1d           # Only fetches the missing ~23 hours
                                   # (already has the recent hour from previous query)
```

**Benefits:**
- :white_check_mark: No duplicate API requests for overlapping time ranges
- :white_check_mark: Fast queries for previously fetched data
- :white_check_mark: Automatic historical backfill
- :white_check_mark: Scales to months of data

## Original Shell Scripts

The original shell scripts are preserved in the project for reference and comparison during migration. These provide the authoritative specification for behavior, validation, and output formatting.

## Attribution

**Architecture and Direction:** Doug Green (douggreen@douggreenconsulting.com)
**Implementation:** Developed collaboratively using Claude AI assistance

## Benefits of the Architecture

- **Professional CLI experience** with Symfony Console
- **Real-time progress bars** during API calls
- **Robust HTTP client** with Guzzle (retries, timeouts, etc.)
- **YAML configuration** parsing and site mapping
- **Type safety** and comprehensive error handling
- **Extensible architecture** for new commands and aliases
- **Composer dependency management**
- **Reduced code duplication** through abstract base class inheritance
