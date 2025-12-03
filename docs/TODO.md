# SolarWinds Log Analysis Tools - TODO

This document tracks all remaining work for the SolarWinds log analysis system.

**NOTE:** Previously, exploit detection tasks were in a separate EXPLOITS_TODO.md file, but consolidating here for better maintenance and synchronization with actual implementation status.

---

## 🔴 EXPLOIT DETECTION TASKS (Current Priority)

The exploit detection system currently flags too much low-volume noise as critical threats. The risk scoring infrastructure is implemented but needs testing and parameter tuning. Multi-timeframe intelligence and additional filtering features are planned.

### Current Problems

Based on testing with real data (--1d and --all timeframes):

**Issue 1: Low-Volume Noise Flagged as Critical**
- 56 requests at 17.5/hr triggers "BLOCK NOW" (should be background noise)

**Issue 2: No Recency Prioritization in Display**
- Old attacks shown with same priority as active threats
- ✅ Recency scoring implemented in RiskScoringService
- ❌ Display sorting and indicators not yet implemented

**Issue 3: Too Many "BLOCK MAYBE" Results**
- 235 campaigns in 2-week dataset - too many to manually review
- Need better filtering and noise reduction

### High Priority Tasks

1. **Analysis Run Tracking** (:white_check_mark: COMPLETED)
   - :white_check_mark: Add `analysis_runs` table to track each exploit detection run
   - :white_check_mark: Capture configuration snapshot (risk_scoring, blocking config)
   - :white_check_mark: Record git commit/branch/dirty status for reproducibility
   - :white_check_mark: Link campaigns to runs via run_id foreign key
   - :white_check_mark: Save summary statistics (total, block_now, block_maybe counts)
   - :white_check_mark: Add `--save-run` flag to enable tracking
   - :white_check_mark: Document in EXPLOITS.md
   - [ ] Add `exploits history` command to list recent runs (optional, deferred)
   - [ ] Add `exploits compare RUN1 RUN2` to compare before/after (optional, deferred)
   - [ ] Add `exploits show-run RUN` to view run details (optional, deferred)
   - [ ] Add `exploits rescore --run=RUN` to re-score with current config (optional, deferred)

   **Status:** Core tracking implemented. Can now run with `--save-run` to capture config snapshots.
   Additional query commands (history/compare/show-run) are optional enhancements.

2. **Risk Scoring Simplification** (After run tracking)
   - [ ] Run baseline with current config: `exploits --3d --show-action=all`
   - [ ] Analyze false positives and failure patterns
   - [ ] Simplify from 6 factors to 3 core factors (severity × volume × recency)
   - [ ] Remove/defer: origin_impact, historical, server_impact
   - [ ] Implement simplified multiplicative formula instead of weighted sum
   - [ ] Test with same timeframe, compare results
   - [ ] Iterate based on improvements/regressions

   **Approach:** Human-guided simplification rather than automated parameter sweep.
   Start simple, add complexity only when justified by real failure cases.

3. **Display Enhancements**
   - [ ] Sort by recency-weighted risk score
   - [ ] Show "Last Seen" timestamp with recency indicators
   - [ ] Highlight high-contributing risk factors in campaign table

### Medium Priority Tasks

4. **Multi-Timeframe Intelligence**
   - [ ] Add `--mode` flag (alert vs intelligence)
   - [ ] Implement historical context boosting from campaign_analysis
   - [ ] Document cron schedule (15m/1d/1w/1m)
   - [ ] Add alert output format for cron emails

5. **Bot & CMS Filtering**
   - ✅ CMS-aware filtering implemented (WordPress patterns skip Drupal sites)
   - ✅ Bot detection implemented (classifies common search engine bots)
   - ⚠️  Bot filtering logic may need tuning (bots still appearing in results)
   - [ ] Drupal legitimate endpoint detection (/system/ajax, /toolbar/subtrees)

### Lower Priority Tasks (Deferred)

6. **Automated Testing Infrastructure** (May not be needed if simplification works)
   - [ ] Build ground truth dataset - Manually classify 20-30 campaigns
   - [ ] Create parameter sweep test harness - Test ~500 configurations
   - [ ] Implement evaluation metrics (F1, precision, recall)
   - [ ] Generate optimization reports

   **Note:** Parameter sweep approach assumes architecture is correct and just needs tuning.
   Simplification approach questions the architecture itself. Trying simplification first.

7. **Parameter Tuning** (Only if automated testing proves necessary)
   - Risk scoring weights (origin, volume, severity, recency, historical, server impact)
   - Volume thresholds (what req/hr rates map to what scores)
   - Risk level cutoffs (critical/high/medium/low/noise boundaries)

8. **Advanced Detection Features**
   - [ ] Parameter enumeration detection (100+ random params on same path)
   - [ ] Coordinated attack detection (multiple IPs with same patterns)
   - [ ] Blocked IP tracking (suppress alerts for already-blocked IPs)

### Implementation Status Summary

**✅ COMPLETED:**
- Risk scoring system with 6 factors (RiskScoringService.php)
- Recency weighting (calculateRecencyScore)
- CMS-aware pattern filtering (getCmsType, detectAttackPatterns)
- Bot classification (classifyUserAgent)

**⚠️ PARTIALLY DONE:**
- Bot filtering exists but may need tuning
- Recency scoring done but display sorting/indicators missing

**❌ NOT STARTED:**
- Testing infrastructure (ground truth + parameter sweep)
- Multi-timeframe modes and cron setup
- Drupal endpoint filtering
- Parameter enumeration detection
- Coordinated attack detection
- Blocked IP tracking

---

## Code Quality Improvements

### 1. Bot Verification Management Commands (Optional)

**Optional CLI Tools for Bot IP Verification:**
   - `solarwinds bot:update-ranges --all` - Force update all bot ranges
   - `solarwinds bot:verify --ip=X.X.X.X --bot=googlebot` - Test verification
   - `solarwinds bot:stats` - Show update metadata and range counts

Note: Bot IP verification is fully functional and automatic (updates every 6 hours). These commands would only provide manual control and debugging capabilities.

## Code Quality Improvements

### High Priority Tasks

1. **Use PHP Constructor Property Promotion**
   - Refactor all service and command constructors to use PHP 8.0+ property promotion syntax
   - Example: `public function __construct(protected DatabaseService $database)` instead of separate property declaration and assignment
   - Makes code more concise and eliminates boilerplate
   - Already used in CampaignAnalysisService - apply consistently across codebase

2. **Research and Implement Testing Framework**
   - Evaluate PHPUnit vs other PHP testing frameworks
   - Design test strategy for command classes and services
   - Create integration tests comparing output with original shell scripts
   - Implement automated validation of backward compatibility
   - Set up continuous integration testing pipeline

3. **Review DatabaseService for Abstraction Violations**
   - Application-level code has crept into DatabaseService again
   - DatabaseService should contain ONLY:
     - Schema management (CREATE TABLE, indexes)
     - Raw SQL execution (query(), execute(), prepare())
     - Basic CRUD operations (insertLogs(), no business logic)
     - Transaction management (beginTransaction(), commit(), rollback())
   - Application logic belongs in specialized services:
     - CampaignAnalysisService - campaign queries and analysis
     - SyncTrackingService - sync status and gap detection
     - Command classes - WHERE clause building, result filtering
   - Audit current DatabaseService methods for business logic
   - Move application-specific methods to appropriate services
   - Restore DatabaseService to low-level data access only

### Display and Output Improvements

1. **Alphabetize and Color-Code Legend Sections**
   - Sort all legend items alphabetically (Scan Types, Action, Behavior, etc.)
   - Apply same color scheme in legend as used for blocking decision highlighting
   - Consistent visual language between legend and campaign display
   - Makes legend easier to scan and reference


## Future Enhancements (Lower Priority)

### Advanced Features
- **AI-Assisted Threat Analysis**: Research feasibility of using AI to identify threats
  - Challenge: Large data volume makes cloud APIs impractical (cost, bandwidth)
  - Would require local AI setup (Ollama, llama3.2, etc.)
  - Potential uses: Anomaly detection, pattern clustering, risk scoring validation
  - Lower priority: Manual risk scoring system should be implemented and tested first
  - Research questions: Can local AI models provide meaningful threat insights? What's the performance impact?
- **Dynamic Attack Pattern Learning**: Adaptive pattern detection based on traffic analysis
  - Automatically identify suspicious root-level PHP files (high volume, non-200 responses)
  - Build reputation scores for observed attack patterns over time
  - Suggest new patterns for review based on traffic (e.g., /alfa.php, /abcd.php with 1000+ requests)
  - Quarterly review system to approve learned patterns
  - Balance between static baseline patterns (framework-specific) and dynamic adaptation
  - Current baseline: test.php, temp.php, 1.php, x.php, shell.php based on observed traffic
- **IP Range Filtering**: CIDR notation support for network-based filtering
- **Complex Query Combinations**: Boolean logic for advanced query construction
- **Flexible Time Specifications**: Natural language time parsing improvements

### Export and Integration
- **CSV Export**: Structured data export for external analysis
- **JSON Export**: Machine-readable result formats
- **Formatted Reports**: HTML/PDF report generation
- **API Integration**: REST API for programmatic access

### Performance Optimizations
- **Investigate Pattern Detection Performance Regression**:
  - Pattern detection on 13M log entries currently takes ~5 minutes
  - Should be ~3:20 based on previous performance (50% regression)
  - Regression suspected around commit 2dbf10c but not confirmed
  - Recent optimizations implemented:
    - Added INDEXED BY hint to force idx_time_method usage (eliminates TEMP B-TREE sort on query)
    - Added url_arguments to SELECT to avoid JSON parsing
    - Changed getCmsType() to use cached database queries instead of in-memory filtering
  - Bottleneck is in pattern matching loop itself, not database query
  - Next steps:
    - Profile the pattern matching code to identify actual bottleneck
    - Test older commits to confirm when regression occurred
    - Consider pattern matching optimizations (early termination, compiled regexes, etc.)
    - Verify data volume hasn't increased (more rows = slower processing)
- **✅ Denormalize JSON Message Data into Separate Columns** (COMPLETED):
  - Migrated frequently-queried fields to dedicated columns:
    - `client_ip` - used for grouping, filtering, and verification
    - `req_user_agent` - used for bot detection and classification
    - `req_uri` - used for exploit pattern matching
    - `resp_status` - used for filtering and campaign analysis
  - Kept `message` column for less-frequently accessed fields
  - Implemented migration strategy: ALTER TABLE, backfill from JSON, updated sync logic
  - Maintained backward compatibility: JSON parsing for old records without denormalized columns
  - Achieved massive performance improvement for large queries (--all, --2w, etc.)
- **Database Retention & Cleanup Policy**:
  - Review data retention strategy as database grows over time
  - Consider implementing automatic cleanup of old logs (e.g., >2 weeks)
  - Option to preserve logs indefinitely for flagged bad actors/attackers
  - Add commands to manage retention rules and database size
  - Monitor database growth and performance impact
  - Consider archiving strategies for historical data
- **Parallel Batch Queries with Multi-Threading**:
  - Run the 4 exploit batches in parallel instead of sequentially
  - Speed up 2-week queries (currently taking minutes)
  - Parallelize volume analysis IP queries (currently sequential)
  - Implementation options: Guzzle async HTTP, ReactPHP, amphp, or other async solutions
- **Pagination Optimization**: Improved memory usage for large result sets
- **Parallel Processing**: Concurrent API requests for complex queries
