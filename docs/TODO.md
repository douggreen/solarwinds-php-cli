# SolarWinds Log Analysis Tools - TODO

This document tracks the remaining work to complete the migration and enhancement of the SolarWinds log analysis system.

## Current Priority: Exploit Detection Improvements

**See [EXPLOITS_TODO.md](EXPLOITS_TODO.md) for detailed planning and implementation strategy.**

The exploit detection system currently flags too much low-volume noise as critical threats. We need to implement intelligent risk scoring, multi-timeframe intelligence gathering, and systematic testing to tune parameters.

## Next Steps

**Other Priorities:**
1. **Standardize Gap Array Naming** - Rename gap array keys to follow `_time` convention
   - Currently: gap arrays use `'start'` and `'end'` (ambiguous)
   - Should be: `'start_time'` and `'end_time'` (follows timestamp naming convention)
   - Files to update:
     - SyncTrackingService.php - all gap array creations in detectGapsInSyncedRanges() and detectMissingRanges()
     - BaseSolarWindsCommand.php - all gap array usages
     - SyncCommand.php - all gap array usages in displayCoverageReport()
   - Improves consistency with naming convention where `*_time` = unix timestamp integer
2. **Cleanup CASE_STUDY_2.md** - Rewrite to focus on process and collaboration rather than product
   - Currently too product-focused (features, performance improvements, blocking campaigns)
   - Sounds bragadocious with too many claims about achievements
   - Should focus on: human-AI collaboration patterns, what worked/what didn't, process improvements
   - Should emphasize: AI blindspots, human oversight requirements, iteration patterns
   - Remove marketing-style language, focus on practical lessons learned
   - Target audience: developers considering AI-assisted development, not product users
3. **Performance Review** - Review frequently-called methods (like getProgressCallback) for repeated expensive operations like strtotime()
4. Research and implement testing framework

## Security Enhancements

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
- **Denormalize JSON Message Data into Separate Columns**:
  - Current schema stores all log data in a single JSON `message` column
  - Querying JSON fields requires SQLite to parse JSON for every row scanned
  - Processing 541k bot logs requires 541k JSON decode operations in PHP
  - Migrate frequently-queried fields to dedicated columns:
    - `client_ip` - used for grouping, filtering, and verification
    - `req_user_agent` - used for bot detection and classification
    - `req_uri` - used for exploit pattern matching
    - `resp_status` - used for filtering and campaign analysis
  - Keep `message` column for less-frequently accessed fields
  - Massive performance improvement for large queries (--all, --2w, etc.)
  - Migration strategy: ALTER TABLE to add columns, backfill from JSON, update sync logic
  - Backward compatibility: Keep JSON parsing for old records without denormalized columns
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
