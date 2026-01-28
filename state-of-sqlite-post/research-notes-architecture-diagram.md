# Research Notes: Architecture Diagram

This document provides visual representations of the SQLite driver architecture.

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           WordPress / Application                            │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      │ MySQL Query
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         WP_PDO_MySQL_On_SQLite                               │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                        Query Processing                              │   │
│  │  ┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────────┐  │   │
│  │  │  Lexer   │───▶│  Parser  │───▶│   AST    │───▶│  Translator  │  │   │
│  │  │ (MySQL)  │    │ (LL)     │    │          │    │              │  │   │
│  │  └──────────┘    └──────────┘    └──────────┘    └──────────────┘  │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                      │                                      │
│                                      │ SQLite Queries                       │
│                                      ▼                                      │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                     Information Schema Builder                       │   │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐  │   │
│  │  │ TABLES   │ │ COLUMNS  │ │STATISTICS│ │CONSTRAINTS│ │ SCHEMATA │  │   │
│  │  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘  │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                      │                                      │
└──────────────────────────────────────┼──────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           SQLite Database (PDO)                              │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────────────┐  │
│  │   User Tables    │  │  Info Schema     │  │   Global Variables       │  │
│  │   (STRICT)       │  │  Shadow Tables   │  │   & Configuration        │  │
│  └──────────────────┘  └──────────────────┘  └──────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Query Processing Pipeline

```
                    MySQL Query String
                           │
                           ▼
              ┌────────────────────────┐
              │     WP_MySQL_Lexer     │
              │  ┌──────────────────┐  │
              │  │ Token Stream     │  │
              │  │ - Keywords       │  │
              │  │ - Identifiers    │  │
              │  │ - Operators      │  │
              │  │ - Literals       │  │
              │  └──────────────────┘  │
              └────────────────────────┘
                           │
                           ▼
              ┌────────────────────────┐
              │      WP_Parser         │
              │  ┌──────────────────┐  │
              │  │ MySQL Grammar    │──┼──▶ mysql-grammar.php (~70KB)
              │  │ (LL Parser)      │  │
              │  └──────────────────┘  │
              └────────────────────────┘
                           │
                           ▼
              ┌────────────────────────┐
              │   WP_Parser_Node       │
              │  (Abstract Syntax Tree)│
              │                        │
              │   query                │
              │   └─ simpleStatement   │
              │      └─ selectStmt     │
              │         ├─ SELECT      │
              │         ├─ selectList  │
              │         ├─ fromClause  │
              │         └─ whereClause │
              └────────────────────────┘
                           │
                           ▼
              ┌────────────────────────┐
              │     Translation        │
              │  ┌──────────────────┐  │
              │  │ Tree Walking     │  │
              │  │ - Rewrite rules  │  │
              │  │ - Type casting   │  │
              │  │ - Function maps  │  │
              │  └──────────────────┘  │
              └────────────────────────┘
                           │
                           ▼
                  SQLite Query String(s)
```

## Information Schema Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    MySQL CREATE TABLE Statement                          │
│                                                                          │
│  CREATE TABLE users (                                                    │
│    id INT PRIMARY KEY AUTO_INCREMENT,                                    │
│    email VARCHAR(255) NOT NULL UNIQUE,                                   │
│    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP                        │
│  )                                                                       │
└─────────────────────────────────────────────────────────────────────────┘
                                   │
                                   │ Parse & Record
                                   ▼
┌─────────────────────────────────────────────────────────────────────────┐
│              WP_SQLite_Information_Schema_Builder                        │
│                                                                          │
│  Records metadata in shadow tables:                                      │
│                                                                          │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │ _wp_sqlite_tables                                                │    │
│  │ ┌────────────────┬─────────────┬────────────┬─────────────────┐ │    │
│  │ │ TABLE_SCHEMA   │ TABLE_NAME  │ TABLE_TYPE │ ENGINE          │ │    │
│  │ ├────────────────┼─────────────┼────────────┼─────────────────┤ │    │
│  │ │ wordpress      │ users       │ BASE TABLE │ InnoDB          │ │    │
│  │ └────────────────┴─────────────┴────────────┴─────────────────┘ │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                          │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │ _wp_sqlite_columns                                               │    │
│  │ ┌─────────────┬─────────────┬──────────┬───────────┬──────────┐ │    │
│  │ │ COLUMN_NAME │ DATA_TYPE   │ NULLABLE │ DEFAULT   │ EXTRA    │ │    │
│  │ ├─────────────┼─────────────┼──────────┼───────────┼──────────┤ │    │
│  │ │ id          │ int         │ NO       │ NULL      │ auto_inc │ │    │
│  │ │ email       │ varchar     │ NO       │ NULL      │          │ │    │
│  │ │ created_at  │ timestamp   │ YES      │ CURRENT.. │          │ │    │
│  │ └─────────────┴─────────────┴──────────┴───────────┴──────────┘ │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                          │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │ _wp_sqlite_statistics (indexes)                                  │    │
│  │ _wp_sqlite_table_constraints                                     │    │
│  │ _wp_sqlite_key_column_usage                                      │    │
│  │ _wp_sqlite_referential_constraints                               │    │
│  │ _wp_sqlite_check_constraints                                     │    │
│  └─────────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────┘
                                   │
                                   │ Generate SQLite Schema
                                   ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        SQLite Table Creation                             │
│                                                                          │
│  CREATE TABLE `users` (                                                  │
│    `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,                      │
│    `email` TEXT NOT NULL,                                                │
│    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP                           │
│  ) STRICT                                                                │
│                                                                          │
│  CREATE UNIQUE INDEX `email_unique` ON `users` (`email`)                 │
└─────────────────────────────────────────────────────────────────────────┘
```

## MySQL Proxy Architecture

```
┌──────────────────┐     MySQL Wire Protocol     ┌──────────────────────────┐
│                  │◀────────────────────────────│                          │
│  MySQL Client    │                             │     MySQL_Proxy          │
│  (phpMyAdmin,    │────────────────────────────▶│                          │
│   Adminer,       │                             │  ┌────────────────────┐  │
│   mysql CLI)     │                             │  │  MySQL_Session     │  │
└──────────────────┘                             │  │  - Handshake       │  │
                                                 │  │  - Auth            │  │
                                                 │  │  - Query handling  │  │
                                                 │  └────────────────────┘  │
                                                 │           │              │
                                                 │           ▼              │
                                                 │  ┌────────────────────┐  │
                                                 │  │  SQLite_Adapter    │  │
                                                 │  │                    │  │
                                                 │  │  WP_PDO_MySQL_     │  │
                                                 │  │  On_SQLite         │  │
                                                 │  └────────────────────┘  │
                                                 │           │              │
                                                 └───────────┼──────────────┘
                                                             │
                                                             ▼
                                                 ┌──────────────────────────┐
                                                 │    SQLite Database       │
                                                 └──────────────────────────┘
```

## Component Dependency Graph

```
                          ┌─────────────────────┐
                          │  WordPress / App    │
                          └──────────┬──────────┘
                                     │
                                     ▼
                          ┌─────────────────────┐
                          │  WP_SQLite_DB       │
                          │  (db.php drop-in)   │
                          └──────────┬──────────┘
                                     │
                    ┌────────────────┼────────────────┐
                    │                │                │
                    ▼                ▼                ▼
         ┌──────────────┐  ┌─────────────────┐  ┌──────────────┐
         │ WP_PDO_MySQL │  │ WP_SQLite_      │  │ Query        │
         │ _On_SQLite   │  │ Configurator    │  │ Monitor      │
         └──────┬───────┘  └────────┬────────┘  │ Integration  │
                │                   │           └──────────────┘
       ┌────────┼────────┐          │
       │        │        │          │
       ▼        ▼        ▼          ▼
┌──────────┐ ┌──────┐ ┌───────┐ ┌──────────────────┐
│WP_MySQL_ │ │WP_   │ │WP_    │ │WP_SQLite_        │
│Lexer     │ │Parser│ │Parser │ │Information_      │
│          │ │      │ │Grammar│ │Schema_Builder    │
└──────────┘ └──────┘ └───────┘ └────────┬─────────┘
                                         │
                                         ▼
                                ┌──────────────────┐
                                │WP_SQLite_        │
                                │Information_      │
                                │Schema_           │
                                │Reconstructor     │
                                └──────────────────┘
```

## Transaction Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        MySQL Query Execution                             │
│                                                                          │
│  1. MySQL Query arrives                                                  │
│     │                                                                    │
│     ▼                                                                    │
│  2. ┌─────────────────────────────────────────┐                         │
│     │ BEGIN IMMEDIATE (or SAVEPOINT)          │  ◀── Wrapper Transaction │
│     └─────────────────────────────────────────┘                         │
│     │                                                                    │
│     ▼                                                                    │
│  3. Parse MySQL → AST                                                    │
│     │                                                                    │
│     ▼                                                                    │
│  4. Translate AST → SQLite Query(ies)                                   │
│     │                                                                    │
│     ├──▶ SQLite Query 1 (e.g., info schema update)                      │
│     ├──▶ SQLite Query 2 (e.g., main operation)                          │
│     └──▶ SQLite Query 3 (e.g., index creation)                          │
│     │                                                                    │
│     ▼                                                                    │
│  5. ┌─────────────────────────────────────────┐                         │
│     │ COMMIT (or RELEASE SAVEPOINT)           │  ◀── Success             │
│     └─────────────────────────────────────────┘                         │
│                                                                          │
│     OR on error:                                                         │
│                                                                          │
│     ┌─────────────────────────────────────────┐                         │
│     │ ROLLBACK (or ROLLBACK TO SAVEPOINT)     │  ◀── Failure             │
│     └─────────────────────────────────────────┘                         │
└─────────────────────────────────────────────────────────────────────────┘
```

## File Structure

```
sqlite-database-integration/
├── wp-includes/
│   ├── mysql/
│   │   ├── class-wp-mysql-lexer.php      # MySQL lexical analyzer
│   │   ├── class-wp-mysql-token.php      # Token representation
│   │   └── mysql-grammar.php             # Compressed MySQL grammar
│   │
│   ├── parser/
│   │   ├── class-wp-parser.php           # Generic LL parser
│   │   ├── class-wp-parser-grammar.php   # Grammar loader
│   │   └── class-wp-parser-node.php      # AST node
│   │
│   ├── sqlite/
│   │   ├── class-wp-sqlite-db.php        # WordPress DB interface
│   │   ├── class-wp-sqlite-pdo-user-defined-functions.php
│   │   └── class-wp-sqlite-connection.php
│   │
│   └── sqlite-ast/
│       ├── class-wp-pdo-mysql-on-sqlite.php           # Main driver
│       ├── class-wp-sqlite-information-schema-builder.php
│       ├── class-wp-sqlite-information-schema-reconstructor.php
│       └── class-wp-sqlite-configurator.php
│
├── packages/
│   └── wp-mysql-proxy/
│       └── src/
│           ├── class-mysql-proxy.php     # Server
│           ├── class-mysql-session.php   # Client session
│           ├── class-mysql-protocol.php  # Wire protocol
│           └── Adapter/
│               └── class-sqlite-adapter.php
│
└── tests/
    ├── WP_SQLite_Driver_Tests.php        # Main driver tests
    ├── WP_SQLite_Driver_Translation_Tests.php
    ├── WP_SQLite_Driver_Metadata_Tests.php
    └── parser/
        └── run-parser-tests.php          # 70k query tests
```
