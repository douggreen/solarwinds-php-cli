# SolarWinds Log Analysis Tools

Professional SolarWinds log analysis tools built with Symfony Console, migrated from a collection of shell scripts to provide better maintainability and extensibility.

## Documentation

- **README.md** (this file) - Project overview and usage for developers
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

This project supports configurable site mappings, allowing you to define organization-specific hostnames and aliases in your `~/.solarwinds.yml` file. Once configured, these sites become available as command-line options (e.g., `--main`, `--blog`) for filtering log analysis to specific hosts.

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
- **`name`**: Short alias used for command line options (e.g., `main` → `--main` flag)
- **`label`**: Human-readable display name for output and descriptions

### How Site Configuration Works

1. **Command Line Options**: Each site generates a command option based on the `name` field:
   ```bash
   bin/solarwinds 5xx --main --blog  # Filters to main site and blog
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
bin/solarwinds 5xx --main --api           # Main site and API server only
bin/solarwinds 5xx --blog --status       # Blog site with status codes
bin/solarwinds 5xx --docs --country --1h  # Documentation site by country (1 hour)
```

### Adding Your Own Sites

To add sites for your organization:

1. Edit `~/.solarwinds.yml`
1. Add entries under the `sites:` section
1. Choose meaningful `name` values (used for --flags)
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

This generates `--main`, `--blog`, and `--app` command options that filter logs for the respective hostnames.

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

Customize the criteria for blocking recommendations. These are absolute thresholds (not timeframe-relative) that you adjust based on your site's traffic characteristics:

```yaml
blocking:
  thresholds:
    min_requests: 100                 # Minimum exploit attempts (absolute count)
    min_duration_hours: 2             # Attack must span at least this duration
    high_confidence_40x_ratio: 0.8    # 80%+ failed requests = high confidence (0.0-1.0)
```

**Adjusting Thresholds:**
- **Increase `min_requests`** (e.g., 500) for higher-traffic sites to reduce noise
- **Decrease `min_requests`** (e.g., 25) for low-traffic sites to catch smaller attacks
- **Increase `min_duration_hours`** (e.g., 6) to require more sustained attacks
- **Adjust `high_confidence_40x_ratio`** to tune sensitivity (lower = more strict, e.g., 0.6 = 60%+ failures)

**Note:** These thresholds are independent of query timeframe. An attack with 100 requests over 2 hours triggers the same threshold whether you're analyzing the last day or last week. For timeframe-relative filtering, use the `--min-requests` command option with rate syntax (e.g., `--min-requests=3/s`).

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
  errors: search "error" --status --1h
  quickbot: bot --1h
  404s: status --404 --host --path
  mysite: search --query="{ json.orig_host:example.com }" --host --status
```

### Recommended Aliases

These aliases provide the same functionality as commands from the original shell scripts:

```yaml
aliases:
  # HTTP error analysis
  5xx: status --5 --1d --host --path
  500image: search --query="{ json.resp_status:500 } /sites/default/files" --host --path --day

  # Request analysis
  posts: search --query="{ json.req_method:POST } { json.resp_status:200 } -/sites/default/files" --host --path --day
  login: search "Login attempt failed" --drupal --vars=ip,post.name --day --host

  # Geographic analysis
  country: search --query="{ json.resp_status:-30 } { json.resp_status:-40 } { json.resp_status:-90 } -/sites/default/files" --country --15m

  # Security and blocking analysis
  ban: status --403 --host --ip --day

  # Security threat detection (with client-side filtering)
  xss: 'search --query="{ json.req_uri:? } { json.req_uri:script } { json.resp_status:-30 } { json.resp_status:-40 } { json.resp_status:-90 }" --filter="req_uri:\?.*</?script" --host --path --day'
  sql-injection: "search --query=\"{ json.req_uri:? } ( { json.req_uri:insert } OR { json.req_uri:update } OR { json.req_uri:delete } OR { json.req_uri:select } OR { json.req_uri:union } OR { json.req_uri:drop } ) { json.resp_status:-30 } { json.resp_status:-40 } { json.resp_status:-90 }\" --filter=\"req_uri:\\?.*(select[\\s\\*\\(\\+]|insert[\\s\\*\\(\\+]|update[\\s\\*\\(\\+]|delete[\\s\\*\\(\\+]|union[\\s\\*\\(\\+]|drop[\\s\\*\\(\\+])\" --host --path --day"
  pentest: "search --query=\"{ json.req_uri:? } ( { json.req_uri:insert } OR { json.req_uri:update } OR { json.req_uri:delete } OR { json.req_uri:select } OR { json.req_uri:union } OR { json.req_uri:drop } OR { json.req_uri:script } ) { json.resp_status:-30 } { json.resp_status:-40 } { json.resp_status:-90 }\" --filter=\"req_uri:\\?.*(select[\\s\\*\\(]|insert[\\s\\(]into|update[\\s\\(].*set|delete[\\s\\(]from|union[\\s\\(]select|drop[\\s\\(]table|or[\\s]+\\w+[\\s]*=|and[\\s]+\\w+[\\s]*=|'\\s*--|--\\s*$|\\d'\\s*(or|and)|</?script)\" --host --path --day"

  # Drupal/PHP error analysis
  cron: search "{ json.type:cron }" --drupal --1d --host
  drupal: search "{ program:logger }" --drupal --1d --host
  drupal-php: search "{ json.type:php }" --drupal --vars=file,line,function --1d --host
  drupal-error: search "{ json.severity:Error }" --drupal --1d --host --path
  drupal-warning: search "{ json.severity:Warning }" --drupal --1d --host --path
  drupal-notice: search "{ json.severity:Notice }" --drupal --1d --host

  # Convenience shortcuts
  errors: search "error" --status --1h
  quickbot: bot --1h
  404s: status --404 --host --path
```

### How Aliases Work

1. **Alias arguments come first**: `errors: search "error" --status --1h`
1. **User arguments are appended**: `solarwinds errors --host` becomes `search "error" --status --1h --host`
1. **Validation applies**: The target command's validation prevents conflicting options
1. **No override protection**: Aliases cannot replace built-in commands (`bot`, `search`, `status`)

### Alias Examples

```bash
# Using recommended aliases
solarwinds 5xx --2h                    # HTTP 5xx errors (last 2 hours)
solarwinds posts --country             # POST requests by country
solarwinds login --1h                  # Failed logins (last hour)

# Drupal/PHP error analysis
solarwinds drupal --2h                 # All PHP errors (last 2 hours)
solarwinds drupal-error --1d           # PHP errors only (last day)
solarwinds drupal-warning --1h         # PHP warnings (last hour)
solarwinds drupal-notice --day         # PHP notices (last day)

# Security threat detection
solarwinds xss --2h                    # XSS attack detection (last 2 hours)
solarwinds sql-injection --1d          # SQL injection detection (last day)
solarwinds pentest --week              # Combined security scan (last week)

# Custom aliases
solarwinds errors --country            # Error patterns by country
solarwinds quickbot --country          # Bot traffic by country
solarwinds 404s --day                  # 404 errors (last day)
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
bin/solarwinds status                  # All status codes (last day)
bin/solarwinds status --404 --15m     # 404 errors from last hour
bin/solarwinds status --5 --country   # 5xx errors by country

# General search
bin/solarwinds search "error"         # Text search for "error"
bin/solarwinds search --query="{ json.resp_status:404 }" --host # JSON query

# Bot analysis
bin/solarwinds bot                     # Bot traffic (last 15m)
bin/solarwinds bot crawler --host     # Crawler traffic by host
```

### Examples (Using Aliases)

```bash
# Using recommended aliases (if configured)
bin/solarwinds 5xx --2h               # HTTP 5xx errors (last 2 hours)
bin/solarwinds posts --country        # POST requests by country
bin/solarwinds login --1h             # Failed logins (last hour)
```

## Automated Monitoring with Cron

The `exploits` command includes intelligent campaign analysis that builds historical patterns and detects threats in real-time. For optimal threat detection, set up both long-term and short-term monitoring:

### Recommended Cron Setup

**Long-term Analysis** (builds historical knowledge):
```bash
# Daily analysis - runs at 2 AM
0 2 * * * /path/to/solarwinds exploits --1d >> /var/log/solarwinds/daily.log 2>&1

# Weekly analysis - runs Sunday 3 AM
0 3 * * 0 /path/to/solarwinds exploits --1w >> /var/log/solarwinds/weekly.log 2>&1
```

**Real-time Monitoring** (detects active threats):
```bash
# Every 15 minutes - check for active attacks
*/15 * * * * /path/to/solarwinds exploits --15m --min-severity=high >> /var/log/solarwinds/realtime.log 2>&1

# Or every 5 minutes for faster detection of critical threats
*/5 * * * * /path/to/solarwinds exploits --5m --min-severity=critical >> /var/log/solarwinds/realtime.log 2>&1
```

### How It Works

The campaign analysis system automatically:
- **Scales thresholds** based on timeframe (short scans use rate-based thresholds, long scans use volume-based)
- **Checks historical patterns** to identify known bad actors from previous scans
- **Performs deep-dive analysis** for new suspicious IPs (queries full history automatically)
- **Saves all results** to database for future reference
- **Filters noise** (single-event bursts, trusted bots like Google crawlers, etc.)

**Alert Integration Example:**

Pipe urgent alerts to your notification system:

```bash
#!/bin/bash
# /usr/local/bin/exploit-monitor.sh

URGENT=$(/path/to/solarwinds exploits --15m --json | jq -r 'select(.confidence=="urgent") | .ip')

if [ -n "$URGENT" ]; then
  echo "URGENT: High-rate attack from IP(s): $URGENT" | mail -s "Security Alert" ops@example.com
fi
```

Then in cron:
```bash
*/15 * * * * /usr/local/bin/exploit-monitor.sh
```

### Threshold Configuration

You can customize detection thresholds in `~/.solarwinds.yml`:

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

    # Trusted IP prefixes (auto-excluded)
    trusted_ip_prefixes:
      - "66.249."   # Google
      - "64.233."   # Google
      - "66.102."   # Google
      - "74.125."   # Google
      - "142.250."  # Google
```

## Features

### Time Options
Supports comprehensive time range options:
- Relative: `--5m`, `--1h`, `--1d`, `--1w`, etc.
- Specific days: `--yesterday`, `--2D` (2 days ago), through `--14D`
- Custom ranges: `--since="2 hours ago" --until="now"`

### Display Options
- `--status` - Show HTTP status codes with color coding
- `--host` - Show originating hosts (with shortening)
- `--path[=N]` - Show request paths (optionally truncated to N segments)
- `--ua` - Show user agents (with bot highlighting)
- `--ip` - Show IP addresses
- `--country` - Show country information
- `--drupal` - Format PHP/Drupal watchdog errors with file:line grouping
- `--vars[=FIELDS]` - Show variable replacements from Drupal watchdog logs. Supports:
  - `--vars` - Show all variables
  - `--vars=user,ip` - Show specific Drupal variables
  - `--vars=post.name,post.pass` - Access nested JSON fields using dot notation
  - Deep array references work with any nested structure (e.g., `geoip.country_code2`)
- `--filter=PATTERN` - Client-side regex filtering in format "field:regex" (can be used multiple times, AND logic)
- And more...

### Client-Side Filtering

The `--filter` option enables post-processing of API results with regex patterns to narrow down results that can't be filtered by the SolarWinds API directly.

**Format:** `--filter="field:regex"`

**Examples:**
```bash
# Filter for script tags in URLs (XSS detection)
bin/solarwinds search "script" --filter="req_uri:\?.*<script"

# Filter for SQL keywords followed by operators
bin/solarwinds search "select" --filter="req_uri:select[\s\*\(]"

# Multiple filters (AND logic)
bin/solarwinds search "error" --filter="req_uri:/admin" --filter="resp_status:50[0-9]"
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
