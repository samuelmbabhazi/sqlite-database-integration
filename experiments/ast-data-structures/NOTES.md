# Alternative AST data structures

**Origin:** ephemeral exploration (rebuilt fresh from the optimized parser's hot
path). No PR/commit.

**Idea:** the object AST (`WP_Parser_Node`) dominates parse time — try cheaper
node representations and measure the realistic ceiling.

**Run (each variant in its own process):**
```
php -d ...jit... bench-variants.php --src=<.../src> --variant=object|noast|array|tape --reuse
php -d ...jit... microbench.php     # object vs array-literal construction
```

**Result (parse-only, full corpus, best-of-7, warm JIT):**

| variant  | what parse_recursive returns         | QPS   | vs object |
|----------|--------------------------------------|-------|-----------|
| object   | `new WP_Parser_Node(...)` (current)  | ~57K  | 1.00×     |
| noast    | true/false (recognition only)        | ~246K | +330%     |
| array    | `[$rid, $children]`                  | ~55K  | −5%       |
| tape     | flat int tape + in-place rollback    | ~140K | +144%     |

Microbench: `new WP_Parser_Node(...)` ≈ 27 ns; a realistic `[$rid,$child]` array
with a live child ≈ 10 ns ⇒ ~2× (NOT the ~12× a dead-store `[1]` literal suggests —
the JIT elides that literal).

**Verdict:** Recognition is cheap; **AST materialization is ~77% of parse time**
(~246K validate-only ceiling). Swapping an object for an array barely moves the
needle (−5%) because the children-array work dominates and is constant. A *flat
int tape with in-place truncation* is ~2.4× faster to BUILD (the slowness of a
"tape" only appears with a naive `array_slice` copy-on-rollback) — but a tape is
not a usable tree; consumers would need a tape walker. No drop-in node-shape win
at scale; anything dramatic needs consumer cooperation (e.g. a lazy CST).
