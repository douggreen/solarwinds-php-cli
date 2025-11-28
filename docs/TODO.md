# SolarWinds Log Analysis Tools - TODO

This document tracks the remaining work to complete the migration and enhancement of the SolarWinds log analysis system.

## Next Steps

**Current Priority:**
1. **Track Blocked IPs** - Avoid showing 'BLOCK NOW' for already-blocked IPs
2. **Investigate Record Count Discrepancy** - Database contains 10,527,622 vs Analyzing 9,759,784 (768k difference)
3. **Factor Recency into Blocking** - Prioritize active campaigns over dormant ones
4. **Performance Review** - Review frequently-called methods (like getProgressCallback) for repeated expensive operations like strtotime()
5. **Implement Reverse DNS Bot Verification** - Secure bot verification (prevents User-Agent spoofing)
6. **Implement DDoS Detection** - Detect coordinated exploit campaigns
7. Research and implement testing framework

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
   - For bots without published IP ranges (IAHarvester, ClaudeBot, emerging crawlers)
   - Use PHP's `gethostbyaddr()` for reverse DNS
   - Use `gethostbyname()` or `dns_get_record()` for forward verification
   - Verify hostname matches expected pattern:
     - `*.googlebot.com`, `*.crawl.yahoo.net` (search engines)
     - `*.archive.org` (Internet Archive/IAHarvester)
   - Cache DNS lookups to avoid performance impact
   - **Security:** This prevents User-Agent spoofing - requires both UA match AND DNS verification
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
