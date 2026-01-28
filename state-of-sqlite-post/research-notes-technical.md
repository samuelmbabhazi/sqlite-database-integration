# Research Notes: Technical Deep Dive

This document captures the technical implementation details and complexities of the new SQLite driver.

## File Statistics

| File | Lines | Description |
|------|-------|-------------|
| class-wp-pdo-mysql-on-sqlite.php | 6,731 | Main driver class |
| class-wp-mysql-lexer.php | 2,997 | MySQL lexer |
| class-wp-sqlite-information-schema-builder.php | 3,150 | Information schema builder |
| mysql-grammar.php | ~70kb | Compressed MySQL grammar |
| **Total core files** | ~13,000+ | |

## Architectural Decisions

### 1. AST-Based Translation vs Token Processing

The old driver used token-based processing:
```
MySQL Query → Tokenize → Pattern Match → Transform → SQLite Query
```

The new driver uses AST-based translation:
```
MySQL Query → Lexer → Tokens → Parser → AST → Translate → SQLite Queries
```

Benefits of AST approach:
- Complete understanding of query structure
- Easier to handle nested constructs (subqueries, CTEs)
- More maintainable transformation rules
- Better error handling and reporting
- Supports complex MySQL features

### 2. Information Schema Emulation

Rather than trying to translate MySQL information_schema queries on-the-fly, the driver maintains shadow tables in SQLite that mirror MySQL's INFORMATION_SCHEMA:

Tables implemented:
- `_wp_sqlite_tables` - mirrors INFORMATION_SCHEMA.TABLES
- `_wp_sqlite_columns` - mirrors INFORMATION_SCHEMA.COLUMNS
- `_wp_sqlite_statistics` - mirrors INFORMATION_SCHEMA.STATISTICS
- `_wp_sqlite_table_constraints`
- `_wp_sqlite_referential_constraints`
- `_wp_sqlite_key_column_usage`
- `_wp_sqlite_check_constraints`
- `_wp_sqlite_schemata`

When a DDL statement is executed:
1. Parse the MySQL statement into AST
2. Record metadata in information schema tables
3. Generate SQLite CREATE TABLE from information schema
4. Execute the SQLite statement

Benefits:
- SHOW CREATE TABLE returns original MySQL syntax
- Complex information_schema queries work correctly
- ALTER TABLE operations preserve metadata
- Proper constraint handling

### 3. STRICT Table Mode in SQLite

The driver requires SQLite >= 3.37.0 for STRICT table support:
- STRICT tables enforce type affinity
- Prevents unexpected type coercion
- More MySQL-like behavior

For legacy SQLite (with `WP_SQLITE_UNSAFE_ENABLE_UNSUPPORTED_VERSIONS`):
- Uses `PRAGMA writable_schema=ON`
- Allows reading databases created with newer SQLite
- Not recommended for production

## Key Implementation Tricks

### 1. INSERT/UPDATE Type Casting and Implicit Defaults

MySQL has complex behavior for INSERT/UPDATE depending on SQL mode:

**The problem**: MySQL's STRICT vs non-STRICT mode behavior differs significantly.

**The solution**: Rewrite INSERT statements as:
```sql
-- Original:
INSERT INTO table (col1, col2) VALUES (val1, val2)

-- Transformed to:
INSERT INTO table (all_columns)
SELECT <adjusted-values> FROM (VALUES (val1, val2)) WHERE true
```

The wrapper SELECT applies:
1. Type casting based on column data type
2. IMPLICIT DEFAULT values for missing columns (non-strict mode)
3. NULL handling based on column constraints

Example implicit defaults (non-strict mode):
- INT: 0
- VARCHAR: ''
- DATE: '0000-00-00'
- DATETIME: '0000-00-00 00:00:00'
- JSON: 'null' (string, not NULL)

### 2. ALTER TABLE Implementation

SQLite has limited ALTER TABLE support. The driver handles this by:

1. Recording changes in information schema
2. Recreating the table from information schema:
   - Create new table with updated schema
   - Copy data with column mapping (handles renames)
   - Drop old table
   - Rename new table
   - Recreate indexes and constraints

Column tracking during ALTER:
- Tracks column renames (CHANGE/RENAME)
- Tracks column removals (DROP)
- Preserves ROWIDs when possible

### 3. Multi-Table DELETE

MySQL supports: `DELETE t1, t2 FROM t1 JOIN t2 ON ... WHERE ...`

SQLite doesn't support this directly. The driver:
1. Creates alias-to-table mapping
2. Executes SELECT to get ROWIDs from all target tables
3. Executes separate DELETE for each table using ROWIDs

```sql
-- Step 1: Get ROWIDs
SELECT t1.rowid AS t1_rowid, t2.rowid AS t2_rowid
FROM t1 JOIN t2 ON ... WHERE ...

-- Step 2: Delete from each table
DELETE FROM t1 WHERE rowid IN (...)
DELETE FROM t2 WHERE rowid IN (...)
```

### 4. UPDATE with Multiple Tables

MySQL: `UPDATE t1 JOIN t2 SET t1.col = ...`

Similar approach to multi-table DELETE:
1. Identify the single table being modified
2. Use subquery to get values from joined tables
3. Execute UPDATE on target table

### 5. SQL_CALC_FOUND_ROWS / FOUND_ROWS()

MySQL optimization for pagination. The driver:
1. Detects SQL_CALC_FOUND_ROWS in SELECT
2. Stores count or query for later retrieval
3. FOUND_ROWS() returns the stored value

Options for storage:
- Integer: direct count
- String: query to execute for count
- Array: query + parameters for count

### 6. ON UPDATE CURRENT_TIMESTAMP

MySQL columns can have `ON UPDATE CURRENT_TIMESTAMP`.

SQLite doesn't support this natively. The driver:
1. Records the column attribute in information schema
2. Handles timestamp updates during UPDATE translation

### 7. LIKE with Case Sensitivity

MySQL `LIKE` is case-insensitive by default.
SQLite `LIKE` is case-sensitive for non-ASCII.

The driver uses `ESCAPE '\'` and handles collation differences.

### 8. BINARY Comparisons

MySQL `LIKE BINARY` forces case-sensitive comparison.

Translated to SQLite with appropriate GLOB patterns or custom functions.

### 9. VALUES Clause Column Naming

SQLite < 3.33.0 doesn't name columns in VALUES clauses.

For older versions, the driver prepends a dummy SELECT:
```sql
SELECT NULL AS `column1`, NULL AS `column2` WHERE FALSE
UNION ALL
VALUES (value1, value2)
```

### 10. Transaction Wrapping

Each MySQL query may translate to multiple SQLite queries. The driver:
1. Begins a wrapper transaction (or savepoint if nested)
2. Executes all SQLite queries
3. Commits on success, rolls back on failure

BEGIN vs BEGIN IMMEDIATE:
- Read-only queries: BEGIN
- Write queries: BEGIN IMMEDIATE (avoids SQLITE_BUSY)

## MySQL Protocol Implementation

The MySQL proxy implements the MySQL wire protocol:

### Protocol Version
- Uses protocol version 10 (since MySQL 3.21.0)

### Capability Flags
Full support for:
- CLIENT_PROTOCOL_41
- CLIENT_SECURE_CONNECTION
- CLIENT_PLUGIN_AUTH
- CLIENT_CONNECT_WITH_DB
- CLIENT_MULTI_STATEMENTS
- CLIENT_MULTI_RESULTS
- And many more...

### Command Types Supported
- COM_QUERY (execute SQL)
- COM_PING (connection check)
- COM_INIT_DB (change database)
- COM_QUIT (close connection)

### Authentication
- mysql_native_password
- caching_sha2_password (basic support)

### Field Types
Complete mapping from MySQL field types to wire format:
- FIELD_TYPE_TINY through FIELD_TYPE_GEOMETRY
- Proper flags for NOT_NULL, PRIMARY_KEY, etc.

## Parser Implementation Details

### Grammar Processing Pipeline

1. **Parse ANTLR4 Grammar**
   - Read MySQLParser.g4
   - Parse into PHP tree structure

2. **Flatten Grammar**
   - Convert nested rules to top-level rules
   - Generate names for anonymous fragments

3. **Expand Modifiers**
   - `*` (zero or more) → right-recursive rule
   - `+` (one or more) → right-recursive rule
   - `?` (optional) → rule with epsilon alternative

4. **Compress and Export**
   - Replace string names with integers
   - Create name-to-ID mapping
   - Export as PHP array

### Parser Optimization

Key optimizations applied:
- Lookahead tables for early branch rejection
- Fragment rules for memory efficiency
- Integer IDs instead of string comparisons

Performance: ~1000 complex SELECT queries/second on MacBook Pro

### Version-Aware Parsing

The lexer supports SQL mode flags that affect tokenization:
- HIGH_NOT_PRECEDENCE
- PIPES_AS_CONCAT
- IGNORE_SPACE
- NO_BACKSLASH_ESCAPES

## User-Defined Functions

PHP implementations of MySQL functions for SQLite:

### Date/Time Functions
- `month($date)` → Extract month
- `year($date)` → Extract year
- `day($date)` → Extract day
- `unix_timestamp($date)` → UNIX timestamp
- `from_unixtime($timestamp)` → Date from timestamp
- `now()` → Current timestamp
- `curdate()` → Current date
- `datediff($date1, $date2)` → Days between dates

### String Functions
- `md5($string)` → MD5 hash
- `ucase($string)` → Uppercase
- `lcase($string)` → Lowercase
- `unhex($hex)` → Hex to binary
- `locate($needle, $haystack)` → Position in string

### Numeric Functions
- `rand()` → Random number (0-1)
- `log($n)` → Natural logarithm
- `least(...)` → Minimum value
- `greatest(...)` → Maximum value

### Network Functions
- `inet_ntoa($int)` → Integer to IP
- `inet_aton($ip)` → IP to integer

### Control Flow
- `if($condition, $then, $else)` → Conditional
- `isnull($value)` → NULL check

### Locking (Emulated)
- `get_lock($name, $timeout)` → Advisory lock
- `release_lock($name)` → Release lock

## Column Metadata Implementation

The driver provides MySQL-compatible column metadata:

### From Information Schema
For table columns, metadata is retrieved from `_wp_sqlite_columns`:
- `native_type`: TINY, SHORT, LONG, LONGLONG, etc.
- `mysqli_type`: Numeric type ID
- `len`: Column length
- `precision`: Decimal precision
- `flags`: not_null, primary_key, unique_key, etc.

### For Expressions
For computed columns/expressions, metadata is derived from SQLite:
- Maps SQLite types to MySQL types
- INT/INTEGER → LONGLONG
- TEXT → BLOB or VAR_STRING
- REAL → DOUBLE

### MySQLi Compatibility
Additional fields for mysqli compatibility:
- `mysqli:orgname`: Original column name
- `mysqli:orgtable`: Original table name
- `mysqli:db`: Database name
- `mysqli:charsetnr`: Character set number
- `mysqli:flags`: MySQL column flags
- `mysqli:type`: MySQL type code

## Testing Approach

### MySQL Query Test Suite
- ~70,000 queries extracted from MySQL server tests
- Tests lexer and parser compatibility
- Ensures grammar coverage

### WordPress Core Tests
- PHPUnit test suite from WordPress
- Over 99% tests passing
- Validates WordPress compatibility

### Driver-Specific Tests
- Translation tests (MySQL → SQLite)
- Query execution tests
- Information schema tests
- Metadata tests
- PDO API tests

## Integration Points

### Query Monitor
- Hooks into WordPress query logging
- Displays SQLite queries alongside MySQL
- Shows correspondence between MySQL and SQLite queries

### WordPress Playground
- Powers playground.wordpress.net
- Database pane with Adminer/phpMyAdmin access
- File browser integration

### Desktop Apps (WordPress Studio)
- Uses the SQLite driver for local development
- No MySQL server required
