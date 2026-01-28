# Research Notes: WordPress SQLite Driver Rewrite

This document compiles research gathered from the codebase, git history, GitHub releases, and pull requests for the comprehensive "State of SQLite" post.

## Project Overview

The project represents a fundamental rewrite of the SQLite integration for WordPress, transforming a basic token-based translator into an advanced AST-based MySQL-on-SQLite driver with comprehensive information schema emulation.

### Before: WP_SQLite_Translator (Legacy Driver)
- Token-based query processing
- Limited MySQL compatibility
- Manual, case-by-case query transformations
- No proper information schema support
- Difficult to extend and maintain

### After: WP_PDO_MySQL_On_SQLite (New Driver)
- Full AST-based parsing using custom MySQL lexer and parser
- Comprehensive MySQL information schema emulation
- PDO API implementation (in progress)
- MySQL binary protocol support (MySQL proxy)
- Support for MySQL administration tools (Adminer, phpMyAdmin)
- Extensive test coverage (~70k MySQL queries)

## Timeline of Major Milestones

### Phase 1: Foundation (Nov 2024)
- **Nov 18, 2024**: PR #157 merged - "Exhaustive MySQL Parser"
  - MySQL lexer adapted from AI-generated prototype by @adamziel
  - MySQL grammar from MySQL Workbench, manually adapted
  - Dynamic recursive parser by @adamziel
  - Test suite of ~70k MySQL queries
  - Foundation WIP SQLite driver demo

### Phase 2: Core Development (Dec 2024 - Jan 2025)
- Information schema tables implementation
- CREATE TABLE, ALTER TABLE, INSERT, UPDATE, DELETE support
- Basic SHOW statements
- Core WordPress compatibility

### Phase 3: Feature Parity (Feb - May 2025)
- **Feb 13, 2025**: Internal announcement of new driver (post-2025-02-13.txt)
- Driver passes legacy test suite
- WordPress Playground integration
- Migration support from legacy driver

### Phase 4: Public Release (Jun 2025)
- **v2.2.0 (Jun 2, 2025)**: First release with new driver (behind feature flag)
- **v2.2.1 (Jun 2, 2025)**: Minor fixes
- **v2.2.2 (Jun 6, 2025)**: Public announcement
- **Jun 6, 2025**: Public post on new SQLite driver (post-2025-06-06.txt)

### Phase 5: Advanced Features (Jul - Dec 2025)
- **Jul 2025**: Query Monitor integration (PR #212)
- **Sep 2025**: FOREIGN KEY constraints, UPDATE with multiple tables
- **Oct 2025**: Column metadata, CHECK constraints, phpMyAdmin/Adminer support
- **Nov 2025**: MySQL Proxy for SQLite (PR #272)
- **Dec 2, 2025**: Adminer/phpMyAdmin in WordPress Playground (post-2025-12-02.txt)
- **Dec 2025**: PDO API foundations, transaction improvements

### Phase 6: PDO API & Polish (Jan 2026)
- **v2.2.16 (Jan 15, 2026)**: Latest release
- PDO API implementation progress
- SHOW FULL COLUMNS support
- DEFAULT (expression) support
- Legacy SQLite version compatibility

## Key Components

### 1. MySQL Lexer (`class-wp-mysql-lexer.php`)
- Exhaustive lexer for MySQL SQL dialect
- Multi-version support (MySQL 5.7+)
- Zero dependencies, no PHP extensions required
- No PCRE or regex engines used
- Based on MySQL Workbench lexer grammar
- Supports SQL modes affecting lexer behavior:
  - SQL_MODE_HIGH_NOT_PRECEDENCE
  - SQL_MODE_PIPES_AS_CONCAT
  - SQL_MODE_IGNORE_SPACE
  - SQL_MODE_NO_BACKSLASH_ESCAPES

### 2. MySQL Parser (`class-wp-parser.php`)
- Dynamic recursive descent parser
- Supports LL grammars
- ~100 lines of core parsing logic
- Grammar-driven (rules provided by grammar file)
- Produces complete AST for MySQL queries

### 3. MySQL Grammar (`mysql-grammar.php`)
- Adapted from MySQL Workbench ANTLR4 grammar
- Converted and compressed to PHP array (~70kb)
- Version-aware rules
- Grammar conversion pipeline:
  1. Parse MySQLParser.g4 into PHP tree
  2. Flatten nested rules into top-level rules
  3. Expand *, +, ? modifiers into right-recursive rules
  4. Compress and export as PHP array

### 4. MySQL-on-SQLite Driver (`class-wp-pdo-mysql-on-sqlite.php`)
The main driver class (~81k tokens, very large file). Key features:
- Extends PDO class
- Translates MySQL queries to SQLite
- Maintains MySQL information schema in SQLite
- Emulates MySQL behavior (SQL modes, variables, etc.)
- Requires SQLite >= 3.37.0 (STRICT tables)
- Support for legacy SQLite with `WP_SQLITE_UNSAFE_ENABLE_UNSUPPORTED_VERSIONS`

Key constants/maps:
- DATA_TYPE_MAP: MySQL tokens to SQLite data types
- MYSQL_DATE_FORMAT_TO_SQLITE_STRFTIME_MAP
- DATA_TYPE_IMPLICIT_DEFAULT_MAP (for non-strict mode)
- COLUMN_INFO_MYSQL_TO_NATIVE_TYPES_MAP
- COLUMN_INFO_SQLITE_TO_NATIVE_TYPES_MAP

### 5. Information Schema Builder (`class-wp-sqlite-information-schema-builder.php`)
Builds and maintains MySQL INFORMATION_SCHEMA tables in SQLite:
- SCHEMATA
- TABLES
- COLUMNS
- STATISTICS (indexes)
- TABLE_CONSTRAINTS
- REFERENTIAL_CONSTRAINTS
- KEY_COLUMN_USAGE
- CHECK_CONSTRAINTS

### 6. Information Schema Reconstructor
Handles migration and schema synchronization:
- Detects out-of-sync information schema
- Reconstructs missing table information
- Uses wp_get_db_schema() for WordPress tables
- Removes stale data for dropped tables

### 7. User Defined Functions (`class-wp-sqlite-pdo-user-defined-functions.php`)
PHP implementations of MySQL functions for SQLite:
- Date/time: month, year, day, hour, minute, second, week, etc.
- String: md5, ucase, lcase, unhex, locate
- Network: inet_ntoa, inet_aton
- Misc: rand, if, regexp, field, log, least, greatest
- Locks: get_lock, release_lock
- Version: version()

### 8. MySQL Proxy (`packages/wp-mysql-proxy/`)
Bridges MySQL wire protocol to SQLite driver:
- Enables phpMyAdmin, Adminer, MySQL CLI connectivity
- Implements MySQL protocol constants and helpers
- Client capability flags
- Server status flags
- Command types (COM_QUERY, COM_PING, etc.)
- Field types and flags

## Supported MySQL Features

### Data Definition Language (DDL)
- CREATE TABLE (with full constraint support)
- CREATE INDEX
- ALTER TABLE (all operations through table recreation)
- DROP TABLE (single and multiple)
- TRUNCATE TABLE
- TEMPORARY tables

### Data Manipulation Language (DML)
- INSERT (including INSERT IGNORE, ON DUPLICATE KEY UPDATE)
- INSERT INTO ... SET ...
- INSERT INTO ... SELECT
- REPLACE
- UPDATE (including multi-table with JOIN)
- DELETE (including multi-table)
- SELECT (full support including subqueries)

### Query Features
- UNION / UNION ALL
- Common Table Expressions (WITH)
- Subqueries
- JOINs (all types)
- ORDER BY, GROUP BY, HAVING
- LIMIT with OFFSET
- DISTINCT / DISTINCTROW
- SQL_CALC_FOUND_ROWS / FOUND_ROWS()
- Derived tables

### SHOW Statements
- SHOW TABLES
- SHOW TABLE STATUS
- SHOW COLUMNS / SHOW FULL COLUMNS
- SHOW CREATE TABLE
- SHOW INDEX
- SHOW GRANTS
- SHOW DATABASES
- LIKE/WHERE clauses in all SHOW statements

### Other
- DESCRIBE / EXPLAIN
- USE <database>
- SET (SQL modes, variables)
- Transactions (BEGIN, COMMIT, ROLLBACK, SAVEPOINT)
- Table locking (LOCK TABLES, UNLOCK TABLES)
- ANALYZE, CHECK, OPTIMIZE, REPAIR TABLE
- INFORMATION_SCHEMA queries

### SQL Modes Supported
- STRICT_TRANS_TABLES
- STRICT_ALL_TABLES
- NO_BACKSLASH_ESCAPES
- ERROR_FOR_DIVISION_BY_ZERO
- NO_ENGINE_SUBSTITUTION
- NO_ZERO_DATE
- NO_ZERO_IN_DATE
- ONLY_FULL_GROUP_BY

### Constraints
- PRIMARY KEY
- UNIQUE
- FOREIGN KEY (with ON DELETE/UPDATE actions)
- CHECK constraints
- NOT NULL
- DEFAULT (including expressions)
- AUTO_INCREMENT

## GitHub Statistics

### Releases (v2.2.x line)
- v2.2.0 (Jun 2, 2025): Initial release with new driver
- v2.2.1 - v2.2.16: Continuous improvements
- 17 releases in the v2.2.x line

### Key Pull Requests
- #157: Exhaustive MySQL Parser (foundational PR)
- #212: Query Monitor support
- #272: MySQL Proxy for SQLite
- #291: PDO API foundations
- #237: FOREIGN KEY constraints
- #209: Column metadata
- #257: CHECK constraints
- #276: INSERT and UPDATE value type casting

## Contributors
- @JanJakes: Primary developer of the new driver
- @adamziel (Adam Zielinski): Parser implementation, MySQL proxy prototype
- @berislavgrgicak: Testing with Playground Tester
