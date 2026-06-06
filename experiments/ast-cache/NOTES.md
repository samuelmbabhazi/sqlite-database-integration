# AST cache keyed by parameterized template

**Origin:** local branch `ast-cache` (fully implemented: cache + driver wiring +
unit/equivalence tests + benchmark). No PR. The files here are copied from that
branch as reference artifacts — they run on the `ast-cache` branch, where the cache
is wired into `WP_MySQL_Parser`.

**Idea:** cache the AST on a parameterized token-stream signature so e.g.
`WHERE id = 5` and `WHERE id = 8` share one entry; serve the cached AST on a hit
instead of re-parsing. Not a parser change — a layer above it.

**Run (on the `ast-cache` branch):**
```
php -d ...jit... run-ast-cache-benchmark.php --scenarios=hit,corpus,wp --rounds=2 --iters=5 --sleep=0
```

**Result (ABAB cache-off vs cache-on, warm JIT):**

| scenario               | off    | on    | hit rate | speedup |
|------------------------|--------|-------|----------|---------|
| hit (100% repeats)     | ~190K  | ~374K | 1.00     | 1.96×   |
| WordPress-like repeats | ~128K  | ~303K | 1.00     | 2.36×   |
| mostly-unique corpus   | ~57K   | ~47K  | 0.27     | 0.84×   |

Memory: ~1.87 MB at a 200-entry cap.

**Verdict:** ~2–2.4× on repeat-heavy workloads (e.g. WordPress's parameterized
queries), orthogonal to and stackable with the parser optimizations — but a net
LOSS (~0.84×) on unique-query streams, because computing the signature costs more
than the parse it avoids. Worth shipping only gated on observed query repetition.
