# Multi-shape regex → direct AST construction

**Origin:** local branches `parser-fast-path` / `perf-with-fastpath`
(commits d3436f92, 9630eb93). No PR. `class-wp-mysql-fast-parser.php` (1209 lines).

**Idea:** for a curated set of common query shapes (INSERT/SELECT/UPDATE/DELETE/
DROP/SHOW/USE/TRUNCATE/SET/EXPLAIN/BEGIN/COMMIT/ROLLBACK), detect the shape with a
single PCRE2 union pattern using `(*MARK:NAME)`, then build the `WP_Parser_Node`
tree directly in PHP. On a miss, fall through to the recursive parser unchanged.

**Run:** wired into `WP_MySQL_Parser::parse()` at token position 0; benchmark
parse-only with the fast path toggled on/off.

**Result:** on a 30K subset, overall ≈ **1.18×** (76K → 90K QPS); **19.06%**
(5,718/30K) of queries hit the fast path; on those queries the speedup is ≈3.4×.
The produced AST is byte-for-byte identical to the recursive parser's
(0 mismatches across the full corpus).

**Verdict:** Real, modest, and orthogonal to the main parser — the one regex-based
hybrid that actually works, because it sidesteps the recursive descent entirely for
the shapes it knows. More shapes = more wins, but each shape needs a hand-written
builder.
