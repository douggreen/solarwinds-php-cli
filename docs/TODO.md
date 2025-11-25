# SolarWinds Log Analysis Tools - TODO

This document tracks the remaining work to complete the migration and enhancement of the SolarWinds log analysis system.

## Next Steps

**Current Priority:**
1. **Refactor DatabaseService Architecture** - Separate concerns between database layer and application logic
2. **Trusted Bot IP Verification** - Enhance bot detection with reverse DNS lookup
3. Review and fix ExploitsCommand issues
4. Research and implement testing framework

## Architecture Refactoring

### 1. DatabaseService Separation of Concerns (TECHNICAL DEBT)

**Problem:** DatabaseService currently mixes database connection/query management with application-specific logic (campaign analysis, bot IP operations, sync tracking). This violates separation of concerns and makes the codebase harder to test and maintain.

**Current State:**
- DatabaseService has 1,294 lines mixing infrastructure and domain logic
- Methods like `saveCampaignAnalysis()`, `getCampaignAnalysisByIp()`, `hasRecentCampaignAnalysis()` embed application logic
- Services like `BotIpService` need raw PDO access via `getConnection()` (quick fix added with @todo)

**Target Architecture:**
- **DatabaseService**: Connection management, schema management, basic query execution only
- **CampaignAnalysisService**: All campaign-related database operations
- **BotIpService**: Bot IP verification and related database operations (already exists, needs integration)
- **SyncTrackingService**: Sync range tracking and gap detection

**Benefits:**
- Clear separation of concerns (infrastructure vs domain logic)
- Easier unit testing (mock domain services without database)
- Better code organization and discoverability
- Follows single responsibility principle

**Implementation Tasks:**
1. Create `CampaignAnalysisService` and move campaign methods from DatabaseService
2. Create `SyncTrackingService` and move sync tracking methods from DatabaseService
3. Update `BotIpService` to accept DatabaseService and use query delegation instead of raw PDO
4. Update all command classes to use domain services instead of DatabaseService directly
5. Add `query()` / `prepare()` delegation methods to DatabaseService for domain services to use
6. Remove `getConnection()` method once domain services no longer need raw PDO access

**Affected Files:**
- `src/Services/DatabaseService.php` - Remove application logic, add query delegation
- `src/Services/CampaignAnalysisService.php` - New file
- `src/Services/SyncTrackingService.php` - New file
- `src/Services/BotIpService.php` - Update to use DatabaseService instead of raw PDO
- `src/Commands/ExploitsCommand.php` - Use CampaignAnalysisService
- `src/Commands/ThreatsCommand.php` - Use CampaignAnalysisService
- `src/Commands/BaseSolarWindsCommand.php` - Use SyncTrackingService

## Security Enhancements

### 1. Trusted Bot IP Verification (IN PROGRESS)

**Problem:** Current implementation only checks User-Agent strings to identify trusted bots, which can be easily spoofed by malicious actors.

**Current Behavior:**
- `BlockingService::classifyUserAgent()` checks if UA matches patterns like "Googlebot", "bingbot"
- No IP address verification
- Attackers can spoof UA to bypass blocking recommendations

**Implementation Status:**

✅ **Completed:**
- Database tables created (`bot_ip_ranges`, `bot_ip_metadata`)
- `BotIpService` implemented with CIDR range checking
- JSON parsing from GitHub sources ([sefinek/known-bots-ip-whitelist](https://github.com/sefinek/known-bots-ip-whitelist))
- Auto-update mechanism (6-hour intervals synced with upstream)
- IPv4/IPv6 CIDR support
- Comprehensive documentation in EXPLOITS.md

**Remaining Work:**

1. **Add Reverse DNS Verification Fallback**
   - For bots without published IP ranges (ClaudeBot, emerging crawlers)
   - Use PHP's `gethostbyaddr()` for reverse DNS
   - Use `gethostbyname()` or `dns_get_record()` for forward verification
   - Verify hostname matches expected pattern (e.g., `*.googlebot.com`, `*.crawl.yahoo.net`)
   - Cache DNS lookups to avoid performance impact
   - Reference: [Google's bot verification documentation](https://developers.google.com/search/docs/crawling-indexing/verifying-googlebot)

2. **Add Bot Spoofing Attack Pattern**
   - Detect when UA claims bot but IP fails verification
   - Tiered severity based on bot type (search engine = critical, monitoring = medium)
   - Integrate into campaign analysis and blocking recommendations
   - Weight fakebot + other exploits for higher confidence blocking

3. **Integrate with BlockingService**
   - Update `classifyUserAgent()` to also verify IP
   - Pass IP address through from ExploitsCommand
   - Only classify as `trusted_bot` if both UA AND IP verification pass
   - Log unverified bots for manual review

4. **Add Bot Verification Commands**
   - `solarwinds bot:update-ranges --all` - Force update all bot ranges
   - `solarwinds bot:verify --ip=X.X.X.X --bot=googlebot` - Test verification
   - `solarwinds bot:stats` - Show update metadata and range counts

5. **Testing**
   - Test with known verified IPs (Googlebot, Bingbot)
   - Test bot spoofing detection (fake UA + random IP)
   - Test reverse DNS fallback (ClaudeBot scenarios)
   - Test edge cases (disabled bots, unknown bots, IPv6)

**Architecture Decision (2025-01-25):**
We chose [sefinek/known-bots-ip-whitelist](https://github.com/sefinek/known-bots-ip-whitelist) as our primary source rather than pulling directly from authoritative APIs. This avoids becoming maintainers ourselves (tracking new bots, API changes, format normalization). Supply chain risk is acceptable given our defense-in-depth approach. See EXPLOITS.md for full rationale.

**Maintenance Commitments:**

- **Quarterly Ecosystem Review:** Check if [sefinek](https://github.com/sefinek/known-bots-ip-whitelist) is still maintained, evaluate alternatives, monitor for industry standards
- **Future Contribution:** Consider PR to [sefinek](https://github.com/sefinek/known-bots-ip-whitelist) adding bot type metadata (systematic crawler vs user-directed vs monitoring) to help community distinguish bot behaviors

**Affected Files:**
- ✅ `src/Services/BotIpService.php` - Created
- ✅ `src/Services/DatabaseService.php` - Added bot_ip tables
- ✅ `docs/EXPLOITS.md` - Documented strategy
- 🔲 `src/Services/BlockingService.php` - Needs integration
- 🔲 `src/Commands/ExploitsCommand.php` - Needs bot spoofing pattern
- 🔲 `.solarwinds.yml.example` - Needs bot_verification config

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

## User Interface Improvements

### 1. Standardize Command-Line Options (UI CONSISTENCY)

**Problem:** Command-line options use inconsistent naming conventions, making the interface less predictable and harder to learn.

**Current State (Inconsistent):**
- Attack type filters: `--xss-only`, `--sqli-only`, `--fakebot-only`, `--scan-only`
- Blocking filters: `--show-all-actions` (but no `--show-action-block-now`)
- Severity filters: `--min-severity=medium` (parameter-based, not flag-based)
- Display options: `--show-details`, `--summary-only`

**Target State (Consistent):**
- **Action filters:** `--show-action-block-now`, `--show-action-block-maybe`, `--show-action-review`, `--show-action-all`
- **Type filters:** `--show-type-api`, `--show-type-fakebot`, `--show-type-xss`, `--show-type-sqli`, `--show-type-scan`, etc.
- **Severity filters:** `--show-severity-critical`, `--show-severity-high`, `--show-severity-medium`, `--show-severity-low` (minimum threshold)
- **Display options:** `--show-details`, `--show-summary` (keep existing pattern)

**Benefits:**
- Predictable naming: All filters follow `--show-{category}-{value}` pattern
- Self-documenting: Category in flag name (action/type/severity)
- Tab-completion friendly: All `--show-*` flags group together
- Easier to remember: Consistent structure

**Implementation Strategy:**
1. Replace old flags with new standardized flags (clean break, no backward compatibility needed)
2. Update all documentation examples to use new flags
3. Update any shell scripts or aliases that use old flags

**Notes:**
- Single user codebase - no backward compatibility concerns
- Can do clean refactor without deprecation period
- **Type filter behavior:** When type filters are active (`--show-type-*` or current `--*-only` flags), the command automatically bypasses action filtering and shows ALL campaigns matching that type, regardless of blocking recommendation (BLOCK NOW, ALLOW, etc.). This is intentional - when users ask for specific attack types, they want to see all instances, not have them hidden by action priority

**Affected Files:**
- `src/Commands/ExploitsCommand.php` - Add new option definitions
- `src/Commands/ThreatsCommand.php` - Add new option definitions (if applicable)
- `docs/EXPLOITS.md` - Update documentation examples
- `README.md` - Update usage examples
- `CHANGELOG.md` - Document deprecations and new flags

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
