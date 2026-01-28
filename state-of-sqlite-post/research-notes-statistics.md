# Research Notes: Statistics and Metrics

This document compiles key statistics and metrics about the SQLite driver project.

## Codebase Size

### Core Driver Files
| File | Lines | Size | Description |
|------|-------|------|-------------|
| class-wp-pdo-mysql-on-sqlite.php | 6,731 | - | Main driver |
| class-wp-mysql-lexer.php | 2,997 | - | MySQL lexer |
| class-wp-sqlite-information-schema-builder.php | 3,150 | - | Information schema |
| mysql-grammar.php | 4 | 68KB | Compressed grammar |

### Test Files
| File | Lines | Description |
|------|-------|-------------|
| WP_SQLite_Driver_Tests.php | 11,526 | Driver tests |
| WP_SQLite_Translator_Tests.php | 3,560 | Legacy translator tests |
| WP_SQLite_Driver_Metadata_Tests.php | 2,299 | Metadata tests |
| WP_SQLite_Driver_Translation_Tests.php | 2,053 | Translation tests |
| WP_SQLite_Driver_Query_Tests.php | 655 | Query tests |
| Other test files | ~1,600 | Various tests |
| **Total test lines** | ~22,000+ | |

### MySQL Proxy Package
- 8 source files in `packages/wp-mysql-proxy/src/`
- 4 test files for MySQL proxy
- Complete MySQL wire protocol implementation

## Release History

### v2.2.x Line (New Driver)
| Version | Date | Key Changes |
|---------|------|-------------|
| v2.2.0 | Jun 2, 2025 | Initial release with new driver |
| v2.2.1 | Jun 2, 2025 | Minor fixes |
| v2.2.2 | Jun 6, 2025 | Public announcement |
| v2.2.3 | Jul 4, 2025 | WP-CLI fixes |
| v2.2.4 | Jul 25, 2025 | Query Monitor |
| v2.2.5 | Jul 31, 2025 | Table locking |
| v2.2.6 | Aug 6, 2025 | Database name fallback |
| v2.2.7 | Sep 11, 2025 | FOREIGN KEY constraints |
| v2.2.8 | Sep 12, 2025 | Multi-table UPDATE |
| v2.2.9 | Sep 12, 2025 | Derived tables |
| v2.2.10 | Sep 19, 2025 | Binary/hex literals |
| v2.2.11 | Oct 2, 2025 | Column metadata |
| v2.2.12 | Oct 10, 2025 | CHECK constraints |
| v2.2.13 | Oct 22, 2025 | INSERT ... SET |
| v2.2.14 | Nov 6, 2025 | Type casting |
| v2.2.15 | Nov 28, 2025 | PHP 8.5 compatibility |
| v2.2.16 | Jan 15, 2026 | PDO API, transaction fixes |

**Total v2.2.x releases**: 17

### Development Timeline
- **Nov 18, 2024**: Parser PR merged (PR #157)
- **Jun 2, 2025**: First public release (v2.2.0)
- **Nov 13, 2025**: MySQL Proxy merged (PR #272)
- **Jan 2026**: Ongoing PDO API work

**Active development period**: ~14 months (and counting)

## Pull Request Statistics

### Key PRs by Category

**Foundation**:
- #157: Exhaustive MySQL Parser

**Information Schema**:
- #196: ADD/DROP INDEX
- #213: SHOW DATABASES, SCHEMATA
- #209: Column metadata

**Query Support**:
- #237: FOREIGN KEY constraints
- #238: Multi-table UPDATE
- #241: Derived tables in UPDATE
- #257: CHECK constraints
- #276: INSERT/UPDATE type casting
- #286: LIKE/WHERE in SHOW

**Integration**:
- #212: Query Monitor support
- #269: phpMyAdmin/Adminer fixes
- #272: MySQL Proxy

**API**:
- #291: PDO API foundations
- #294: Transaction improvements
- #297: PDO bootstrap

## Test Coverage

### MySQL Query Test Suite
- **~70,000 queries** from MySQL server tests
- Tests lexer tokenization
- Tests parser AST generation
- Validates grammar coverage

### WordPress Core Tests
- PHPUnit test suite
- **99%+ tests passing**
- Validates WordPress compatibility

### Driver-Specific Tests
Categories:
- Translation tests (MySQL → SQLite)
- Query execution tests
- Information schema tests
- Metadata tests
- PDO API tests
- MySQL proxy tests

## Feature Coverage

### MySQL Statement Types Supported
- SELECT (with all clauses)
- INSERT, REPLACE
- UPDATE (single and multi-table)
- DELETE (single and multi-table)
- CREATE TABLE, CREATE INDEX
- ALTER TABLE (all operations)
- DROP TABLE, DROP INDEX
- TRUNCATE TABLE
- USE, SET
- SHOW (many variants)
- DESCRIBE / EXPLAIN
- Transaction statements
- Table administration statements

### MySQL Features Emulated
- SQL modes (STRICT, NO_BACKSLASH_ESCAPES, etc.)
- Implicit defaults
- Type casting
- Information schema
- System variables
- User variables
- Column metadata
- Foreign key constraints
- Check constraints
- ON UPDATE CURRENT_TIMESTAMP

### Functions Implemented
Date/time: 12+
String: 8+
Numeric: 5+
Network: 2
Control flow: 2
Locking: 2
**Total**: 30+ user-defined functions

## Integration Points

### Supported MySQL Tools
- Adminer (via MySQL proxy)
- phpMyAdmin (via MySQL proxy)
- MySQL CLI (via MySQL proxy)
- Query Monitor (WordPress plugin)

### WordPress Ecosystem
- WordPress Core
- WordPress Playground
- WordPress Studio
- WP-CLI

## Requirements

### Minimum Versions
- PHP: 7.2+
- SQLite: 3.37.0 (STRICT tables)
- With legacy flag: SQLite 3.27.0+

### PHP 8.x Compatibility
- PHP 8.0: Supported
- PHP 8.1: Supported
- PHP 8.2: Supported
- PHP 8.3: Supported
- PHP 8.4: Supported
- PHP 8.5: Supported (as of v2.2.15)

## Lexer Token Coverage

From MySQL Workbench predefined.tokens:
- 600+ token types defined
- All MySQL keywords
- All operators
- All literal formats
- Version-specific tokens

## Grammar Statistics

- Based on MySQL Workbench grammar
- ANTLR4 format converted to PHP
- ~70KB compressed size
- Covers MySQL 5.7, 8.0, 8.x
- Version-aware rules

## Performance

Parser performance (from PR #157):
- ~1,000 complex SELECT queries/second
- MacBook Pro baseline
- Significant optimization opportunities remaining

## Contributors

Primary:
- @JanJakes: Main driver development
- @adamziel (Adam Zielinski): Parser, MySQL proxy prototype

Testing/review:
- @berislavgrgicak: Plugin testing
- WordPress community contributors

## Project Links

- Main repo: https://github.com/WordPress/sqlite-database-integration
- Archived Automattic fork: https://github.com/Automattic/sqlite-database-integration
- WordPress Playground: https://playground.wordpress.net
