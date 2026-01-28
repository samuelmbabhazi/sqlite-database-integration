# Research Notes: Real-World Problems Solved

This document catalogs actual bugs, issues, and plugin incompatibilities that the new SQLite driver addressed.

## Plugin Compatibility Issues Fixed

### 1. Gravity Forms - Foreign Key Support (#229)

**Problem**: Importing database dumps containing Gravity Forms tables failed because the plugin uses FOREIGN KEY constraints.

```sql
CREATE TABLE `wp_gravitysmtp_event_tracking` (
  ...
  CONSTRAINT `wp_gravitysmtp_event_tracking_ibfk_1`
  FOREIGN KEY (`event_id`) REFERENCES `wp_gravitysmtp_events` (`id`)
)
```

**Error**: `FOREIGN KEY and CHECK constraints are not supported yet`

**Solution**: PR #237 implemented full FOREIGN KEY constraint support including:
- FOREIGN KEY in CREATE TABLE
- REFERENCES clause in column definitions
- ADD/DROP FOREIGN KEY in ALTER TABLE
- DROP CONSTRAINT clause
- Proper ON DELETE/UPDATE actions

### 2. WooCommerce / Action Scheduler - UPDATE with JOIN (#233)

**Problem**: Action Scheduler (used by WooCommerce) uses UPDATE...JOIN syntax for claiming scheduled actions:

```sql
UPDATE wp_actionscheduler_actions t1
JOIN (
  SELECT action_id FROM wp_actionscheduler_actions
  WHERE claim_id = 0 AND scheduled_date_gmt <= '2025-12-11 00:03:28'
  AND status='pending'
  ORDER BY priority ASC, attempts ASC, scheduled_date_gmt ASC
  LIMIT 25 FOR UPDATE
) t2 ON t1.action_id = t2.action_id
SET claim_id=27263, last_attempt_gmt='2025-12-11 00:03:28'
```

**Error**: `SQLSTATE[HY000]: General error: 1 near "t1": syntax error`

**Solution**: PR #238 implemented UPDATE with multiple tables, translating to:
1. Extract values from the JOIN via subquery
2. Execute UPDATE on the target table only

### 3. Wordfence - TEXT into BLOB Column (#268)

**Problem**: Wordfence stores configuration in a LONGBLOB column but inserts TEXT values:

```sql
INSERT INTO wp_wfconfig (name, val) VALUES ('apiKey', '2')
-- val is LONGBLOB, '2' is TEXT
```

**Error**: `SQLSTATE[23000]: Integrity constraint violation: 19 cannot store TEXT value in BLOB column`

**Solution**: PR #276 implemented comprehensive type casting for INSERT and UPDATE statements. The driver now:
- Retrieves column metadata from information schema
- Applies appropriate CAST() operations
- Handles STRICT vs non-STRICT SQL mode differences

### 4. Various Plugins - CHECK Constraints (#251)

**Problem**: Some plugins use CHECK constraints in table definitions:

```sql
CREATE TABLE t (
  status ENUM('active', 'inactive') CHECK (status IN ('active', 'inactive'))
)
```

**Solution**: PR #257 added CHECK constraint support with proper information schema recording.

### 5. AIOSEO - Parser Errors (#250)

**Problem**: AIOSEO plugin's SQL queries caused parser errors due to complex syntax.

**Solution**: Grammar and parser improvements to handle edge cases.

## WordPress Core Compatibility Issues Fixed

### 6. ORDER BY Ambiguity (#228)

**Problem**: Queries with ambiguous column names in ORDER BY:

```sql
SELECT p.*, m.meta_value
FROM posts p
JOIN postmeta m ON p.ID = m.post_id
ORDER BY meta_value  -- ambiguous!
```

**Error**: `Query Error: ambiguous column name`

**Solution**: PR #232 implemented disambiguation for unqualified columns in ORDER BY by analyzing table references.

### 7. ON UPDATE CURRENT_TIMESTAMP (#148)

**Problem**: WordPress and plugins use auto-updating timestamp columns:

```sql
CREATE TABLE t (
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)
```

SQLite doesn't support ON UPDATE natively.

**Solution**:
- Record the attribute in information schema
- Intercept UPDATE statements
- Automatically set timestamp on row modification

### 8. Transaction Savepoints (#188)

**Problem**: Nested transaction support needed for complex operations.

**Solution**: PR #221 added:
- SAVEPOINT support
- RELEASE SAVEPOINT
- ROLLBACK TO SAVEPOINT
- Table locking statements (LOCK TABLES, UNLOCK TABLES)

### 9. Key Length Issues (#167, #124)

**Problem**: Index names generated were too long for some operations.

**Solution**: Proper key length handling and identifier management.

### 10. Empty Default Timestamp (#165)

**Problem**: CREATE TABLE with empty default timestamp values failed.

**Solution**: Proper handling of datetime fields with empty defaults.

## Information Schema Issues Fixed

### 11. Complex Information Schema Queries (#208)

**Problem**: Queries like:
```sql
SELECT * FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'wp'
ORDER BY ORDINAL_POSITION
```

**Solution**: Full information schema emulation with WHERE, ORDER BY, and AS support.

### 12. SHOW COLUMNS with LIKE (#211)

**Problem**: LIKE clause in SHOW COLUMNS was being applied to table name instead of column name.

**Solution**: Fixed condition application in SHOW statement translation.

## Cron and Background Processing Issues

### 13. WP-Cron PDO Exceptions (#206)

**Problem**: Uncaught PDOException when running wp-cron tasks.

**Solution**: Better error handling and transaction management.

### 14. WooCommerce Cron Jobs (#210)

**Problem**: Errors when running WooCommerce CRON jobs.

**Solution**: Multiple fixes including UPDATE...JOIN support and better transaction handling.

## Migration and Tooling Issues

### 15. Legacy Driver Migration

**Problem**: Users with databases created by the old driver needed to migrate to the new one.

**Solution**:
- WP_SQLite_Configurator handles database initialization
- WP_SQLite_Information_Schema_Reconstructor rebuilds metadata
- Automatic migration when enabling new driver
- Uses wp_get_db_schema() for accurate WordPress table definitions

### 16. Query Monitor Compatibility (#212)

**Problem**: Query Monitor plugin also uses db.php drop-in, causing conflicts.

**Solution**:
- Detect and override Query Monitor's db.php
- Boot Query Monitor eagerly when detected
- Store enhanced query information for QM to consume
- Extend QM panel with SQLite query details

### 17. phpMyAdmin/Adminer Support (#269)

**Problem**: Database administration tools expected MySQL protocol.

**Solution**: PR #272 implemented MySQL binary protocol proxy:
- Full MySQL wire protocol implementation
- Authentication handling
- Query execution through driver
- Proper result set formatting

## PHP Version Compatibility

### 18. PHP 8.4 Compatibility (#43, #234)

**Problem**: Deprecation warnings and compatibility issues with newer PHP.

**Solution**: Multiple fixes for PHP 8.4 and 8.5 compatibility.

### 19. PDO Bug Workarounds

**Problem**: PDO::inTransaction() unreliable in PHP < 8.4

**Solution**: Internal tracking of transaction state:
```php
// Polyfill for PHP < 8.4 PDO bug
// @see https://bugs.php.net/bug.php?id=81227
private $in_transaction = false;
```

## SQLite Version Compatibility

### 20. Legacy SQLite Support (#252, #293, #302)

**Problem**: Some environments have older SQLite versions (< 3.37.0).

**Solution**:
- WP_SQLITE_UNSAFE_ENABLE_UNSUPPORTED_VERSIONS flag
- PRAGMA writable_schema=ON for STRICT table compatibility
- Fallbacks for missing features (e.g., VALUES column naming in < 3.33.0)

## Summary Statistics

From the GitHub issue tracker:
- **30+ issues** closed related to the new driver
- Major plugin compatibility: WooCommerce, Gravity Forms, Wordfence, AIOSEO
- Core WordPress features: all passing tests
- PHP versions: 7.2 through 8.5 supported
- SQLite versions: 3.27.0+ (with flag), 3.37.0+ (recommended)
