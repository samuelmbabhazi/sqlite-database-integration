#!/usr/bin/env python3
"""
Function-name lookup case-insensitivity (core/connection.rs + core/ext/mod.rs).

SQLite looks up function names case-insensitively, but Turso's extension
registry stores names as-is and connection.rs looks them up with
HashMap::get directly. The driver's translator emits e.g. THROW(...)
uppercase, so 32 tests fail with "no such function: THROW" even though
we registered "throw". Normalise to lowercase at both register and
lookup sites.
"""

import sys

CONN = 'core/connection.rs'
EXT = 'core/ext/mod.rs'

with open(CONN) as f:
    s = f.read()
old = 'self.functions.get(name).cloned()'
new = 'self.functions.get(&name.to_lowercase()).cloned()'
if old not in s:
    sys.exit(f'{CONN}: resolve_function lookup not found')
with open(CONN, 'w') as f:
    f.write(s.replace(old, new, 1))

with open(EXT) as f:
    s = f.read()
old = '(*ext_ctx.syms).functions.insert(\n            name_str.clone(),'
new = '(*ext_ctx.syms).functions.insert(\n            name_str.to_lowercase(),'
if old not in s:
    sys.exit(f'{EXT}: register_scalar_function insert not found')
with open(EXT, 'w') as f:
    f.write(s.replace(old, new, 1))

print('patched function-name case (register + resolve)')
