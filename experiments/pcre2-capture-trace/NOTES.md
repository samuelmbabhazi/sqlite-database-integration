# Extracting a parse tree from PCRE2 captures / MARK trace

**Origin:** local branch `parser-fast-path`, `exp-pcre2-trace-findings.php` (a
documented negative result) + the `wall-*.php` probes (rebuilt here). No PR.

**Idea:** compile the grammar to one pattern with a numbered capture (or `(*MARK)`
/ `(?&rule(<tag>))`) per rule occurrence, match once, and walk the captures to
assemble the tree.

**Run:** `php -d ...jit... wall-a-*.php / wall-b-*.php / wall-c-*.php`

**Result — three independent walls:**
- **Capture/compile limit:** PCRE2 compilation is bounded by total complexity, not a
  fixed capture count. The 76 KB grammar pattern compiles at 0 captures and
  tolerates ~1,175 added captures before failing ("pattern too large"); tiny
  patterns tolerate thousands. Capturing all ~1500 rules at once won't compile.
- **JIT collapse on captures around recursion:** adding ~6 numbered captures around
  `(?&rule)` call sites collapses a JIT-able pattern's throughput ~4.6×. (The 76 KB
  grammar pattern doesn't JIT at all, so captures cost little THERE — the collapse
  is the reason a capture-heavy recursive pattern can't be made fast.)
- **$matches export cost:** a pattern with ~1,400 named subroutines costs ~26 µs per
  `preg_match` with `PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL` — already over the
  parser's per-query budget before walking anything.

Separately, PCRE2 only ever exposes last-write-wins ovector slots + one MARK per
match (verified: marking the start rule's branches yields exactly 2 distinct MARKs
for the whole corpus). No per-recursion-frame stack is recoverable.

**Verdict:** Single-pass PCRE2 → AST is infeasible at this grammar's scale in stock
PHP. The structural information simply isn't exposed.
