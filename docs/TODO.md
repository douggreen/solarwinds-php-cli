# SolarWinds Log Analysis Tools - TODO

This document tracks the remaining work to complete the migration and enhancement of the SolarWinds log analysis system.

## Next Steps

**Current Priority:**
1. **Track Blocked IPs** - Avoid showing 'BLOCK NOW' for already-blocked IPs
2. **Investigate Record Count Discrepancy** - Database contains 10,527,622 vs Analyzing 9,759,784 (768k difference)
3. **Factor Recency into Blocking** - Prioritize active campaigns over dormant ones
4. **Performance Review** - Review frequently-called methods (like getProgressCallback) for repeated expensive operations like strtotime()
5. **Implement DDoS Detection** - Detect coordinated exploit campaigns
6. Research and implement testing framework

## Security Enhancements

### 1. Bot Verification Management Commands (Optional)

**Optional CLI Tools for Bot IP Verification:**
   - `solarwinds bot:update-ranges --all` - Force update all bot ranges
   - `solarwinds bot:verify --ip=X.X.X.X --bot=googlebot` - Test verification
   - `solarwinds bot:stats` - Show update metadata and range counts

Note: Bot IP verification is fully functional and automatic (updates every 6 hours). These commands would only provide manual control and debugging capabilities.

## Code Quality Improvements

### High Priority Tasks

1. **Research and Implement Testing Framework**
   - Evaluate PHPUnit vs other PHP testing frameworks
   - Design test strategy for command classes and services
   - Create integration tests comparing output with original shell scripts
   - Implement automated validation of backward compatibility
   - Set up continuous integration testing pipeline

### Display and Output Improvements

2. **Highlight Blocking Decision Factors in Campaign Display**
   - Color-code table row values (scan types, severity, time span, volume, behavior, origin%) that contribute to ACTION recommendations
   - Visual indicators show WHY a campaign is BLOCK NOW vs BLOCK MAYBE vs ALLOW
   - Help users quickly understand the severity factors driving blocking decisions
   - Color scheme: BLOCK NOW in red, BLOCK MAYBE in orange
   - Highlight the specific factors contributing to each blocking decision
   - Improves threat assessment speed and decision confidence

3. **Alphabetize and Color-Code Legend Sections**
   - Sort all legend items alphabetically (Scan Types, Action, Behavior, etc.)
   - Apply same color scheme in legend as used for blocking decision highlighting
   - Consistent visual language between legend and campaign display
   - Makes legend easier to scan and reference


## Future Enhancements (Lower Priority)

### Advanced Features
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
