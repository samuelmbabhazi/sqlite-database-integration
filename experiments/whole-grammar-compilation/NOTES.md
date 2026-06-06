# Whole-grammar → PHP compilation

**Origin:** local branch `_parser_perf` (commits b5959e8, bf1b1fea). No PR.

**Idea:** compile every grammar rule to a dedicated PHP method with switch-on-token
dispatch and inlined symbol matching, instead of interpreting the grammar at
runtime. `compile-grammar.php` emits `WP_MySQL_Compiled_Parser` (extends `WP_Parser`).

**Run** (point requires at the optimized parser tree — PR #378):
```
php compile-grammar.php > /tmp/compiled.php          # 2.48 MB, 50,918 lines, 1427 methods
php -d ...jit... bench-compiled-parser.php --runs=5  # interpreter vs compiled
php compare-asts.php                                 # AST identity check vs interpreter
```

**Result (best-of-N, fresh parser per query):**

| config           | interpreter | compiled | Δ      |
|------------------|-------------|----------|--------|
| no opcache       | ~35K QPS    | ~42K     | +20%   |
| opcache, no JIT  | ~39K QPS    | ~46K     | +18%   |
| opcache + JIT    | ~62K QPS    | ~57K     | −8%    |

**Verdict:** the compiled parser wins ~18–20% WITHOUT JIT but loses ~6–8% under
tracing JIT — the huge generated methods exceed `opcache.jit_max_trace_length`, so
JIT abandons them while the small interpreted hot loop traces tightly. Wrong shape
for tracing JIT (the production default). See `method-size-capping/` for the
attempt to fix this.
