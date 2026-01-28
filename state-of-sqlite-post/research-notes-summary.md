# Research Notes Summary

This is an index to the research gathered for the "State of SQLite" post about the WordPress SQLite driver rewrite.

## Research Files

### Core Research
| File | Description | Key Topics |
|------|-------------|------------|
| [research-notes-overview.md](research-notes-overview.md) | Project overview and history | Timeline, components, features, contributors |
| [research-notes-technical.md](research-notes-technical.md) | Technical deep dive | Architecture, implementation tricks, parser details |
| [research-notes-challenges.md](research-notes-challenges.md) | Challenges and solutions | 18 major challenges and how they were solved |
| [research-notes-examples.md](research-notes-examples.md) | Query translation examples | MySQL → SQLite transformations |
| [research-notes-statistics.md](research-notes-statistics.md) | Statistics and metrics | Code stats, releases, tests |

### Extended Research (Phase 2)
| File | Description | Key Topics |
|------|-------------|------------|
| [research-notes-real-world-problems.md](research-notes-real-world-problems.md) | Bugs and issues fixed | Plugin compatibility, WordPress core issues |
| [research-notes-before-after.md](research-notes-before-after.md) | Old vs new driver comparison | Feature comparison, query support |
| [research-notes-architecture-diagram.md](research-notes-architecture-diagram.md) | Visual architecture | ASCII diagrams, component flow |
| [research-notes-why-not-just.md](research-notes-why-not-just.md) | Design decisions explained | Why simpler approaches fail |
| [research-notes-comparisons.md](research-notes-comparisons.md) | Other projects comparison | History, alternatives, unique contributions |

## Existing Posts (for reference)

| File | Date | Topic |
|------|------|-------|
| post-2025-02-13.txt | Feb 13, 2025 | Internal announcement |
| post-2025-06-06.txt | Jun 6, 2025 | Public announcement of v2.2.0 |
| post-2025-07-09.txt | Jul 9, 2025 | Query Monitor integration |
| post-2025-12-02.txt | Dec 2, 2025 | Adminer/phpMyAdmin in Playground |

## Executive Summary

### What Was Built

A complete rewrite of the WordPress SQLite driver, transforming a limited token-based translator into a full MySQL-on-SQLite implementation featuring:

1. **AST-Based Parser**: Custom MySQL lexer and parser using the official MySQL Workbench grammar
2. **Information Schema Emulation**: Full MySQL INFORMATION_SCHEMA support via shadow tables
3. **PDO API Implementation**: The driver now extends PDO for better compatibility
4. **MySQL Binary Protocol**: A proxy server enabling phpMyAdmin, Adminer, and MySQL CLI tools
5. **Extensive Testing**: ~70,000 MySQL queries validated, WordPress core tests passing

### Key Numbers

- **6,731 lines**: Main driver class
- **3,150 lines**: Information schema builder
- **~70KB**: Compressed MySQL grammar
- **~70,000**: MySQL queries in test suite
- **17 releases**: In the v2.2.x line
- **14+ months**: Active development

### Why It Matters

1. **WordPress Playground**: Powers browser-based WordPress instances
2. **Local Development**: No MySQL server needed (WordPress Studio)
3. **Plugin Compatibility**: Better support for WordPress plugins
4. **Tool Support**: MySQL admin tools now work with SQLite
5. **Future Foundation**: Clean architecture for continued improvements

## Key Themes for the Post

### 1. The Parser Investment
Building a complete MySQL parser was a significant upfront investment that pays off in maintainability and feature support. The old token-based approach couldn't handle modern MySQL complexity.

### 2. Information Schema as the Source of Truth
The decision to maintain MySQL-compatible metadata in SQLite tables was crucial. It enables SHOW CREATE TABLE to return the original MySQL syntax and makes complex introspection queries work correctly.

### 3. Handling MySQL's Complexity
MySQL has accumulated decades of features and edge cases. The driver handles SQL modes, implicit defaults, type casting, and various statement types that differ significantly from SQLite.

### 4. Making Admin Tools Work
The MySQL protocol implementation enables powerful debugging and development workflows by allowing standard MySQL tools to connect to SQLite databases.

### 5. WordPress Ecosystem Integration
Query Monitor integration, Playground support, and maintaining compatibility with WordPress's expectations for database behavior were all important goals.

## Story Arc for the Post

1. **The Problem**: Token-based translation couldn't handle modern MySQL/WordPress complexity
2. **The Approach**: Invest in proper parsing, information schema emulation
3. **The Implementation**: Walk through key components and their responsibilities
4. **The Challenges**: Highlight interesting problems and solutions
5. **The Results**: Statistics, compatibility achievements, tool support
6. **The Future**: PDO API completion, remaining work, community contribution

## Interesting Code Locations

For code examples and deep dives:

| Topic | File | Lines (approx) |
|-------|------|----------------|
| INSERT type casting | class-wp-pdo-mysql-on-sqlite.php | 4830-5140 |
| ALTER TABLE recreation | class-wp-pdo-mysql-on-sqlite.php | 2365-2435 |
| Multi-table DELETE | class-wp-pdo-mysql-on-sqlite.php | 2200-2290 |
| Transaction handling | class-wp-pdo-mysql-on-sqlite.php | 1480-1550 |
| Main translate() method | class-wp-pdo-mysql-on-sqlite.php | 3570-3800 |
| MySQL protocol | packages/wp-mysql-proxy/src/ | Full files |

## GitHub Resources

- **Main PR**: [#157 - Exhaustive MySQL Parser](https://github.com/WordPress/sqlite-database-integration/pull/157)
- **MySQL Proxy**: [#272 - MySQL Proxy for SQLite](https://github.com/WordPress/sqlite-database-integration/pull/272)
- **Releases**: [All releases](https://github.com/WordPress/sqlite-database-integration/releases)
- **Automattic Fork**: [Archived](https://github.com/Automattic/sqlite-database-integration)

## Key PR Discussion Highlights

From PR #157 (Parser) discussion thread:

1. **Initial Validation**: Jan tested 66k MySQL queries, achieving 97% success rate with minimal fixes
2. **Grammar Choice**: MySQL Workbench grammar chosen because it covers multiple MySQL versions in single grammar
3. **Parser Design**: Discussion about LL parsing, conflict resolution, lookahead strategies
4. **Lexer Improvements**: AI-generated lexer was refined through manual comparison with MySQLLexer.g4
5. **Performance**: Lexing 66k queries went from 1.6-1.7s to under 1s after optimization
6. **AST-to-SQLite Design**: Considered AST-to-AST transformation but chose direct AST-to-string for simplicity

## Code Comment Links Referenced

The driver code contains extensive references to external documentation:
- MySQL documentation on SQL modes, data types, implicit defaults
- SQLite documentation on STRICT tables, ALTER TABLE limitations
- PHP bug reports for PDO workarounds
- WordPress core code for compatibility

See [research-notes-technical.md](research-notes-technical.md) for specific URLs.

## Next Steps

This research should provide comprehensive material for writing the detailed "State of SQLite" post covering:
- The technical architecture and key decisions
- Challenges encountered and how they were solved
- The feature set and compatibility achievements
- The MySQL protocol/tools support
- Future development directions
