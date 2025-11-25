# SolarWinds Log Analysis Tools - TODO

This document tracks the remaining work to complete the migration and enhancement of the SolarWinds log analysis system.

## Next Steps

**Current Priority:**
1. Review and fix ExploitsCommand issues
2. Research and implement testing framework

## ExploitsCommand Enhancements

### Current Issues to Review

1. **Review Low-Volume Traffic Classification**
   - 8 requests over 1.9 days (0.2/hour) being classified as "High-Volume Traffic" and appearing in blocking recommendations
   - Need minimum request threshold before classifying IPs as threats worthy of blocking consideration
   - `classifyThreatType()` currently returns 'High-Volume Traffic' as default for any traffic that doesn't match other patterns
   - Should either:
     - Add minimum threshold check (e.g., 50+ requests) before returning threat classification
     - Filter out low-volume IPs earlier in campaign creation
     - Change default from "High-Volume Traffic" to something more appropriate for edge cases

2. **Review SCANNER Detection False Positives**
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
     - ✅ Auto-sync logic with range detection
     - ✅ Malformed JSON handling (strip control chars, preserve all data)
     - ✅ Add `retrieved_at` metadata (timestamp when log was fetched from API)
     - ✅ All commands updated to build SQL WHERE clauses
     - ✅ SearchCommand supports --sql-where for direct SQL queries
     - ✅ SearchCommand supports --query for backward compatibility (translates SolarWinds syntax to SQL)
     - ✅ All aliases migrated to SQL WHERE clause syntax

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
