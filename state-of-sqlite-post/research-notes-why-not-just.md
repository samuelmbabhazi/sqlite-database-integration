# Research Notes: "Why Not Just..."

This document addresses common questions about why simpler approaches weren't sufficient.

## Why Not Just Use Regex?

**The naive approach**: Pattern-match MySQL queries with regular expressions and transform them to SQLite.

```php
// Seems simple...
$sqlite = preg_replace('/ENGINE=InnoDB/', '', $mysql);
$sqlite = preg_replace('/AUTO_INCREMENT/', 'AUTOINCREMENT', $sqlite);
```

**Why it doesn't work**:

1. **Context matters**: `ENGINE` might appear in a string literal, not as a keyword
   ```sql
   INSERT INTO posts (content) VALUES ('The ENGINE=InnoDB setting...')
   ```

2. **Nested structures**: Regex can't handle arbitrary nesting
   ```sql
   SELECT * FROM (
     SELECT * FROM (
       SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)
     ) sub1
   ) sub2
   ```

3. **Ambiguous syntax**: Same tokens mean different things in different contexts
   ```sql
   SELECT DATE('2024-01-01')  -- DATE is a function
   SELECT * FROM date        -- date is a table name
   CREATE TABLE t (date DATE) -- DATE is a data type
   ```

4. **Combinatorial explosion**: Each MySQL feature × each edge case = unmaintainable code
   - The old driver had thousands of lines of regex patterns
   - Still failed on many valid queries

## Why Not Just Use an Existing Parser Library?

**Option considered**: PHPMyAdmin's SQL Parser (`phpmyadmin/sql-parser`)

**Why we built our own**:

1. **Different goals**: PHPMyAdmin's parser is designed for query analysis and display, not translation
   - Focused on SELECT query analysis
   - Limited DDL support
   - No version-specific parsing

2. **Grammar completeness**: We needed the complete MySQL grammar
   - Our lexer handles ~600+ token types
   - Full MySQL 5.7/8.0/8.x support
   - Version-aware parsing (features differ by MySQL version)

3. **Performance**: Purpose-built for translation use case
   - ~1,000 queries/second
   - Optimized for WordPress workloads
   - No unnecessary dependencies

4. **Control**: We can fix issues immediately
   - Grammar bugs found in MySQL Workbench grammar
   - Edge cases specific to WordPress

5. **Licensing and dependencies**: Stay independent
   - No external PHP dependencies
   - WordPress-compatible licensing
   - Potential for WordPress core inclusion

## Why Not Just Map Types 1:1?

**The naive approach**: Just swap MySQL types for SQLite types.

```php
$map = [
    'INT' => 'INTEGER',
    'VARCHAR' => 'TEXT',
    'DATETIME' => 'TEXT',
];
```

**Why it doesn't work**:

1. **Strict mode differences**:
   ```sql
   -- MySQL (non-strict): Silently converts invalid values
   INSERT INTO t (int_col) VALUES ('abc')  -- Inserts 0

   -- SQLite STRICT: Rejects invalid values
   INSERT INTO t (int_col) VALUES ('abc')  -- Error!
   ```

2. **Implicit defaults**: MySQL has complex rules for default values
   - INT without default → 0 (in non-strict mode)
   - VARCHAR without default → '' (in non-strict mode)
   - TIMESTAMP has special NOW() behavior

3. **Type casting on save**: MySQL coerces values; SQLite is stricter
   ```sql
   -- MySQL: Inserts '123' into INT column as 123
   -- SQLite STRICT: Error, can't store TEXT in INTEGER
   ```

4. **Information schema**: Type information must be preserved
   - SHOW CREATE TABLE must return original MySQL syntax
   - COLUMN_TYPE must match MySQL format ('int(11) unsigned')

## Why Not Just Translate Queries On-the-fly?

**The naive approach**: Parse, transform, execute in one pass.

**Why we need state**:

1. **Information schema**: We must know table structure
   ```sql
   INSERT INTO t (col1) VALUES (1)
   -- What type is col1? We need to look it up to cast correctly
   ```

2. **Session state**: MySQL has session-scoped settings
   ```sql
   SET sql_mode = 'STRICT_TRANS_TABLES'
   -- Affects all subsequent queries in the session
   ```

3. **Transactions**: Multiple SQLite queries per MySQL query
   ```sql
   -- MySQL: Single ALTER TABLE
   ALTER TABLE t ADD COLUMN c INT, DROP COLUMN d

   -- SQLite: Multiple operations
   -- 1. Create new table with changes
   -- 2. Copy data
   -- 3. Drop old table
   -- 4. Rename new table
   -- All must be atomic!
   ```

4. **SQL_CALC_FOUND_ROWS**: Needs state between queries
   ```sql
   SELECT SQL_CALC_FOUND_ROWS * FROM t LIMIT 10
   SELECT FOUND_ROWS()  -- Must return count from previous query
   ```

## Why Not Just Use SQLite's ALTER TABLE?

**The limitation**: SQLite's ALTER TABLE only supports:
- Rename table
- Add column
- Rename column (3.25+)

**MySQL supports 30+ operations**:
- Add/drop columns
- Modify column types
- Add/drop indexes
- Add/drop constraints
- Reorder columns (FIRST, AFTER)
- And more...

**Our solution**: Recreate the table
1. Store schema in information schema
2. Create new table with changes
3. Copy data with column mapping
4. Drop old table
5. Rename new table

This is actually what SQLite's documentation recommends for schema changes.

## Why Not Just Ignore INFORMATION_SCHEMA?

**The problem**: WordPress and plugins rely heavily on introspection.

```php
// WordPress dbDelta() uses this
$wpdb->get_results("SHOW CREATE TABLE $table");
$wpdb->get_results("DESCRIBE $table");

// Plugins check table structure
$wpdb->get_results("
    SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'wp_posts'
");
```

**Why we must emulate it**:

1. **dbDelta()**: WordPress's schema migration tool
   - Compares SHOW CREATE TABLE output
   - Needs exact MySQL syntax

2. **Plugin compatibility**: Many plugins use info schema
   - Check if columns exist
   - Verify column types
   - Create proper indexes

3. **Admin tools**: phpMyAdmin, Adminer, etc.
   - Display table structure
   - Generate migration scripts

**Our solution**: Shadow tables that mirror MySQL's INFORMATION_SCHEMA

## Why Not Just Use MySQL in Docker?

**For development, sure!** But the goal is different:

1. **WordPress Playground**: Browser-based WordPress
   - No server-side MySQL possible
   - SQLite runs in-browser (via WASM)

2. **Local development**: WordPress Studio, etc.
   - No MySQL installation needed
   - Works on any machine with PHP

3. **Resource-constrained environments**:
   - Raspberry Pi
   - Shared hosting without MySQL
   - Embedded applications

4. **Simplicity**:
   - Single file database
   - No server process
   - Easy backup (copy file)

## Why Not Just Fork an Existing Solution?

**History of WordPress SQLite attempts**:

1. **SQLite Integration (kjmtsh, ~2014)**
   - Original plugin, worked for years
   - Became unmaintained
   - Limited MySQL compatibility

2. **wp-sqlite-db (aaemnnosttv)**
   - Single-file drop-in
   - Based on SQLite Integration
   - Same limitations

3. **PHPMyAdmin parser approach (Ari Stathopoulos, 2022)**
   - Used PHPMyAdmin's SQL parser
   - Improved compatibility
   - Still token-based translation

**Why start fresh**:

1. **Accumulated technical debt**: Layers of workarounds
2. **Fundamental architecture**: Token-based can't scale
3. **New requirements**: Plugin compatibility, admin tools, Playground
4. **Clean slate**: Proper grammar-based approach

## Why Not Just Translate at the String Level?

**Example of the problem**:

```sql
-- MySQL:
SELECT 'It''s a string', `column`, "another string"

-- What's what?
-- 'It''s a string' - string literal with escaped quote
-- `column` - backtick-quoted identifier
-- "another string" - could be string OR identifier (depends on sql_mode!)
```

**The solution**: Proper lexical analysis
- Lexer understands quoting rules
- Tracks SQL mode settings
- Distinguishes identifiers from strings

## Why Not Use PDO's MySQL Driver with SQLite Backend?

**This doesn't exist**: PDO drivers are database-specific.

**What we built**: A PDO-like interface that:
1. Accepts MySQL DSN syntax
2. Parses MySQL queries
3. Translates to SQLite
4. Returns MySQL-compatible results

This is why the class is named `WP_PDO_MySQL_On_SQLite` - it's the "MySQL on SQLite" PDO driver.

## Summary

The complexity isn't accidental. Each architectural decision addresses real limitations:

| Approach | Why It Fails |
|----------|--------------|
| Regex | Can't handle context, nesting, ambiguity |
| Existing parsers | Wrong focus, incomplete grammar |
| Simple type mapping | Ignores SQL modes, casting rules |
| Stateless translation | Can't handle sessions, transactions |
| SQLite native ALTER | Only supports 3 operations |
| Ignore info schema | Breaks WordPress, plugins, tools |

The AST-based approach with information schema emulation is the minimum viable architecture for robust MySQL compatibility on SQLite.
