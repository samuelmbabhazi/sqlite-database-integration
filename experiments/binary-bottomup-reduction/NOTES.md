# Bottom-up reduction with custom binary encoding

**Origin:** ephemeral exploration (rebuilt fresh). No PR/commit. Reuses
`../preg-replace-callback-shiftreduce/build-mega.php`.

**Idea:** encode tokens and reduced non-terminals as fixed-width binary records
(4-byte type+slot, 3-byte UTF-8 codepoints, single-byte), match right-hand-side
sequences via PCRE2 in `/s` mode, and reduce iteratively — hoping a tighter
encoding beats the codepoint shift-reduce.

**Run:** `php -d ...jit... bench-14.php`

**Result:** across encodings the per-call floor is a ~20–30K QPS band (±~1.4× by
byte width), all below the ~56K parser. The 4-byte binary variant doesn't compile
(~72 KB pattern → PCRE2 "too large").

**Verdict:** same wall as the preg_replace_callback approach, from a different
direction — the per-call match-finding cost is encoding-independent, and the same
epsilon-reduction problem applies.
