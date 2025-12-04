# SolarWinds Log Analysis Tools - TODO

This document tracks all remaining work for the SolarWinds log analysis system.

**NOTE:** Previously, exploit detection tasks were in a separate EXPLOITS_TODO.md file, but consolidating here for better maintenance and synchronization with actual implementation status.

---

## :red_circle: EXPLOIT DETECTION TASKS (Current Priority)

The exploit detection system currently flags too much low-volume noise as critical threats. The risk scoring infrastructure is implemented but needs testing and parameter tuning. Multi-timeframe intelligence and additional filtering features are planned.

### Current Problems

Based on testing with real data (--1d and --all timeframes):

**Issue 1: Low-Volume Noise Flagged as Critical**
- 56 requests at 17.5/hr triggers "BLOCK NOW" (should be background noise)

**Issue 2: No Recency Prioritization in Display**
- Old attacks shown with same priority as active threats
- :white_check_mark: Recency scoring implemented in RiskScoringService
- :x: Display sorting and indicators not yet implemented

**Issue 3: Too Many "BLOCK MAYBE" Results**
- 235 campaigns in 2-week dataset - too many to manually review
- Need better filtering and noise reduction

### High Priority Tasks

1. **Analysis Run Query Commands** (optional, deferred)
   - [ ] Add `exploits history` command to list recent runs
   - [ ] Add `exploits compare RUN1 RUN2` to compare before/after
   - [ ] Add `exploits show-run RUN` to view run details
   - [ ] Add `exploits rescore --run=RUN` to re-score with current config

   Note: Core run tracking is implemented (`--save-run`). These are optional query enhancements.

2. **Risk Scoring Simplification** (In progress)
   - :white_check_mark: Run baseline with current config
   - :white_check_mark: Remove historical factor (5% weight, redundant with deep dive)
   - :white_check_mark: Test with cached data - no blocking decision changes
   - [ ] Analyze false positives and failure patterns
   - [ ] Further simplification: Remove server_impact, keep origin_impact
   - [ ] Evaluate multiplicative formula vs weighted sum
   - [ ] Test with same timeframe, compare results
   - [ ] Iterate based on improvements/regressions

   **Status:** Reduced from 6 to 5 factors. Current weights: origin_impact (30%), volume (25%),
   severity (20%), recency (20%), server_impact (5%).

   **Approach:** Human-guided simplification rather than automated parameter sweep.
   Start simple, add complexity only when justified by real failure cases.

4. **Display Enhancements**
   - :white_check_mark: Sort by risk score (campaigns sorted by action priority, then risk score descending)
   - [ ] Show "Last Seen" timestamp with recency indicators
   - [ ] Highlight high-contributing risk factors in campaign table

### Medium Priority Tasks

5. **Multi-Timeframe Intelligence**
   - [ ] Add `--mode` flag (alert vs intelligence)
   - [ ] Implement historical context boosting from campaign_analysis
   - [ ] Enrich deep dive data - Use full historical analysis for all metrics
     - Currently: Deep dive calculates full campaign data but only stores 3 fields (requests, first_seen, span_days)
     - Currently enriched: Action, Score, Time Span (show with asterisk)
     - Not enriched: Volume, 40x%, Origin%, Behavior, Attack Types, Severity (still from current window)
     - Enhancement: Use deep dive's full historical attack patterns, severity, volume analysis
     - Benefit: More accurate threat assessment based on long-term patterns vs short window snapshots
   - [ ] Document cron schedule (15m/1d/1w/1m)
   - [ ] Add alert output format for cron emails

6. **Bot & CMS Filtering**
   - :white_check_mark: CMS-aware filtering implemented (WordPress patterns skip Drupal sites)
   - :white_check_mark: Bot detection implemented (classifies common search engine bots)
   - :warning: Bot filtering logic may need tuning (bots still appearing in results)
   - [ ] Drupal legitimate endpoint detection (/system/ajax, /toolbar/subtrees)

### Lower Priority Tasks (Deferred)

7. **Automated Testing Infrastructure** (May not be needed if simplification works)
   - [ ] Build ground truth dataset - Manually classify 20-30 campaigns
   - [ ] Create parameter sweep test harness - Test ~500 configurations
   - [ ] Implement evaluation metrics (F1, precision, recall)
   - [ ] Generate optimization reports

   **Note:** Parameter sweep approach assumes architecture is correct and just needs tuning.
   Simplification approach questions the architecture itself. Trying simplification first.

8. **Parameter Tuning** (Only if automated testing proves necessary)
   - Risk scoring weights (origin, volume, severity, recency, historical, server impact)
   - Volume thresholds (what req/hr rates map to what scores)
   - Risk level cutoffs (critical/high/medium/low/noise boundaries)

9. **Parameter Enumeration Detection**
    - [ ] Detect attacks using 100+ random parameter values on same endpoint
    - [ ] Example: /api/endpoint?param=value1, /api/endpoint?param=value2, etc.
    - [ ] Flag as distinct attack pattern separate from path enumeration

10. **Coordinated Attack Detection**
    - [ ] Detect multiple IPs with identical attack patterns (same paths, same timing)
    - [ ] Group coordinated attacks into single campaign with multiple source IPs
    - [ ] Distinguish from coincidental similar attacks

11. **Blocked IP Tracking**
    - [ ] Track IPs that have been blocked at firewall/WAF level
    - [ ] Suppress BLOCK NOW alerts for already-blocked IPs
    - [ ] Show "Already Blocked" status in campaign display

---

## Code Quality Improvements

### Bot Verification Management Commands (Optional)

**Optional CLI Tools for Bot IP Verification:**
   - `solarwinds bot:update-ranges --all` - Force update all bot ranges
   - `solarwinds bot:verify --ip=X.X.X.X --bot=googlebot` - Test verification
   - `solarwinds bot:stats` - Show update metadata and range counts

Note: Bot IP verification is fully functional and automatic (updates every 6 hours). These commands would only provide manual control and debugging capabilities.

### High Priority Tasks

1. **Research and Implement Testing Framework**
   - Evaluate PHPUnit vs other PHP testing frameworks
   - Design test strategy for command classes and services
   - Create integration tests comparing output with original shell scripts
   - Implement automated validation of backward compatibility
   - Set up continuous integration testing pipeline

2. **Review DatabaseService for Abstraction Violations**
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
