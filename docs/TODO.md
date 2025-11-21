# SolarWinds Log Analysis Tools - TODO

This document tracks the remaining work to complete the migration and enhancement of the SolarWinds log analysis system.

## Next Steps

**Current Priority:**
1. Implement remaining commands
2. Research and implement testing framework

## Remaining Command Implementations

The following commands require new core implementations as they cannot be effectively replaced by aliases:

### **1. page (PageCommand)**
- **Purpose**: General page access analysis excluding static files and 4xx errors
- **Query Pattern**: `( -/sites/default/files )` - excludes static files, includes all status codes
- **Default Display**: Detailed page access logs
- **Required Argument**: Page/path to analyze
- **Validation**: Page argument is required
- **Example Usage**: `page --1h /login`, `page --day /admin --status`

### **2. pagebyip (PageByIpCommand)**
- **Purpose**: Web request activity analysis for specific IP addresses or IP lists
- **Query Pattern**: `{ json.client_ip:IP_QUERY } { json.resp_status:-40 } -/sites/default/files`
- **IP Handling**: Single IP or comma/space-separated lists with automatic OR syntax conversion
- **Dynamic Output**: Pages for single IP, host summary for multiple IPs
- **Required Argument**: IP address or IP list
- **Validation**: IP argument is required
- **Example Usage**: `pagebyip --1h 192.168.1.100`, `pagebyip --day '10.1.1.1,10.1.1.2'`

### **3. pingdom (PingdomCommand)**
- **Purpose**: Pingdom monitoring service error analysis
- **Query Pattern**: `json.req_user_agent:pingdom AND -json.resp_status:200 AND -json.resp_status:301 AND -json.resp_status:304`
- **Focus**: Non-successful responses from Pingdom monitoring
- **Default Display**: Custom format with host,status,uri,timestamp
- **Example Usage**: `pingdom --day`, `pingdom --1h --status`

### **4. importer (ImporterCommand)**
- **Purpose**: Content import activity analysis
- **Query Pattern**: `{ json.type:mtc_importer } { json.severity:Info }`
- **Default Display**: ips-only format (IP address analysis)
- **Default Time**: 1 day
- **Example Usage**: `importer --1h`, `importer --day --status`

### **5. antibot (AntibotCommand)**
- **Purpose**: Anti-bot measure effectiveness analysis
- **Query Pattern**: `{ json.resp_status:429 } OR { json.resp_status:503 }`
- **Focus**: Rate limiting and bot blocking responses
- **Default Display**: host-status format
- **Default Time**: 1 hour
- **Example Usage**: `antibot --1h`, `antibot --day --country`

## Implementation Priority

**Phase 1 (Simple):** page, importer, antibot
- Follow established patterns closely
- Standard query building and validation

**Phase 2 (Medium):** pagebyip, pingdom
- Require custom output formatting
- More complex query logic

**Note:** php-error was implemented via the `--drupal` display flag and aliases rather than as a separate command. pentest was replaced by the xss, sql-injection, and pentest aliases, and superseded by the comprehensive exploits command.

## ExploitsCommand Enhancements

### Current Issues to Review

1. **Review SCANNER Detection False Positives**
   - IAHarvester (Internet Archive) being flagged as SCANNER for legitimate image requests
   - Example: `/sites/default/files/styles/whole_max/public/images/580extensionmap.jpg.webp?itok=...&cb=...`
   - User-Agent: `IAHarvester/1.0 (+https://archive-it.org/organizations/...)`
   - Response: 100% successful (2xx), no failed requests
   - Issue: 12 requests out of 12,831 total flagged as scanner activity
   - Need to investigate why these specific paths trigger SCANNER detection
   - Possible causes:
     - Query parameter patterns matching scanner regex?
     - Overly broad scanner detection patterns?
     - Should trusted bots (like IAHarvester) bypass scan detection entirely?

### Add functionality from ThreatsCommand

1. **Add DDoS Detection (`detectDDoSPatterns()`)**
   - Detect coordinated exploit campaigns
   - Group by host + target path + time window (1-minute windows)
   - Look for 10+ coordinating IPs attacking same target
   - Detect 1000+ requests in window at 100+ requests/second
   - Would help identify distributed exploit campaigns vs single-source attacks

2. **Add Advanced Threat Classification (`classifyThreatType()`)**
   - More sophisticated classification than current `classifyIpBehavior()`
   - Uses URI duplication ratio to detect "Exploit Probing" (same request repeated)
   - Uses parameter scanning ratio to detect "Parameter Scanner" (same path, varying params)
   - Uses POST ratio analysis to detect "Brute-force Attack"
   - Uses request rate thresholds to detect "Load Attack", "DoS Attack", "Aggressive Bot"
   - Provides more specific threat types:
     - Exploit Probing
     - Parameter Scanner
     - Load Attack
     - Brute-force Attack
     - High-Rate Crawler
     - Web Scraper / Crawler
     - DoS Attack (High Rate)
     - Aggressive Crawler
     - Targeted Endpoint Attack
     - Vulnerability Scanner
     - Aggressive Bot
     - High-Volume Traffic

## Code Quality Improvements

### High Priority Tasks

1. **Research and Implement Testing Framework**
   - Evaluate PHPUnit vs other PHP testing frameworks
   - Design test strategy for command classes and services
   - Create integration tests comparing output with original shell scripts
   - Implement automated validation of backward compatibility
   - Set up continuous integration testing pipeline

2. **SQLite Local Database Architecture (IN PROGRESS)**
   - **NEW APPROACH:** Store all SolarWinds data locally in SQLite database
   - **Key Changes:**
     - SQLite with JSON column storage (handles HTTP + Drupal logs)
     - Generated columns with indexes for fast queries (client_ip, req_method, resp_status, type, severity)
     - Auto-sync: Automatically fetch missing data before queries
     - Local-first: All queries run against SQLite (fast!)
     - Retention: Keep 2 weeks max (SolarWinds API limit) + indefinite for bad actors
   - **New Query Syntax:**
     - Aliases use SQL WHERE clause conditions (simplified)
     - Example: `search req_method='POST' AND resp_status=200 --1d --host`
     - Code wraps in: `SELECT * FROM logs WHERE time >= ... AND ({user_conditions})`
     - Time flags (--1d, --15m, --2w) automatically add time filters
   - **Benefits:**
     - 100x faster queries (local DB vs API calls)
     - Offline analysis capability
     - Historical data preservation (backup before SolarWinds deletes)
     - Complex SQL queries possible (joins, aggregations)
     - Reduced API calls to SolarWinds
   - **Implementation Status:**
     - ✅ DatabaseService created with JSON schema
     - ✅ Integrated with BaseSolarWindsCommand
     - ✅ Auto-sync logic with gap detection
     - ✅ Malformed JSON handling (strip control chars, preserve all data)
     - ⏳ Add `retrieved_at` metadata (timestamp when log was fetched from API)
       - Consider: Add as column with migration OR store in JSON data field
       - Benefits: Track data freshness, debug sync issues, retention policies
     - ⏳ SearchCommand SQL WHERE clause support
     - ⏳ Alias migration to new syntax

### Display and Output Improvements

3. **Query Display Control**: Only show query output when debug mode is enabled

### Code Refactoring

4. **Display Service Consolidation**: Refactor color and formatting logic for consistency
5. **Enhanced Debugging Framework**: Add comprehensive API request/response details and query transformation tracking

## Future Enhancements (Lower Priority)

### Advanced Features
- **IP Range Filtering**: CIDR notation support for network-based filtering
- **Complex Query Combinations**: Boolean logic for advanced query construction
- **Flexible Time Specifications**: Natural language time parsing improvements

### Export and Integration
- **CSV Export**: Structured data export for external analysis
- **JSON Export**: Machine-readable result formats
- **Formatted Reports**: HTML/PDF report generation
- **API Integration**: REST API for programmatic access

### Performance Optimizations
- **Parallel Batch Queries with Multi-Threading**:
  - Run the 4 exploit batches in parallel instead of sequentially
  - Speed up 2-week queries (currently taking minutes)
  - Apply same pattern to ThreatsCommand if needed
  - Parallelize volume analysis IP queries (currently sequential)
  - Implementation options: Guzzle async HTTP, ReactPHP, amphp, or other async solutions
- **Pagination Optimization**: Improved memory usage for large result sets
- **Parallel Processing**: Concurrent API requests for complex queries
