# SolarWinds Log Analysis Tools

Professional SolarWinds log analysis tools built with Symfony Console, providing powerful log analysis capabilities through a clean command-line interface.

## Installation

### Prerequisites
- PHP 8.1 or higher
- Composer
- SolarWinds API access

### Install Dependencies
```bash
composer install
```

### Make Binary Executable
```bash
chmod +x bin/solarwinds
```

## Configuration

Create a configuration file at `~/.solarwinds.yml`:

```yaml
# Required: Your SolarWinds API token
token: "your-solarwinds-api-token"

# Required: SolarWinds API endpoint (region-specific)
base_url: "https://api.na-01.cloud.solarwinds.com"

# Optional: Default behavior settings
progress: true    # Show progress bars during API calls
debug: false      # Enable debug output
validate: false   # Enable result validation
```

### Common Base URLs by Region

**North America (most common):**
```yaml
base_url: "https://api.na-01.cloud.solarwinds.com"
```

**Europe:**
```yaml
base_url: "https://api.eu-01.cloud.solarwinds.com"
```

## Site Configuration

Configure organization-specific hostnames and aliases in your `~/.solarwinds.yml` file. Once configured, these sites become available as command-line options (e.g., `--main`, `--blog`) for filtering log analysis to specific hosts.

### Adding Sites

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

Each site entry has:
- **Hostname (key)**: The actual hostname to filter on in logs (e.g., `example.com`)
- **`name`**: Short alias used for command line options (e.g., `main` → `--main` flag)
- **`label`**: Human-readable display name for output

### Usage Examples

```bash
# Filter to specific sites
bin/solarwinds status --main --api           # Main site and API server only
bin/solarwinds status --blog --404          # Blog site 404 errors
bin/solarwinds search "error" --docs --1h   # Documentation site errors (1 hour)
```

## Command Aliases

Create custom command shortcuts in your `~/.solarwinds.yml` file. Aliases appear as real commands and provide convenient access to common analysis patterns.

### Configuring Aliases

Add aliases under the `aliases:` section:

```yaml
aliases:
  # HTTP error analysis
  5xx: status --5 --1d --host --path
  404s: status --404 --host --path

  # Request analysis
  posts: search --query="{ json.req_method:POST } { json.resp_status:200 } -/sites/default/files" --host --path --day

  # Security analysis
  ban: status --403 --host --ip --day

  # Convenience shortcuts
  errors: search "error" --status --1h
  quickbot: bot --1h
```

### Using Aliases

```bash
# Using configured aliases
bin/solarwinds 5xx --2h               # HTTP 5xx errors (last 2 hours)
bin/solarwinds posts --country        # POST requests by country
bin/solarwinds 404s --day             # 404 errors (last day)
bin/solarwinds ban --1h               # Blocked requests (last hour)
```

## Usage

### Available Commands

```bash
bin/solarwinds list                   # List all available commands
bin/solarwinds status --help          # Get help for a specific command
```

### Core Commands

- **`status`** - HTTP status code analysis with built-in shortcuts
- **`search`** - General-purpose log search with flexible query syntax
- **`bot`** - Analyze bot and crawler traffic with dynamic user agent filtering

### Examples

```bash
# Status code analysis
bin/solarwinds status                  # All status codes (last day)
bin/solarwinds status --404 --15m     # 404 errors from last 15 minutes
bin/solarwinds status --5 --country   # 5xx errors by country

# General search
bin/solarwinds search "error"         # Text search for "error"
bin/solarwinds search --query="{ json.resp_status:404 }" --host # JSON query

# Bot analysis
bin/solarwinds bot                     # Bot traffic (last 15 minutes)
bin/solarwinds bot crawler --host     # Crawler traffic by host
```

## Features

### Time Options
Supports comprehensive time range options:
- **Relative**: `--5m`, `--15m`, `--1h`, `--2h`, `--1d`, `--1w`, etc.
- **Specific days**: `--yesterday`, `--2D` (2 days ago), through `--14D`
- **Custom ranges**: `--since="2 hours ago" --until="now"`

### Display Options
- `--status` - Show HTTP status codes with color coding
- `--host` - Show originating hosts (with shortening when sites configured)
- `--path[=N]` - Show request paths (optionally truncated to N segments)
- `--ua` - Show user agents (with bot highlighting)
- `--ip` - Show IP addresses
- `--country` - Show country information
- And more...

### Caching System
- **Automatic caching** for queries taking >60 seconds
- **Time-based expiration** (10% of query time range by default)
- **Configurable cache duration**: `--cached=5m`, `--cached=2h`
- **Infinite cache mode**: `--cached=0`

## Troubleshooting

### Common Issues

**Authentication Errors**
- Verify your API token is correct in `~/.solarwinds.yml`
- Check that your base URL matches your SolarWinds region

**No Results**
- Try broader time ranges (e.g., `--1d` instead of `--15m`)
- Verify site configuration matches your actual hostnames
- Use `--debug` flag to see the actual query being executed

**Performance Issues**
- Use more specific queries to reduce result sets
- Leverage caching with `--cached` for repeated queries
- Consider shorter time ranges for initial analysis

### Getting Help

```bash
bin/solarwinds list                    # See all commands
bin/solarwinds COMMAND --help          # Get help for specific command
```

## Documentation

- **docs/CONTRIBUTING.md** - Developer guidelines and architecture
- **docs/CASE_STUDY.md** - Lessons learned about AI-assisted development
- **docs/CLAUDE-MUST-READ-FIRST.md** - AI development context and guidelines
- **docs/TODO.md** - Additional planned features

## Attribution

**Architecture and Direction:** Doug Green (douggreen@douggreenconsulting.com)
**Implementation:** Developed collaboratively using Claude AI assistance
