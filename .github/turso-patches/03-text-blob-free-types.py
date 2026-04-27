#!/usr/bin/env python3
"""
TextValue::free / Blob::free — rebuild the original slice box (extensions/core/src/types.rs).

TextValue/Blob `free` reconstructs `Box<u8>` from a pointer that was
originally `Box<str>` / `Box<[u8]>` (fat pointers, length lost in the
cast). This corrupts the heap when custom UDFs return text/blob values.
Fix both frees to use the stored length and rebuild the correct slice
box.
"""

import re
import sys

PATH = 'extensions/core/src/types.rs'

OLD_TEXT = (
    '    #[cfg(feature = "core_only")]\n'
    '    fn free(self) {\n'
    '        if !self.text.is_null() {\n'
    '            let _ = unsafe { Box::from_raw(self.text as *mut u8) };\n'
    '        }\n'
    '    }\n'
)
NEW_TEXT = (
    '    #[cfg(feature = "core_only")]\n'
    '    fn free(self) {\n'
    '        if !self.text.is_null() && self.len > 0 {\n'
    '            unsafe {\n'
    '                let slice = std::slice::from_raw_parts_mut(\n'
    '                    self.text as *mut u8, self.len as usize);\n'
    '                let _ = Box::from_raw(slice as *mut [u8]);\n'
    '            }\n'
    '        }\n'
    '    }\n'
)

with open(PATH) as f:
    s = f.read()
if OLD_TEXT not in s:
    sys.exit(f'{PATH}: TextValue::free not found')
s = s.replace(OLD_TEXT, NEW_TEXT, 1)

# Blob::free uses the same pattern.
blob_pat = re.compile(
    r'(impl Blob \{\n(?:[^}]|\{[^}]*\})*?)'
    r'(    #\[cfg\(feature = "core_only"\)\]\n'
    r'    fn free\(self\) \{\n'
    r'        if !self\.data\.is_null\(\) \{\n'
    r'            let _ = unsafe \{ Box::from_raw\(self\.data as \*mut u8\) \};\n'
    r'        \}\n'
    r'    \}\n)'
)
m = blob_pat.search(s)
if m:
    new_blob = (
        '    #[cfg(feature = "core_only")]\n'
        '    fn free(self) {\n'
        '        if !self.data.is_null() && self.size > 0 {\n'
        '            unsafe {\n'
        '                let slice = std::slice::from_raw_parts_mut(\n'
        '                    self.data as *mut u8, self.size as usize);\n'
        '                let _ = Box::from_raw(slice as *mut [u8]);\n'
        '            }\n'
        '        }\n'
        '    }\n'
    )
    s = s[:m.start(2)] + new_blob + s[m.end(2):]
    print('patched Blob::free')

with open(PATH, 'w') as f:
    f.write(s)
print('patched TextValue::free')
