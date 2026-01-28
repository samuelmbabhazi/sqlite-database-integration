# Research Notes: Before/After Comparison

This document compares the old token-based driver (WP_SQLite_Translator) with the new AST-based driver (WP_PDO_MySQL_On_SQLite).

## Architecture Comparison

### Old Driver: WP_SQLite_Translator

```
MySQL Query String
       ↓
   Tokenize (regex-based)
       ↓
   Pattern Match (string manipulation)
       ↓
   Transform (case-by-case handling)
       ↓
SQLite Query String
```

**Characteristics**:
- Token stream processing
- Regex-based tokenization
- String pattern matching for query types
- Ad-hoc transformations per query pattern
- Limited understanding of query structure
- Difficult to handle nested constructs

### New Driver: WP_PDO_MySQL_On_SQLite

```
MySQL Query String
       ↓
   Lexer (MySQL grammar-based)
       ↓
   Token Stream
       ↓
   Parser (recursive descent)
       ↓
   Abstract Syntax Tree
       ↓
   Translate (tree walking)
       ↓
SQLite Query String(s)
```

**Characteristics**:
- Full MySQL grammar from MySQL Workbench
- Complete AST representation
- Tree-based transformations
- Deep understanding of query structure
- Handles arbitrary nesting
- Extensible rule-based translation

## Query Support Comparison

### Queries That Failed Before, Work Now

#### 1. Common Table Expressions (WITH)

```sql
-- MySQL:
WITH recent_posts AS (
  SELECT * FROM wp_posts WHERE post_date > '2024-01-01'
)
SELECT * FROM recent_posts WHERE post_status = 'publish'
```

**Before**: ❌ Not supported - couldn't parse CTE syntax
**After**: ✅ Full support - AST handles WITH clause natively

#### 2. Complex UNION queries

```sql
-- MySQL:
(SELECT id, name FROM users WHERE role = 'admin')
UNION ALL
(SELECT id, name FROM users WHERE role = 'editor')
ORDER BY name
LIMIT 10
```

**Before**: ❌ Parenthesized subqueries confused the tokenizer
**After**: ✅ Parser understands query structure

#### 3. Subqueries in FROM clause (Derived Tables)

```sql
-- MySQL:
SELECT * FROM (
  SELECT user_id, COUNT(*) as post_count
  FROM wp_posts
  GROUP BY user_id
) AS user_stats
WHERE post_count > 10
```

**Before**: ⚠️ Limited support, often failed
**After**: ✅ Full support with proper alias handling

#### 4. UPDATE with JOIN

```sql
-- MySQL:
UPDATE wp_posts p
JOIN wp_postmeta m ON p.ID = m.post_id
SET p.post_status = 'draft'
WHERE m.meta_key = 'archived' AND m.meta_value = '1'
```

**Before**: ❌ Not supported
**After**: ✅ Translated to subquery-based UPDATE

#### 5. Multi-table DELETE

```sql
-- MySQL:
DELETE p, m
FROM wp_posts p
JOIN wp_postmeta m ON p.ID = m.post_id
WHERE p.post_status = 'trash'
```

**Before**: ❌ Not supported
**After**: ✅ Executed as multiple DELETE statements using ROWIDs

#### 6. FOREIGN KEY Constraints

```sql
-- MySQL:
CREATE TABLE orders (
  id INT PRIMARY KEY,
  user_id INT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)
```

**Before**: ❌ Threw error, constraints ignored
**After**: ✅ Full support with all ON DELETE/UPDATE actions

#### 7. CHECK Constraints

```sql
-- MySQL:
CREATE TABLE products (
  price DECIMAL(10,2) CHECK (price > 0),
  quantity INT CHECK (quantity >= 0)
)
```

**Before**: ❌ Not supported
**After**: ✅ Supported with information schema recording

#### 8. Complex SHOW statements

```sql
-- MySQL:
SHOW COLUMNS FROM wp_posts LIKE 'post%'
SHOW TABLE STATUS WHERE Name LIKE 'wp_%'
SHOW CREATE TABLE wp_users
```

**Before**: ⚠️ Basic support, LIKE/WHERE often broken
**After**: ✅ Full support via information schema queries

#### 9. Information Schema Queries

```sql
-- MySQL:
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'wordpress'
  AND TABLE_NAME = 'wp_posts'
ORDER BY ORDINAL_POSITION
```

**Before**: ❌ Very limited, most queries failed
**After**: ✅ Full emulation via shadow tables

#### 10. Transaction Savepoints

```sql
-- MySQL:
START TRANSACTION;
INSERT INTO wp_posts (...) VALUES (...);
SAVEPOINT before_meta;
INSERT INTO wp_postmeta (...) VALUES (...);
-- If something goes wrong:
ROLLBACK TO before_meta;
COMMIT;
```

**Before**: ❌ Savepoints not supported
**After**: ✅ Full savepoint support

## Feature Comparison Table

| Feature | Old Driver | New Driver |
|---------|------------|------------|
| Basic SELECT | ✅ | ✅ |
| Basic INSERT/UPDATE/DELETE | ✅ | ✅ |
| JOINs | ✅ | ✅ |
| Subqueries in WHERE | ⚠️ Limited | ✅ |
| Subqueries in FROM | ⚠️ Limited | ✅ |
| UNION / UNION ALL | ⚠️ Limited | ✅ |
| Common Table Expressions | ❌ | ✅ |
| UPDATE with JOIN | ❌ | ✅ |
| Multi-table DELETE | ❌ | ✅ |
| FOREIGN KEY constraints | ❌ | ✅ |
| CHECK constraints | ❌ | ✅ |
| ON UPDATE CURRENT_TIMESTAMP | ❌ | ✅ |
| Transaction savepoints | ❌ | ✅ |
| Table locking (LOCK/UNLOCK) | ❌ | ✅ |
| SHOW CREATE TABLE | ⚠️ Incomplete | ✅ |
| SHOW COLUMNS with LIKE/WHERE | ⚠️ Buggy | ✅ |
| INFORMATION_SCHEMA queries | ❌ | ✅ |
| STRICT SQL mode emulation | ❌ | ✅ |
| Implicit defaults (non-strict) | ❌ | ✅ |
| Type casting on INSERT/UPDATE | ❌ | ✅ |
| Column metadata (getColumnMeta) | ❌ | ✅ |
| MySQL system variables | ❌ | ⚠️ Partial |
| MySQL user variables | ❌ | ✅ |
| PDO API compatibility | ❌ | ✅ |
| MySQL binary protocol | ❌ | ✅ |
| phpMyAdmin/Adminer support | ❌ | ✅ |

## Code Maintainability Comparison

### Old Driver: Pattern Matching

```php
// Example from old driver - string pattern matching
if (preg_match('/^INSERT\s+INTO/i', $query)) {
    // Handle INSERT
    if (preg_match('/ON\s+DUPLICATE\s+KEY/i', $query)) {
        // Special case for ON DUPLICATE KEY
        // More regex, more special cases...
    }
}
```

**Problems**:
- Each new MySQL feature = new regex patterns
- Edge cases multiply
- Hard to test comprehensively
- Subtle bugs from pattern overlap

### New Driver: AST Translation

```php
// Example from new driver - tree walking
private function execute_mysql_query(WP_Parser_Node $node): void {
    $child = $node->get_first_child_node();
    switch ($child->rule_name) {
        case 'insertStatement':
            $this->execute_insert_or_replace_statement($child);
            break;
        case 'updateStatement':
            $this->execute_update_statement($child);
            break;
        // ... clean, extensible structure
    }
}
```

**Benefits**:
- Grammar defines valid syntax
- AST captures full query structure
- Translation rules are composable
- Easy to add new features
- Testable with MySQL test suite

## Error Handling Comparison

### Old Driver

```
Error: Query failed
Query: SELECT * FROM t1 JOIN (SELECT * FROM t2) sub ON t1.id = sub.id
```

**Problem**: Often unclear why a query failed

### New Driver

```
WP_SQLite_Driver_Exception: SQLSTATE[42S02]: Base table or view not found:
1146 Table 'wp_nonexistent' doesn't exist
```

**Benefit**: MySQL-compatible error codes and messages

## Test Coverage Comparison

### Old Driver
- ~3,500 lines of translator tests
- WordPress-specific test cases
- Limited edge case coverage

### New Driver
- ~70,000 MySQL queries from MySQL server test suite
- 11,500+ lines of driver tests
- 2,000+ lines of translation tests
- 2,300+ lines of metadata tests
- Comprehensive edge case coverage

## Performance Characteristics

### Old Driver
- Fast for simple queries (less processing)
- Unpredictable for complex queries (regex backtracking)

### New Driver
- ~1,000 complex SELECT queries/second (parser)
- Consistent performance regardless of complexity
- More upfront work, but predictable
- Grammar ~70KB (loaded once)

## Summary

The transition from token-based to AST-based processing represents a fundamental architectural improvement:

| Aspect | Old | New |
|--------|-----|-----|
| Foundation | Ad-hoc patterns | Formal grammar |
| Query understanding | Surface syntax | Deep structure |
| Extensibility | Difficult | Straightforward |
| Correctness | Best effort | Grammar-validated |
| Error messages | Unclear | MySQL-compatible |
| Test coverage | Limited | Comprehensive |
| Plugin support | Fragile | Robust |
