# Full-PCRE grammar recognizer

**Origin:** local branch `_parser_perf` (commit e0c09f8f), `exp-regex-v3.php`. No PR.

**Idea:** fold the whole grammar into one extended PCRE pattern using
`(?(DEFINE)...)` named subroutines, encode each token id as a codepoint
(offset 0x4000), `(*THEN)` on disjoint-FIRST branches, and inline single-use
rules to a fixpoint. PCRE2's JIT then recognizes queries.

**Run:** `php -d ...jit... exp-regex-v3.php 100000`

**Result:** pattern = 76,488 bytes, 1127 named subroutines (789 rules inlined).
Recognition ≈ **98K QPS**, recognizing 99.85% of the corpus.

**Verdict:** It is a *recognizer*, not a parser — PCRE2 returns only last-write-wins
ovector slots plus one `(*MARK)`, so per-recursion-frame structure can't be
recovered in stock PHP. At ~98K it is faster than the AST-building parser (~57K),
but ~2.6× SLOWER than that same parser run in validate-only mode (~246K) — so as a
pure recognizer it loses. Still, it inspires the hybrids (shape fast-path, FFI
callouts).
