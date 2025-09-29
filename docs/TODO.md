# SolarWinds Log Analysis Tools - TODO

This document tracks the remaining work to complete the migration and enhancement of the SolarWinds log analysis system.

## Next Steps

**Current Priority:**
1. Complete documentation cleanup :white_check_mark: (completed)
2. Manual code cleanup phase (current)
3. Implement remaining commands
2. Improve code documentation and comments
3. Research and implement testing framework
4. Begin Phase 1 command implementations

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

### **4. php-error (PhpErrorCommand)**
- **Purpose**: PHP error analysis from structured error logs
- **Query Pattern**: `{ json.type:php } -NotAcceptableHttpException`
- **Data Structure**: Uses different log structure than standard web logs
- **Default Display**: files-by-line-recent format
- **Default Time**: 1 day
- **Example Usage**: `php-error --day`, `php-error --1h --host`

### **5. pingdom (PingdomCommand)**
- **Purpose**: Pingdom monitoring service error analysis
- **Query Pattern**: `json.req_user_agent:pingdom AND -json.resp_status:200 AND -json.resp_status:301 AND -json.resp_status:304`
- **Focus**: Non-successful responses from Pingdom monitoring
- **Default Display**: Custom format with host,status,uri,timestamp
- **Example Usage**: `pingdom --day`, `pingdom --1h --status`

### **6. importer (ImporterCommand)**
- **Purpose**: Content import activity analysis
- **Query Pattern**: `{ json.type:mtc_importer } { json.severity:Info }`
- **Default Display**: ips-only format (IP address analysis)
- **Default Time**: 1 day
- **Example Usage**: `importer --1h`, `importer --day --status`

### **7. antibot (AntibotCommand)**
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

**Phase 3 (Complex):** php-error
- Different data structure requiring specialized handling

## Code Quality Improvements

### High Priority Tasks

1. **Better Document Code Architecture**
   - Add comprehensive header comments to all classes including architectural details
   - Improve method documentation with parameter/return type descriptions
   - Add inline comments explaining complex logic and design decisions
   - Document the template method pattern implementation
   - Explain service layer interactions and dependencies

2. **Research and Implement Testing Framework**
   - Evaluate PHPUnit vs other PHP testing frameworks
   - Design test strategy for command classes and services
   - Create integration tests comparing output with original shell scripts
   - Implement automated validation of backward compatibility
   - Set up continuous integration testing pipeline

3. **Improve README User Documentation**
   - Remove project status and architectural details
   - Focus on installation, configuration, and usage examples
   - Expand configuration documentation with comprehensive examples
   - Add troubleshooting section for common issues
   - Improve command reference and alias examples

### Display and Output Improvements

4. **No-Grouping Option**: Add `--no-group` flag to show individual log entries instead of grouped summaries
5. **Query Display Control**: Only show query output when debug mode is enabled

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
- **Extended Caching**: More sophisticated cache invalidation strategies
- **Pagination Optimization**: Improved memory usage for large result sets
- **Parallel Processing**: Concurrent API requests for complex queries
