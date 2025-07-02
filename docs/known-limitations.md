# Known limitations
This file documents the known limitations of the SQLite driver that are difficult
to address and are not currently planned to be resolved.

### Number of affected rows when values are not changed
In some cases, the number of affected rows reported by the SQLite driver may
differ from the number returned by MySQL. This is because SQLite always reports
the number of rows matched by a statement, regardless of whether they were
actually changed or set to the same values as they already held.

Example:

```sql
CREATE TABLE t (id INT);
INSERT INTO t (id) VALUES (1);
UPDATE t SET id = 1;
```

The `UPDATE` statement above reports `0` affected rows in MySQL, but `1` in SQLite.