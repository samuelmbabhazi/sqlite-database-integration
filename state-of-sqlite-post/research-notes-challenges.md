# Research Notes: Challenges and Solutions

This document captures the key challenges encountered during the SQLite driver development and how they were addressed.

## Challenge 1: MySQL Syntax Complexity

**Problem**: MySQL has an enormous SQL dialect with version-specific features, complex syntax variations, and edge cases.

**Solution**:
- Adapted the MySQL Workbench ANTLR4 grammar (official MySQL grammar)
- Built a custom grammar conversion pipeline to PHP
- Created an exhaustive lexer supporting all MySQL syntax since 5.7
- Test suite of ~70,000 MySQL queries for validation
- Version-aware parsing with SQL mode support

**Key Insight**: Rather than trying to handle MySQL syntax ad-hoc, investing in a complete grammar pays off in reliability and maintainability.

## Challenge 2: Information Schema Emulation

**Problem**: WordPress and plugins rely heavily on `SHOW CREATE TABLE`, `SHOW COLUMNS`, and `INFORMATION_SCHEMA` queries to understand database structure. SQLite has completely different introspection mechanisms.

**Solution**:
- Create shadow tables in SQLite that mirror MySQL's INFORMATION_SCHEMA
- Intercept DDL statements and record metadata
- Generate SQLite schema from information schema data
- Reconstruct information schema on migration from legacy driver

**Tables Implemented**:
- SCHEMATA, TABLES, COLUMNS
- STATISTICS, TABLE_CONSTRAINTS
- REFERENTIAL_CONSTRAINTS, KEY_COLUMN_USAGE
- CHECK_CONSTRAINTS

**Benefit**: SHOW CREATE TABLE returns the exact MySQL syntax used to create the table, even though it's stored in SQLite.

## Challenge 3: ALTER TABLE Limitations

**Problem**: SQLite has very limited ALTER TABLE support - it can only:
- Rename tables
- Add columns
- Rename columns (SQLite 3.25+)

MySQL supports 30+ ALTER TABLE operations.

**Solution**:
The driver implements ALTER TABLE by:
1. Recording changes in information schema
2. Recreating the table from scratch:
   - Create new table with updated schema
   - Copy data with proper column mapping
   - Recreate all indexes
   - Recreate all constraints
   - Drop old table, rename new table

**Column Tracking**: The driver tracks:
- Column renames (CHANGE, RENAME)
- Column drops
- Column type changes
- Column reordering (FIRST, AFTER)

## Challenge 4: STRICT vs Non-STRICT SQL Mode

**Problem**: MySQL's behavior differs dramatically between STRICT and non-STRICT SQL modes:
- STRICT: Rejects invalid values with errors
- Non-STRICT: Converts invalid values to "implicit defaults"

WordPress historically ran in non-STRICT mode.

**Solution**:
Rewrite INSERT/UPDATE statements to wrap values in type-casting expressions:

```sql
-- Original:
INSERT INTO t (col) VALUES (v)

-- Transformed (non-strict):
INSERT INTO t (col)
SELECT COALESCE(CAST(... AS ...), implicit_default)
FROM (VALUES (v)) WHERE true
```

**Implicit Defaults by Type**:
- Numeric: 0
- String: ''
- Date: '0000-00-00'
- DateTime: '0000-00-00 00:00:00'
- Year: '0000'
- JSON: 'null' (the string)

## Challenge 5: Type Affinity Differences

**Problem**: MySQL enforces column types strictly. SQLite has "type affinity" which is more flexible but can cause issues.

**Example**: Inserting text into a BLOB column fails in SQLite STRICT mode but works in non-STRICT MySQL.

**Solution**:
- Use SQLite STRICT tables (requires SQLite 3.37.0+)
- Apply type casting when saving values
- Use information schema to know column types

## Challenge 6: Multi-Table Operations

**Problem**: MySQL supports multi-table DELETE and UPDATE:
```sql
DELETE t1, t2 FROM t1 JOIN t2 ON ... WHERE ...
UPDATE t1 JOIN t2 SET t1.col = ...
```
SQLite doesn't support these.

**Solution**:
1. Parse the query to identify target tables
2. Execute a SELECT to get ROWIDs of affected rows
3. Execute separate DELETE/UPDATE for each table

## Challenge 7: Transaction Compatibility

**Problem**: MySQL and SQLite handle transactions differently:
- MySQL has implicit transactions
- SQLite has different locking behavior
- Nested transactions don't exist in SQLite

**Solutions**:
1. **Wrapper Transactions**: Wrap each MySQL query in a transaction/savepoint
2. **BEGIN vs BEGIN IMMEDIATE**: Use BEGIN IMMEDIATE for writes to avoid SQLITE_BUSY
3. **Savepoints**: Use savepoints for nested transaction emulation
4. **PHP 8.4 Bug**: Work around PDO::inTransaction() bug in older PHP

## Challenge 8: Column Metadata Compatibility

**Problem**: MySQL tools (phpMyAdmin, Adminer) and WordPress `$wpdb->get_col_info()` expect MySQL-format column metadata from `PDOStatement::getColumnMeta()`.

**Solution**:
Compute MySQL-compatible metadata from:
1. Information schema (for table columns)
2. SQLite column metadata (for expressions)

**Metadata Fields**:
- native_type, mysqli_type, len, precision
- flags (not_null, primary_key, etc.)
- table, name, db
- charsetnr (character set number)

## Challenge 9: Function Compatibility

**Problem**: MySQL has many functions SQLite doesn't support.

**Solution**: User-defined functions in PHP:
- Date/time: MONTH(), YEAR(), NOW(), etc.
- String: MD5(), UCASE(), LCASE()
- Network: INET_NTOA(), INET_ATON()
- Misc: IF(), REGEXP(), FIELD()

**Implementation**: Register functions via `PDO::sqliteCreateFunction()`

## Challenge 10: LIKE Case Sensitivity

**Problem**:
- MySQL LIKE is case-insensitive by default
- SQLite LIKE is case-insensitive only for ASCII

**Solution**: Use proper escaping and handle collation:
```sql
AND column_name LIKE pattern ESCAPE '\'
```

## Challenge 11: Binary/Hex Literals

**Problem**: MySQL supports multiple binary/hex literal formats:
- `0xDEADBEEF`
- `X'DEADBEEF'`
- `0b1010`
- `B'1010'`

SQLite has different syntax.

**Solution**: Translate to SQLite X'...' notation with proper padding:
- Hex literals: Ensure even number of digits
- Binary literals: Convert to hex

## Challenge 12: DEFAULT Expressions

**Problem**: MySQL 8.0+ supports DEFAULT (expression) but SQLite has limitations.

**Solution**:
- Record expression in information schema
- Apply expression during INSERT/UPDATE when DEFAULT is used
- Handle CURRENT_TIMESTAMP specially

## Challenge 13: ON UPDATE CURRENT_TIMESTAMP

**Problem**: MySQL columns can auto-update on row modification.
```sql
updated_at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**Solution**:
- Record attribute in information schema
- Intercept UPDATE statements
- Add timestamp update to UPDATE list

## Challenge 14: Foreign Key Enforcement

**Problem**: SQLite foreign keys are off by default and behave differently.

**Solution**:
- Enable with `PRAGMA foreign_keys = ON`
- Record FK constraints in information schema
- Handle ON DELETE/UPDATE actions

## Challenge 15: db.php Drop-in Conflicts

**Problem**: Both Query Monitor and SQLite plugin need the db.php drop-in file.

**Solution**:
- Detect existing db.php on activation
- Override Query Monitor's db.php
- Eagerly boot Query Monitor when detected
- Store query information for Query Monitor to consume

## Challenge 16: Legacy Migration

**Problem**: Users upgrading from old driver have databases without information schema.

**Solution**: Information Schema Reconstructor:
1. Compare SQLite tables with information schema records
2. For WordPress tables, use wp_get_db_schema() to get accurate definitions
3. For other tables, generate CREATE TABLE from SQLite schema
4. Record all table information in information schema

## Challenge 17: Query Monitor Integration

**Problem**: Query Monitor expects MySQL-specific data and panel rendering.

**Solution**:
- Capture all SQLite queries per MySQL query
- Store mapping for display
- Intercept HTML rendering to add SQLite query details
- Support Playground's different initialization

## Challenge 18: MySQL Protocol for Admin Tools

**Problem**: phpMyAdmin and other tools expect a MySQL server connection.

**Solution**: MySQL Proxy
- Implement MySQL wire protocol in PHP
- Handle authentication handshake
- Translate COM_QUERY commands to driver
- Return results in MySQL wire format

## Open Challenges / TODOs

From code analysis, remaining work includes:
1. Complete PDO API implementation
2. CREATE TABLE ... AS SELECT support
3. More accurate LIKE behavior
4. Better system variable emulation
5. Views and triggers support
6. UPDATE with multiple tables and JOINs
7. More precise column metadata (aliases, original names)
8. ENUM implicit default (first value)
9. Multi-database support
