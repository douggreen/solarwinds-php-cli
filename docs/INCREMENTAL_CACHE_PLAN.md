# Incremental Cache Update Implementation Plan

## Overview

Implement automatic incremental cache updates to reduce API load for queries with overlapping time ranges. When a cached result exists but is slightly stale, fetch only the time gap and merge with cached data instead of re-querying the entire time range.

## Current State Analysis

### Cache Metadata Structure (Current)
- **File**: `{cacheKey}.meta`
- **Content**: Single integer timestamp (Unix epoch)
- **Example**: `1732147200`

### Cache Result File (Current)
- **File**: `{cacheKey}.json`
- **Content**: Array of log entries
- **Format**: JSON with log IDs, messages, timestamps

### Current Cache Flow
1. `searchLogsWithProgress()` calls `tryLoadFromCache()`
2. `tryLoadFromCache()` checks if cache is fresh using 10% staleness rule
3. If fresh: return cached results
4. If stale: return NULL, trigger fresh API query
5. Fresh results saved via `saveResultsToCache()`

### Key Data Available
- `$options['time']['start_time']` - Query start time (e.g., "2024-11-15T00:00:00Z")
- `$options['time']['end_time']` - Query end time (e.g., "2024-11-16T00:00:00Z")
- `$options['time']['human_readable']` - Time flag (e.g., "--1d")
- Log entries have unique `id` field for deduplication

## Proposed Changes

### 1. Enhanced Cache Metadata Schema

**New metadata file format** (JSON instead of plain timestamp):
```json
{
  "version": 2,
  "created_at": 1732147200,
  "query_start_time": "2024-11-15T00:00:00Z",
  "query_end_time": "2024-11-16T00:00:00Z",
  "time_range_seconds": 86400,
  "query_hash": "abc123def456",
  "script_name": "exploits"
}
```

**Backwards Compatibility:**
- If metadata file contains only integer, treat as legacy format
- Legacy format: assume cache is for full time range ending at cache timestamp
- New format: version field distinguishes new metadata

### 2. Decision Logic for Incremental vs Full Refresh

**Incremental update criteria (ALL must be true):**
1. Cache exists and metadata is valid
2. Query time range ≥ 86400 seconds (1 day)
3. Cache age ≤ 50% of query time range
4. Cache query end time is within current query time range
5. Query parameters match (same query string, sites, filters)

**Threshold examples:**
- `--1d` query with 10-hour-old cache: ✅ Use incremental (10h < 12h)
- `--1d` query with 15-hour-old cache: ❌ Full refresh (15h > 12h)
- `--1w` query with 3-day-old cache: ✅ Use incremental (3d < 3.5d)
- `--1h` query with any cache: ❌ Full refresh (too short for incremental)

### 3. Gap Calculation Algorithm

```
Current query: --1d (last 24 hours)
Current time: 2024-11-16 14:00:00

Cache metadata:
  query_start_time: 2024-11-15 04:00:00
  query_end_time: 2024-11-16 04:00:00
  created_at: 2024-11-16 04:00:00 (10 hours ago)

Gap calculation:
  Current query range: 2024-11-15 14:00:00 to 2024-11-16 14:00:00
  Cached data range:   2024-11-15 04:00:00 to 2024-11-16 04:00:00
  Gap to fetch:        2024-11-16 04:00:00 to 2024-11-16 14:00:00 (10 hours)
  Reusable from cache: 2024-11-15 14:00:00 to 2024-11-16 04:00:00 (14 hours)
```

**Implementation:**
```php
$currentQueryEnd = strtotime($options['time']['end_time']);
$cachedQueryEnd = strtotime($metadata['query_end_time']);
$gapSeconds = $currentQueryEnd - $cachedQueryEnd;

if ($gapSeconds > 0) {
    // Build incremental query from cache end to current end
    $incrementalOptions = $options;
    $incrementalOptions['time']['start_time'] = $metadata['query_end_time'];
    $incrementalOptions['time']['end_time'] = $options['time']['end_time'];
}
```

### 4. Result Merging and Deduplication

**Merge strategy:**
1. Load cached results
2. Fetch fresh results for gap period
3. Combine arrays
4. Deduplicate by log entry `id` field
5. Sort by timestamp (optional - may not be necessary)

**Implementation:**
```php
function mergeAndDeduplicateResults(array $cachedResults, array $freshResults): array
{
    $merged = [];
    $seenIds = [];

    // Process all results (cached + fresh)
    foreach (array_merge($cachedResults, $freshResults) as $entry) {
        $id = $entry['id'] ?? null;

        if ($id === null) {
            // No ID - include anyway
            $merged[] = $entry;
            continue;
        }

        if (isset($seenIds[$id])) {
            // Duplicate - skip (keeps first occurrence)
            continue;
        }

        $seenIds[$id] = true;
        $merged[] = $entry;
    }

    return $merged;
}
```

### 5. Integration Points

#### CacheService Changes

**New methods:**
- `loadMetadata(string $cacheKey): ?array` - Load and parse metadata file
- `saveMetadata(string $cacheKey, array $metadata): bool` - Save metadata JSON
- `shouldUseIncrementalUpdate(string $cacheKey, array $options): bool` - Decision logic
- `calculateGapQuery(array $metadata, array $options): ?array` - Calculate gap time range

**Modified methods:**
- `saveToCache()` - Accept time range parameters, save enhanced metadata
- `isCacheFresh()` - Read new metadata format (with fallback to legacy)

#### BaseSolarWindsCommand Changes

**Modified methods:**
- `tryLoadFromCache()` - Check for incremental update opportunity
- `searchLogsWithProgress()` - Implement incremental fetch and merge logic
- `saveResultsToCache()` - Pass time range data to CacheService

**New helper method:**
- `executeIncrementalCacheUpdate(string $query, array $options, array $cachedResults, array $metadata): array`

### 6. Implementation Sequence

**Phase 1: Metadata Enhancement**
1. Add `loadMetadata()` and `saveMetadata()` to CacheService
2. Update `saveToCache()` to write enhanced metadata
3. Update `isCacheFresh()` to read both old and new metadata formats
4. Add tests to verify backwards compatibility

**Phase 2: Gap Calculation**
1. Add `calculateGapQuery()` method
2. Add `shouldUseIncrementalUpdate()` decision logic
3. Unit test gap calculation edge cases

**Phase 3: Merging Logic**
1. Add `mergeAndDeduplicateResults()` helper
2. Test deduplication with various datasets
3. Verify log entry ID field consistency

**Phase 4: Integration**
1. Modify `tryLoadFromCache()` to detect incremental opportunities
2. Add `executeIncrementalCacheUpdate()` to BaseSolarWindsCommand
3. Update `searchLogsWithProgress()` to use incremental logic
4. Update cache save to include time range metadata

**Phase 5: Testing**
1. Test with `--1d`, `--1w`, `--2w` queries
2. Test cache age thresholds (under 50%, over 50%)
3. Test backwards compatibility with existing caches
4. Test multi-batch queries (ExploitsCommand)
5. Verify performance improvement metrics

## Edge Cases and Considerations

### Edge Case 1: Cache Older Than Query Range
**Scenario**: `--1d` query, but cache is 3 days old
**Solution**: Full refresh (cache age > 50% threshold)

### Edge Case 2: Non-Overlapping Time Ranges
**Scenario**: Cached query was for yesterday (`--1D`), current is today (`--1D`)
**Solution**: Full refresh (no overlap to reuse)

### Edge Case 3: Multi-Batch Queries
**Scenario**: ExploitsCommand runs 4 separate batch queries
**Solution**: Each batch has its own cache key, incremental logic applies per-batch independently

### Edge Case 4: Missing Log IDs
**Scenario**: Some log entries don't have `id` field
**Solution**: Include entries without IDs (no deduplication for those)

### Edge Case 5: Corrupted Metadata
**Scenario**: Metadata file is invalid JSON
**Solution**: Treat as missing cache, do full refresh

### Edge Case 6: Time Zone Issues
**Scenario**: Query times use different time zones
**Solution**: All times should already be in UTC (verify in time parsing code)

## Performance Expectations

### Expected Improvements

**Scenario 1: Daily monitoring workflow**
- Query: `exploits --1d` every hour
- Without incremental: 4 batches × full 24h query each time
- With incremental: 4 batches × 1h query + merge (23h cached)
- Expected reduction: ~95% API load

**Scenario 2: Weekly trend analysis**
- Query: `threats --1w` once per day
- Without incremental: Full 7-day query
- With incremental: 1-day query + merge (6 days cached)
- Expected reduction: ~85% API load

### Performance Overhead

**Additional operations:**
- Metadata read/write: Negligible (small JSON files)
- Deduplication: O(n) where n = total entries
- Merge: O(n) array operations

**Net benefit:** Positive for queries ≥ 1 day when cache age < 50%

## Success Criteria

1. ✅ Existing caches continue to work (backwards compatible)
2. ✅ Incremental updates activate automatically for qualifying queries
3. ✅ API call volume reduced by 80%+ for typical monitoring workflows
4. ✅ Result accuracy identical to full refresh
5. ✅ No performance degradation for short queries (--1h, --30m)
6. ✅ Multi-batch queries work correctly
7. ✅ Cache metadata tracks time ranges accurately

## Testing Checklist

- [ ] Legacy metadata files still work
- [ ] New metadata format written correctly
- [ ] Gap calculation correct for various time ranges
- [ ] Deduplication removes all duplicates
- [ ] Incremental threshold (50%) enforced
- [ ] Minimum time range (1d) enforced
- [ ] Multi-batch queries (4+ batches) work
- [ ] `--no-cache` bypasses incremental logic
- [ ] `--cached=0` works with incremental
- [ ] Corrupted cache handled gracefully
- [ ] Performance metrics show improvement

## Implementation Notes

### Debug Output

Add debug messages (when `--debug` enabled):
```
[CACHE] Incremental update opportunity detected
[CACHE] Cached data: 2024-11-15 14:00 to 2024-11-16 04:00 (14 hours)
[CACHE] Fetching gap: 2024-11-16 04:00 to 2024-11-16 14:00 (10 hours)
[CACHE] Merged 1250 cached + 520 fresh = 1770 total (0 duplicates)
[CACHE] Saved updated cache with new time range
```

### Configuration Options

Consider future configuration:
- Threshold percentage (default: 50%)
- Minimum time range (default: 1d)
- Enable/disable incremental updates
- Maximum merge size (safety limit)

## Future Enhancements

- **Cache warming**: Proactively update caches in background
- **Smart prefetch**: Predict next query and prepare cache
- **Distributed cache**: Share cache across multiple machines
- **Compression**: Compress large cached result sets
- **Statistics**: Track cache hit rates and savings
