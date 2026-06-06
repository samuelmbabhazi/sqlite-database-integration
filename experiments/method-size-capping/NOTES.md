# Method-size capping with runtime fallback

**Origin:** local branch `_parser_perf`. No PR. Uses the compiler in
`../whole-grammar-compilation/compile-grammar.php` with extra flags:
`--cap=N` (rules over N lines become stubs), `--stub-single-candidate`,
`--only-rids-file=FILE`. A stubbed rule is a 3-line method
`return $this->parse_recursive($rid);` that delegates back to the interpreter.
`bench-hot-rules.php` (here) ranks rules by call frequency (`DUMP_TOPN=50` writes
`/tmp/top50.txt` for `--only-rids-file`).

**Idea:** keep only the hottest / smallest rules compiled (so each method stays
under the JIT trace-length limit) and let the cold tail run in the interpreter —
hoping for "small AND fast."

**Run:**
```
DUMP_TOPN=50 php bench-hot-rules.php
php ../whole-grammar-compilation/compile-grammar.php --cap=200 > /tmp/compiled.php
php ../whole-grammar-compilation/compile-grammar.php --only-rids-file=/tmp/top50.txt > /tmp/compiled.php
php -d ...jit... ../whole-grammar-compilation/bench-compiled-parser.php --runs=9
```

**Result (warm JIT, compiled / interpreter):**

| budget                        | size    | speedup |
|-------------------------------|---------|---------|
| no cap                        | 2.48 MB | ~0.68×  |
| cap 200                       | 2.1 MB  | ~0.92×  |
| cap 100 + stub single-cand.   | 868 KB  | ~0.93×  |
| top-50 hot rules only         | ~280 KB | ~0.92×  |

All budgets verified AST-identical to the interpreter across the corpus.

**Verdict:** capping rescues the no-cap JIT disaster (0.68×) but then PLATEAUS just
below parity (~0.92×) — it approaches 1.0× from below as more rules are stubbed
(smaller == closer to the interpreter) and never exceeds it. A focused partial
compiler is not worth it for this grammar under tracing JIT.
