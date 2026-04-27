#!/usr/bin/env python3
"""
Alias MySQL collation names to the nearest Turso built-in (core/translate/collate.rs).

Turso's CollationSeq is a closed enum of three built-in collations
(Binary/NoCase/Rtrim). Driver-emitted SQL references MySQL collations
like `utf8mb4_bin` (byte-compare) and `utf8mb4_0900_ai_ci`
(case-insensitive). Map these to the closest built-in at lookup time
before Turso's EnumString rejects the name.
"""

import sys

PATH = 'core/translate/collate.rs'

OLD = (
    '    pub fn new(collation: &str) -> crate::Result<Self> {\n'
    '        CollationSeq::from_str(collation).map_err(|_| {\n'
    '            crate::LimboError::ParseError(format!("no such collation sequence: {collation}"))\n'
    '        })\n'
    '    }\n'
)
NEW = (
    '    pub fn new(collation: &str) -> crate::Result<Self> {\n'
    '        // Alias common MySQL collation names to the nearest\n'
    '        // Turso built-in before strum rejects them.\n'
    '        let lower = collation.to_ascii_lowercase();\n'
    '        let alias = match lower.as_str() {\n'
    '            "utf8mb4_bin" | "utf8_bin" | "ascii_bin" | "latin1_bin" => "Binary",\n'
    '            "utf8mb4_0900_ai_ci" | "utf8mb4_general_ci" | "utf8_general_ci"\n'
    '            | "latin1_general_ci" | "latin1_swedish_ci" => "NoCase",\n'
    '            _ => collation,\n'
    '        };\n'
    '        CollationSeq::from_str(alias).map_err(|_| {\n'
    '            crate::LimboError::ParseError(format!("no such collation sequence: {collation}"))\n'
    '        })\n'
    '    }\n'
)

with open(PATH) as f:
    s = f.read()
if OLD not in s:
    sys.exit(f'{PATH}: CollationSeq::new body not found')
with open(PATH, 'w') as f:
    f.write(s.replace(OLD, NEW, 1))
print('patched CollationSeq to alias MySQL collations')
