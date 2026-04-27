#!/usr/bin/env python3
"""
create_function_v2 — don't fire the old destroy callback on slot reuse (sqlite3/src/lib.rs).

Turso's create_function_v2 invokes the previous FuncSlot's destroy
callback when re-registering a UDF with the same name. In practice
(PHPUnit) this means:
  - setUp #1 opens PDO A, registers 44 UDFs, each with a destroy
    callback + p_app pointing to A.
  - tearDown closes PDO A — Turso's sqlite3_close doesn't clear those
    FuncSlots.
  - setUp #2 opens PDO B, re-registers the same 44 names. Turso invokes
    the OLD destroy callback with the now-dangling A p_app, which trips
    pdo_sqlite and hangs the process.

Comment the destroy invocation out; the callbacks still fire at real
PDO-destruction time from the PHP side.
"""

import sys

PATH = 'sqlite3/src/lib.rs'
OLD = (
    '        // Reuse existing slot — invoke old destroy callback on old user data.\n'
    '        if let Some(old) = slots[id].take() {\n'
    '            if old.destroy != 0 {\n'
    '                let old_destroy: unsafe extern "C" fn(*mut ffi::c_void) =\n'
    '                    std::mem::transmute(old.destroy);\n'
    '                old_destroy(old.p_app as *mut ffi::c_void);\n'
    '            }\n'
    '        }\n'
)
NEW = (
    "        // Don't invoke the old destroy callback here — in PDO\n"
    "        // usage the previous slot's p_app often belongs to a db\n"
    '        // that has already been closed, so the callback UAFs.\n'
    '        let _ = slots[id].take();\n'
)

with open(PATH) as f:
    s = f.read()
if OLD not in s:
    sys.exit(f'{PATH}: slot destroy block not found')
with open(PATH, 'w') as f:
    f.write(s.replace(OLD, NEW, 1))
print('patched slot-reuse destroy invocation')
