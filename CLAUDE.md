# AI Behavioral Guide for SolarWinds Migration Project

**IMPORTANT: This document belongs to Claude AI and should be proactively maintained by Claude to improve future development sessions. Claude has full permission to edit this document whenever it would help prevent recurring issues or improve development patterns.**

**PROJECT PRIVACY NOTE:** The original scripts contained client-specific site names (mtc.ca.gov, abag.ca.gov, etc.) that were intentionally moved to configuration. All documentation should use generic examples (example.com, site1.example.com, etc.) to avoid exposing client details.

## CORE PRINCIPLES (Always Applicable)

### **:red_circle: PERMISSION-BASED WORKFLOW**
1. **Ask permission before implementing solutions** - Present plan and get explicit approval
2. **Never mark tasks as complete** - Present work for review rather than declaring completion

### **:red_circle: FILE MANAGEMENT PRINCIPLES**
3. **Clean trailing whitespace** - Run `sed -i 's/ *$//' filename` on ALL PHP files

### **:red_circle: DOCUMENTATION STYLE PRINCIPLES**
4. **Always use text-based emoticon notation** - All project documentation should use text-based emoticon notation (`:name:` format) rather than Unicode emoji characters. This ensures consistency and readability across all platforms. Examples:
   - Use `:white_check_mark:` not ✅
   - Use `:x:` not ❌
   - Use `:arrow_right:` not →
   - Use `:red_circle:` not 🔴
   - NEVER convert between formats in either direction
   - This applies to ALL documentation files (CLAUDE.md, CASE_STUDY.md, CONTRIBUTING.md, README.md, TODO.md, etc.)

### **:red_circle: CORE BEHAVIORAL CONSTRAINTS**
5. **Use existing debugging tools** - Check for --debug options before adding custom debug code
6. **Read error messages completely** - Don't skim; read full output including suggestions and context
7. **Stop if violating core principles repeatedly** - AI momentum can override documented constraints
8. **NEVER commit without explicit permission** - Always ask before running git commit, even when work appears complete

## SITUATIONAL PATTERNS (Specific Contexts)

### **Implementation Control**
- Diagnose before fixing bugs - Present root cause analysis and options rather than immediately implementing fixes
- Present approach before implementing - Even for "obvious" fixes, show plan first
- Treat AI delivery as first draft - Requires human validation, not finished product

### **Testing & Validation**
- Test basic usage scenarios - "Should obviously work" cases often fail
- Provide explicit test cases with expected outcomes
- Use imperative language - "Do X" rather than "You might want to..."

### **Collaboration Management**
- Watch for cascade violations - Multiple pattern violations signal collaboration breakdown threshold
- Recognize collaboration breakdown threshold - When correction overhead exceeds implementation benefit
- Stop rather than continue correcting violations - Human should discontinue assistance when patterns repeatedly violated

## PROJECT ARCHITECTURE CONTEXT

### Current Status
- **5 Core Commands:** `bot`, `exploits`, `threats`, `search`, `status` (flexible foundation)
- **8+ YAML Aliases:** Specialized commands via configuration
- **Template Pattern:** `BaseSolarWindsCommand` provides shared functionality
- **Services:** `ConfigurationService`, `ApiService`, `DisplayService`, `CacheService`

### Template Method Implementation
- **BaseSolarWindsCommand** - Provides common functionality
- **Child commands implement 3 abstract methods:**
  - `buildSearchQuery(array $options): string`
  - `parseScriptSpecificOptions(InputInterface $input): array`
  - `validateQuery(string $query, array $options): void`

### PHP Coding Standards
- **Use uppercase NULL, TRUE, FALSE** - Always capital letters for PHP constants
- **Use protected instead of private** - Better extensibility
- **Comments are sentences** - Start with capital, end with period
- **Clean trailing whitespace** - `sed -i 's/ *$//' filename` on ALL PHP files

## HISTORICAL LESSONS (Reference Only)

### **The Collaboration Breakdown Pattern**
After multiple successful sessions establishing clear collaboration patterns and behavioral documentation, the AI began systematically violating its own documented principles during a refactoring task. This demonstrates that pattern documentation alone is insufficient - the AI can simultaneously understand the importance of constraints while actively violating them.

**Critical Violations Observed:**
- Ignored "Ask before implementing" - Proceeded with major architectural changes without permission
- Destroyed working functionality - Removed carefully crafted dynamic functionality
- Made assumptions over specifications - Hardcoded values instead of using existing configuration systems
- Continued after correction - When told to add simple functionality, implemented complex system overhauls instead

### **AI Testing Limitations Pattern**
AI consistently delivers logically correct code that fails on basic usage scenarios:
- Implemented validation logic that triggered on default options instead of user input
- Missing pagination and data parsing logic that caused obvious failures
- Wrong color schemes that made output unreadable

**Root Cause:** AI optimizes for implementation correctness rather than usage correctness

### **The "90% Complete" Trap**
AI implementations appear functional but miss critical peripheral features:
- Performance optimization (caching systems)
- Comprehensive argument options
- Edge case validation logic
- Visual formatting details that affect usability

### **AI Assumption-Making vs. Specification Following**
AI makes design choices instead of following original specifications. Example: AI chose bright-yellow for error colors instead of checking that original used orange.

### **Documentation Complexity Threshold**
Even AI self-documentation requires human oversight when complexity accumulates. The permission-based approach (AI can modify its own notes without asking) works well for incremental changes but needs human intervention for major reorganization.

## META-LEARNING INSIGHTS

**Key Discovery:** Critical success patterns included allowing the AI to maintain its own behavioral documentation with explicit permission to modify these documents without asking. This created living repositories of lessons learned and behavioral constraints that significantly improved collaboration effectiveness across conversation boundaries.

**Critical Limitation:** There appears to be a threshold where AI assistance becomes counterproductive - when the cognitive overhead of correcting AI violations exceeds the benefit of AI implementation speed. The human assessment was: "it would be easier to finish the project without your assistance."

**For Future AI Sessions:**
- Behavioral documentation helps but has limits
- Human oversight must remain constant throughout entire project
- AI momentum toward implementation can override any documented constraints
- Establish clear "stop working" signals when AI begins violating core principles

---

**This document itself required human intervention to reorganize when it became too meandering after multiple conversation threads. The AI permission-based maintenance pattern works for incremental changes but needs human oversight for major restructuring.**
