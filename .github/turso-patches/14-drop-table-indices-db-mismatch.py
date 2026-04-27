#!/usr/bin/env python3
"""
DROP TABLE — read indices from the right database (UPSTREAM).

translate_drop_table reads indices from `resolver.schema()` (always
MAIN) to emit Destroy bytecode, but uses the resolved `database_id`
for `db:`. When dropping a TEMP-shadowed table (perm `t` + temp `t`,
DROP resolves to temp), the indices come from MAIN's schema while
Destroy opens the cursor on temp's pager → temp pager reads MAIN's
index root page, which doesn't exist in temp →
"short read on page N: page is pinned".

Repro: testTemporaryTableHasPriorityOverStandardTable —
during the driver's ALTER emulation, DROP TABLE `t` after CREATE
TEMPORARY shadow.

Fix: read indices from with_schema(database_id, ...), same pattern as
patch 12 for sqlite_sequence.

Worth reporting upstream against tursodatabase/turso.
"""

import sys

PATH = 'core/translate/schema.rs'

OLD = (
    '    //  2. Destroy the indices within a loop\n'
    '    let indices = resolver.schema().get_indices(tbl_name.name.as_str());\n'
    '    for index in indices {\n'
)
NEW = (
    '    //  2. Destroy the indices within a loop\n'
    '    // Use the schema for the SAME database the cursor will open in —\n'
    "    // when dropping a TEMP-shadowed table, the table's indices live in\n"
    "    // temp's schema, not main's. Reading from main here would emit\n"
    "    // Destroy with main's index root_page on temp's pager, which then\n"
    "    // tries to read a page that doesn't exist in temp → short read.\n"
    '    let indices: Vec<_> = resolver.with_schema(database_id, |s| {\n'
    '        s.get_indices(tbl_name.name.as_str()).cloned().collect()\n'
    '    });\n'
    '    for index in &indices {\n'
)

with open(PATH) as f:
    s = f.read()
if OLD not in s:
    sys.exit(f'{PATH}: translate_drop_table get_indices block not found')
with open(PATH, 'w') as f:
    f.write(s.replace(OLD, NEW, 1))
print('patched translate_drop_table to read indices from correct database schema')
