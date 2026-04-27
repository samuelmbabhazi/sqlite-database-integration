#!/usr/bin/env python3
"""
DROP TABLE — read sqlite_sequence root_page from the right database (UPSTREAM).

DROP TABLE for a TEMP table with AUTOINCREMENT looks up
`sqlite_sequence` via `resolver.schema()` (which always returns MAIN's
schema) but opens the cursor with `db: database_id` set to TEMP_DB_ID.
The result: it tries to read MAIN's sqlite_sequence root page (e.g.
page 25) from the TEMP database where that page doesn't exist, raising
"I/O error: short read on page N: expected 4096 bytes, got 0".

See `core/translate/schema.rs::translate_drop_table` around the
`// if drops table, sequence table should reset.` block.
Repro: setUp creates two AUTOINCREMENT permanent tables (creating
MAIN's sqlite_sequence with root_page = 25), then the test creates
and drops a TEMP AUTOINCREMENT table.

Fix: use `resolver.with_schema(database_id, ...)` so the sqlite_sequence
root_page comes from the SAME database the cursor will open in.

Worth reporting upstream against tursodatabase/turso.
"""

import sys

PATH = 'core/translate/schema.rs'

OLD = (
    '    // if drops table, sequence table should reset.\n'
    '    if let Some(seq_table) = resolver\n'
    '        .schema()\n'
    '        .get_table(SQLITE_SEQUENCE_TABLE_NAME)\n'
    '        .and_then(|t| t.btree())\n'
    '    {\n'
)
NEW = (
    '    // if drops table, sequence table should reset.\n'
    '    // Use the schema for the SAME database the cursor will open\n'
    "    // in — for TEMP tables, the resolver's main schema doesn't\n"
    "    // own sqlite_sequence; it lives in temp's schema.\n"
    '    if let Some(seq_table) = resolver\n'
    '        .with_schema(database_id, |s| s.get_table(SQLITE_SEQUENCE_TABLE_NAME))\n'
    '        .and_then(|t| t.btree())\n'
    '    {\n'
)

with open(PATH) as f:
    s = f.read()
if OLD not in s:
    sys.exit(f'{PATH}: translate_drop_table sqlite_sequence reset block not found')
with open(PATH, 'w') as f:
    f.write(s.replace(OLD, NEW, 1))
print('patched translate_drop_table to use correct database schema for sqlite_sequence')
