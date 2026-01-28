# Research Notes: Query Translation Examples

This document shows example MySQL queries and their SQLite translations.

## Basic SELECT

### Simple queries pass through mostly unchanged

```sql
-- MySQL:
SELECT 1

-- SQLite:
SELECT 1
```

```sql
-- MySQL:
SELECT c1, c2 FROM t

-- SQLite:
SELECT `c1` , `c2` FROM `t`
```

Note: All identifiers are quoted in the output for safety.

### Joins

```sql
-- MySQL:
SELECT * FROM t1 LEFT JOIN t2 ON t1.id = t2.t1_id WHERE t1.name = 'abc'

-- SQLite:
SELECT * FROM `t1` LEFT JOIN `t2` ON `t1`.`id` = `t2`.`t1_id` WHERE `t1`.`name` = 'abc'
```

## INSERT Statements

### Basic INSERT with type casting

The driver wraps INSERT values in a SELECT to apply type casting:

```sql
-- MySQL:
INSERT INTO t (c) VALUES (1)

-- SQLite (3.33.0+):
INSERT INTO `t` (`c`)
SELECT `column1`
FROM (VALUES (1))
WHERE true

-- SQLite (< 3.33.0, with column naming workaround):
INSERT INTO `t` (`c`)
SELECT `column1`
FROM (
    SELECT NULL AS `column1` WHERE FALSE
    UNION ALL
    VALUES (1)
)
WHERE true
```

### INSERT with TEXT column (type casting applied)

```sql
-- MySQL:
INSERT INTO t1 (c1, c2) VALUES (1, 2)  -- c1, c2 are TEXT columns

-- SQLite:
INSERT INTO `t1` (`c1`, `c2`)
SELECT CAST(`column1` AS TEXT), CAST(`column2` AS TEXT)
FROM (VALUES (1, 2))
WHERE true
```

### INSERT ... SELECT

```sql
-- MySQL:
INSERT INTO t1 SELECT * FROM t2

-- SQLite (two queries):
-- 1. Get column names from select:
SELECT * FROM (SELECT * FROM `t2`) LIMIT 1

-- 2. Execute with type casting:
INSERT INTO `t1` (`c1`, `c2`)
SELECT CAST(`c1` AS TEXT), CAST(`c2` AS TEXT)
FROM (SELECT * FROM `t2`)
WHERE true
```

### INSERT ... SET syntax

```sql
-- MySQL:
INSERT INTO t SET c1 = 1, c2 = 2

-- SQLite:
INSERT INTO `t` (`c1`, `c2`)
SELECT `column1`, `column2`
FROM (VALUES (1, 2))
WHERE true
```

## UPDATE Statements

### Basic UPDATE with type casting

```sql
-- MySQL:
UPDATE t SET c1 = 'value'

-- SQLite:
UPDATE `t` SET `c1` = CAST('value' AS TEXT)
```

### UPDATE with JOIN (single table modified)

```sql
-- MySQL:
UPDATE t1 JOIN t2 ON t1.id = t2.t1_id SET t1.c = t2.val

-- SQLite (complex transformation):
-- 1. Identify target table and get values via subquery
-- 2. Execute UPDATE on target table only
```

## DELETE Statements

### Simple DELETE

```sql
-- MySQL:
DELETE FROM t WHERE id = 1

-- SQLite:
DELETE FROM `t` WHERE `id` = 1
```

### Multi-table DELETE

```sql
-- MySQL:
DELETE t1, t2 FROM t1 JOIN t2 ON t1.id = t2.t1_id WHERE ...

-- SQLite (multiple queries):
-- 1. Get ROWIDs:
SELECT t1.rowid AS t1_rowid, t2.rowid AS t2_rowid
FROM t1 JOIN t2 ON t1.id = t2.t1_id WHERE ...

-- 2. Delete from each table:
DELETE FROM t1 WHERE rowid IN (...)
DELETE FROM t2 WHERE rowid IN (...)
```

## CREATE TABLE

### Basic CREATE TABLE

```sql
-- MySQL:
CREATE TABLE users (
    ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_login varchar(60) NOT NULL DEFAULT '',
    PRIMARY KEY (ID),
    KEY user_login_key (user_login)
)

-- SQLite:
CREATE TABLE `users` (
    `ID` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `user_login` TEXT NOT NULL DEFAULT ''
) STRICT

-- Additional index query:
CREATE INDEX `user_login_key` ON `users` (`user_login`)
```

### Data type mapping

| MySQL | SQLite |
|-------|--------|
| TINYINT, SMALLINT, INT, BIGINT | INTEGER |
| FLOAT, DOUBLE, DECIMAL | REAL |
| CHAR, VARCHAR, TEXT | TEXT |
| BINARY, VARBINARY, BLOB | BLOB |
| DATE, TIME, DATETIME, TIMESTAMP | TEXT |
| SERIAL | INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE |

## ALTER TABLE

ALTER TABLE is handled by recreating the table:

```sql
-- MySQL:
ALTER TABLE t ADD COLUMN new_col INT

-- SQLite (conceptually):
-- 1. Record change in information schema
-- 2. CREATE TABLE t_new (... with new column ...)
-- 3. INSERT INTO t_new SELECT ... FROM t
-- 4. DROP TABLE t
-- 5. ALTER TABLE t_new RENAME TO t
-- 6. Recreate indexes
```

## SHOW Statements

### SHOW TABLES

```sql
-- MySQL:
SHOW TABLES

-- SQLite:
SELECT table_name AS `Tables_in_wp`
FROM `_wp_sqlite_tables`
WHERE table_schema = 'sqlite_database'
AND table_type = 'BASE TABLE'
ORDER BY table_name
```

### SHOW CREATE TABLE

```sql
-- MySQL:
SHOW CREATE TABLE users

-- SQLite:
-- Generates MySQL-compatible CREATE TABLE from information schema
-- Returns exactly what was used to create the table
```

### SHOW COLUMNS / DESCRIBE

```sql
-- MySQL:
SHOW COLUMNS FROM users

-- SQLite:
SELECT
    COLUMN_NAME AS `Field`,
    COLUMN_TYPE AS `Type`,
    IS_NULLABLE AS `Null`,
    COLUMN_KEY AS `Key`,
    COLUMN_DEFAULT AS `Default`,
    EXTRA AS `Extra`
FROM `_wp_sqlite_columns`
WHERE table_schema = ? AND table_name = ?
ORDER BY ordinal_position
```

## INFORMATION_SCHEMA Queries

```sql
-- MySQL:
SELECT COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'wp' AND TABLE_NAME = 'users'

-- SQLite:
SELECT COLUMN_NAME, DATA_TYPE
FROM `_wp_sqlite_columns`
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
```

## Function Translations

### Date Functions

```sql
-- MySQL:
SELECT MONTH(created_at) FROM posts

-- SQLite:
SELECT month(created_at) FROM posts
-- (month is a user-defined function)
```

### String Functions

```sql
-- MySQL:
SELECT MD5(password)

-- SQLite:
SELECT md5(password)
-- (md5 is a user-defined function)
```

### LIKE with BINARY

```sql
-- MySQL:
SELECT * FROM t WHERE c LIKE BINARY 'abc%'

-- SQLite:
SELECT * FROM `t` WHERE GLOB 'abc*' `c`
-- (translated to GLOB for case-sensitive matching)
```

## Transaction Handling

### Wrapper transactions

Each MySQL query is wrapped in a transaction/savepoint:

```sql
-- Single MySQL query execution:
BEGIN IMMEDIATE;  -- For write queries
-- ... execute SQLite queries ...
COMMIT;

-- If already in transaction:
SAVEPOINT wrapper;
-- ... execute SQLite queries ...
RELEASE wrapper;
```

## SQL Mode Emulation

### STRICT mode (default MySQL 8.0)

Invalid values cause errors:
```sql
INSERT INTO t (int_col) VALUES ('not a number')
-- Error: cannot store TEXT in INTEGER column
```

### Non-STRICT mode

Invalid values converted to implicit defaults:
```sql
INSERT INTO t (int_col) VALUES ('not a number')
-- Converted to: 0 (implicit default for INT)
```

## Special Cases

### SQL_CALC_FOUND_ROWS / FOUND_ROWS()

```sql
-- MySQL:
SELECT SQL_CALC_FOUND_ROWS * FROM t LIMIT 10;
SELECT FOUND_ROWS();

-- SQLite:
-- 1. Remove SQL_CALC_FOUND_ROWS, execute query
-- 2. Store total count
-- 3. For FOUND_ROWS(), return stored count
```

### FROM DUAL

```sql
-- MySQL:
SELECT 1 FROM DUAL

-- SQLite:
SELECT 1
-- (FROM DUAL is simply removed)
```

### Index Hints

```sql
-- MySQL:
SELECT * FROM t USE INDEX (idx1) WHERE ...

-- SQLite:
SELECT * FROM `t` WHERE ...
-- (Index hints are ignored - SQLite has its own optimizer)
```

## Complex Examples

### UNION with CTEs

```sql
-- MySQL:
WITH cte AS (SELECT * FROM t1)
SELECT * FROM cte
UNION ALL
SELECT * FROM t2

-- SQLite:
WITH `cte` AS (SELECT * FROM `t1`)
SELECT * FROM `cte`
UNION ALL
SELECT * FROM `t2`
```

### Subquery in WHERE

```sql
-- MySQL:
SELECT * FROM t1 WHERE id IN (SELECT t1_id FROM t2)

-- SQLite:
SELECT * FROM `t1` WHERE `id` IN (SELECT `t1_id` FROM `t2`)
```

### ON DUPLICATE KEY UPDATE

```sql
-- MySQL:
INSERT INTO t (id, val) VALUES (1, 'a')
ON DUPLICATE KEY UPDATE val = VALUES(val)

-- SQLite:
INSERT INTO `t` (`id`, `val`)
SELECT ...
FROM (VALUES (1, 'a'))
WHERE true
ON CONFLICT DO UPDATE SET `val` = excluded.`val`
```
