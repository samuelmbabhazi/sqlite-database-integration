# Research Notes: Comparison with Other Projects

This document compares the WordPress SQLite driver with other MySQL-to-SQLite solutions and related projects.

## Historical WordPress SQLite Solutions

### 1. SQLite Integration by kjmtsh (~2014)

**Description**: The original plugin that enabled WordPress to run on SQLite.

**Approach**:
- Token-based query rewriting
- Pattern matching for query transformation
- Manual handling of MySQL-specific syntax

**Limitations**:
- Limited MySQL syntax support
- No information schema emulation
- Many plugins incompatible
- Became unmaintained around 2015-2016

**Legacy**: Formed the basis for subsequent attempts.

### 2. wp-sqlite-db by aaemnnosttv

**Repository**: https://github.com/aaemnnosttv/wp-sqlite-db

**Description**: Single-file drop-in based on SQLite Integration.

**Approach**:
- Simplified version of SQLite Integration
- Single db.php file
- Same token-based approach

**Status**: Maintenance mode, points to official plugin.

### 3. PHPMyAdmin Parser Approach (2022)

**Contributors**: Ari Stathopoulos, Adam Zielinski

**Description**: Rewrite using PHPMyAdmin's SQL parser.

**Approach**:
- Leveraged PHPMyAdmin's `phpmyadmin/sql-parser` library
- Improved query understanding vs pure regex
- Still fundamentally token-based translation

**Achievements**:
- Better parsing accuracy
- Handled more MySQL syntax
- Formed the "legacy driver" in current plugin

**Limitations**:
- Parser designed for analysis, not translation
- Complex queries still problematic
- Information schema support limited

### 4. Current AST-Based Driver (2024-2025)

**Contributors**: Jan Jakeš, Adam Zielinski

**Description**: Complete rewrite with custom MySQL parser.

**Approach**:
- Custom MySQL lexer from MySQL Workbench grammar
- Full AST-based parsing
- Information schema emulation
- PDO API implementation

**Achievements**:
- ~70,000 MySQL queries passing
- WordPress core tests passing
- Major plugin compatibility
- MySQL admin tools support

## Related MySQL Parser Projects

### PHPMyAdmin SQL Parser

**Repository**: https://github.com/phpmyadmin/sql-parser

**Purpose**: SQL query analysis for phpMyAdmin interface.

**Features**:
- Lexer and parser for MySQL/MariaDB
- Query analysis and modification
- Syntax highlighting support

**Comparison to WP driver**:
| Aspect | PHPMyAdmin Parser | WP MySQL Parser |
|--------|-------------------|-----------------|
| Purpose | Query analysis | Query translation |
| Grammar source | Custom | MySQL Workbench |
| Version support | Good | Complete (5.7-8.x) |
| DDL support | Limited | Full |
| Dependencies | Many | None |
| Performance | N/A | ~1000 queries/sec |

### MySQL Workbench Grammar

**Repository**: https://github.com/mysql/mysql-workbench

**Purpose**: Official MySQL parsing for Workbench GUI.

**Grammar files**:
- MySQLLexer.g4 - Lexer grammar (ANTLR4)
- MySQLParser.g4 - Parser grammar (ANTLR4)

**Why we used it**:
- Official MySQL grammar
- Covers all MySQL versions in single grammar
- Version specifiers for syntax changes
- Well-maintained by Oracle

**Our adaptations**:
- Converted ANTLR4 to PHP array format
- Fixed several grammar bugs
- Added custom conflict resolution
- Compressed to ~70KB

### Other MySQL Parsers

| Project | Language | Notes |
|---------|----------|-------|
| vitess/sqlparser | Go | Used by Vitess, PlanetScale |
| sqlparser-rs | Rust | Multiple SQL dialects |
| antlr4-mysql | Various | ANTLR4 grammars |
| jsqlparser | Java | Used by many Java apps |

## Other Database Abstraction Approaches

### Doctrine DBAL

**Description**: PHP database abstraction layer supporting multiple databases.

**Approach**:
- Abstract query building
- Schema management
- Multiple driver support

**Comparison**:
- Requires writing queries in abstract form
- Not transparent to existing MySQL code
- Different use case (new apps vs compatibility)

### PDO (PHP Data Objects)

**Description**: PHP's built-in database abstraction.

**Approach**:
- Consistent API across databases
- Prepared statements
- Driver-specific SQL still required

**Our integration**:
- WP_PDO_MySQL_On_SQLite extends PDO
- Provides MySQL-compatible interface
- Translates MySQL SQL to SQLite SQL

## SQLite Compatibility Layers in Other Ecosystems

### sql.js (JavaScript)

**Description**: SQLite compiled to JavaScript via Emscripten.

**Use**: Powers SQLite in browsers, including WordPress Playground.

**Relationship**: Our driver generates SQL for sql.js in Playground.

### diesel (Rust)

**Description**: Rust ORM with SQLite and other backends.

**Approach**: Compile-time query validation, type-safe queries.

**Comparison**: Different paradigm (ORM vs SQL translation).

### sqlalchemy (Python)

**Description**: Python SQL toolkit and ORM.

**Approach**: Abstract query building or raw SQL with dialect handling.

**Comparison**: Similar abstraction goals, different approach.

## Comparison Matrix

| Feature | kjmtsh | wp-sqlite-db | PHPMyAdmin | WP AST Driver |
|---------|--------|--------------|------------|---------------|
| Parser type | Token | Token | Token | AST |
| Grammar source | Custom | Custom | Custom | MySQL Workbench |
| MySQL test coverage | Low | Low | Medium | ~70,000 queries |
| Info schema | No | No | Limited | Full |
| FOREIGN KEY | No | No | No | Yes |
| UPDATE JOIN | No | No | No | Yes |
| CTE (WITH) | No | No | No | Yes |
| Admin tools | No | No | No | Yes |
| PDO API | No | No | No | Yes |
| MySQL protocol | No | No | No | Yes |
| Active development | No | No | Limited | Yes |

## What Makes the New Driver Different

### 1. Grammar Foundation

Unlike previous attempts that built parsers ad-hoc, the new driver:
- Uses official MySQL grammar from MySQL Workbench
- Supports all MySQL syntax by design
- Has version-aware parsing

### 2. Information Schema

Previous solutions ignored or minimally supported info schema. The new driver:
- Maintains shadow tables mirroring MySQL's INFORMATION_SCHEMA
- Returns original MySQL syntax for SHOW CREATE TABLE
- Supports complex info schema queries

### 3. Correctness First

The approach prioritizes correctness:
- Parse like MySQL parses
- Translate preserving semantics
- Test against MySQL's own test suite

### 4. Extensibility

The architecture supports additions:
- New MySQL features → grammar updates
- New translation rules → AST handlers
- New APIs → PDO method implementations

## Lessons from Other Projects

### From PHPMyAdmin Parser
- Lexer/parser separation is valuable
- Token types matter for context
- Documentation helps adoption

### From MySQL Workbench
- Official grammar is comprehensive
- Version handling is complex but necessary
- Edge cases are numerous

### From SQLite Integration
- WordPress hooks into db.php
- Plugin compatibility is crucial
- Maintenance is ongoing work

### From Doctrine/PDO
- API consistency matters
- Abstraction has limits
- Sometimes you need the full SQL

## Unique Contributions

The WordPress SQLite driver contributes:

1. **PHP MySQL Parser**: Complete MySQL parser in pure PHP
2. **Grammar Conversion**: ANTLR4 to PHP pipeline
3. **Info Schema Emulation**: Comprehensive approach
4. **MySQL Protocol in PHP**: For admin tools
5. **WordPress Integration**: db.php drop-in architecture

These components could potentially be extracted for use in other PHP projects needing MySQL compatibility on alternative databases.
