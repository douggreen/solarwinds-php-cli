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

### **3. pentest (PentestCommand)**
- **Purpose**: Security penetration testing activity analysis
- **Query Pattern**: `{ json.req_uri:admin } OR { json.req_uri:phpmyadmin } OR { json.req_uri:wp-admin } OR { json.req_uri:wp-login } OR { json.req_uri:xmlrpc } OR { json.req_uri:eval } OR { json.req_uri:union } OR { json.req_uri:select } OR { json.req_uri:script } OR { json.req_uri:alert }`
- **Filtering**: Excludes '/news', '/tags', '%20Union%20City%20' patterns (false positives)
- **Default Display**: Custom format with count, host, path columns
- **Country Mode**: `--country` flag for geographic analysis
- **Example Usage**: `pentest --day`, `pentest --1h --country`

### **4. pingdom (PingdomCommand)**
- **Purpose**: Pingdom monitoring service error analysis
- **Query Pattern**: `json.req_user_agent:pingdom AND -json.resp_status:200 AND -json.resp_status:301 AND -json.resp_status:304`
- **Focus**: Non-successful responses from Pingdom monitoring
- **Default Display**: Custom format with host,status,uri,timestamp
- **Example Usage**: `pingdom --day`, `pingdom --1h --status`

### **5. importer (ImporterCommand)**
- **Purpose**: Content import activity analysis
- **Query Pattern**: `{ json.type:mtc_importer } { json.severity:Info }`
- **Default Display**: ips-only format (IP address analysis)
- **Default Time**: 1 day
- **Example Usage**: `importer --1h`, `importer --day --status`

### **6. antibot (AntibotCommand)**
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

**Phase 2 (Medium):** pagebyip, pentest, pingdom
- Require custom output formatting
- More complex query logic

**Note:** php-error was implemented via the `--drupal` display flag and aliases rather than as a separate command.

## ExploitsCommand Enhancements

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

### Display and Output Improvements

2. **Query Display Control**: Only show query output when debug mode is enabled

### Code Refactoring

6. **Display Service Consolidation**: Refactor color and formatting logic for consistency
7. **Enhanced Debugging Framework**: Add comprehensive API request/response details and query transformation tracking

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
- **Extended Caching**: More sophisticated cache invalidation strategies
- **Pagination Optimization**: Improved memory usage for large result sets
- **Parallel Processing**: Concurrent API requests for complex queries
