#!/usr/bin/env python3
"""
Allow DELETE FROM sqlite_sequence (core/translate/delete.rs).

Real SQLite permits DELETE on sqlite_sequence (it's the documented way
to reset the AUTOINCREMENT counter after a TRUNCATE). Turso's delete
translator rejects any table whose name starts with "sqlite_"; exempt
sqlite_sequence specifically.
"""

import sys

PATH = 'core/translate/delete.rs'

OLD = (
    '    if !connection.is_nested_stmt()\n'
    '        && !connection.is_mvcc_bootstrap_connection()\n'
    '        && crate::schema::is_system_table(tbl_name)\n'
    '    {\n'
    '        crate::bail_parse_error!("table {tbl_name} may not be modified");\n'
    '    }\n'
)
NEW = (
    '    if !connection.is_nested_stmt()\n'
    '        && !connection.is_mvcc_bootstrap_connection()\n'
    '        && crate::schema::is_system_table(tbl_name)\n'
    '        && !tbl_name.eq_ignore_ascii_case("sqlite_sequence")\n'
    '    {\n'
    '        crate::bail_parse_error!("table {tbl_name} may not be modified");\n'
    '    }\n'
)

with open(PATH) as f:
    s = f.read()
if OLD not in s:
    sys.exit(f'{PATH}: delete.rs system-table guard not found')
with open(PATH, 'w') as f:
    f.write(s.replace(OLD, NEW, 1))
print('patched delete.rs to allow DELETE FROM sqlite_sequence')
