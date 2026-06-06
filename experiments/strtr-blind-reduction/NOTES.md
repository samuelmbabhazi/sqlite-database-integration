# strtr blind reduction

**Origin:** ephemeral exploration (rebuilt fresh, toy grammar). No PR/commit.

**Idea:** build a `strtr` translation table whose keys are reducible right-hand-side
sequences and values are non-terminal placeholders, then iterate `strtr` to a fixed
point. `strtr` with an array does parallel substitution in C, which is fast in
principle.

**Run:** `php -d ...jit... strtr-bench.php` (toy expression grammar, ~79K-entry table).

**Result (ops/sec, warm JIT):** hand-written recursive descent ~7.0M;
preg_match validate-only ~23.6M; preg_replace_callback shift-reduce ~1.8M;
`strtr` iterate-to-stable ~2,600 — roughly 2,650× slower than hand RD.
Throughput is ∝ 1/table-size and independent of input length.

**Verdict:** Dead end. `strtr` scans its entire translation table on every call
regardless of input, so a grammar-sized table dominates per call. The native
function is fast; the per-call whole-table scan is not. (Blind parallel
substitution also can't model ordered shift-reduce — it needs a confluent rule set.)
