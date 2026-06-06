# MySQL parser performance experiments

This branch consolidates and verifies the parser/lexer performance experiments
that were explored while optimizing the pure-PHP MySQL parser. The shipped
optimizations live in PR #378 (built on #373 / #375 / #376); the optional native
Rust extension is PR #381 (and #423). The work here is the catalog of *other*
approaches that were prototyped and measured along the way — most lived only in
throwaway local branches or ephemeral sessions and had no home until now.

Everything was re-measured on a MacBook Pro M4, PHP 8.5.5, PCRE2 10.47.
Numbers drift ~10–15% with thermal/load; treat them as orders of magnitude and
ratios, not exact constants.

## How to run
Warm tracing JIT (the production-relevant config):
```
-d memory_limit=2G -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=tracing
```
No opcache: `-d opcache.enable_cli=0`. opcache without JIT: `-d opcache.enable_cli=1 -d opcache.jit=disable`.
Always put `-d` flags BEFORE the script path. The corpus is the 69,577-query
MySQL server-suite CSV at `packages/mysql-on-sqlite/tests/mysql/data/`.

Verified parse-only baselines (best-of-N, reuse one parser, warm JIT):
trunk ≈ 27,700 QPS; the optimized parser (#378) ≈ 56,500 QPS (≈2.0×);
pure-regex recognition ≈ 98K; the parser in validate-only mode ≈ 246K.
AST construction is ≈77% of parse time.

## Experiments (one per directory, one per commit)
`_harness/` holds the parse-only benchmark harnesses used throughout. Each
experiment directory has a `NOTES.md` with the idea, how it was measured, the
result, and a verdict; see each for origin (PR or local branch).

- `whole-grammar-compilation/` — compile every rule to a dedicated PHP method.
- `method-size-capping/` — cap compiled method size, stub the rest to the interpreter.
- `ast-data-structures/` — object vs validate-only vs flat-int-tape vs array node.
- `pratt-expression-cascade/` — Pratt operator-precedence parser for the expr chain.
- `ll2-selectors/` — 2-token-lookahead proposal + the rule/call-split analysis behind it.
- `lalr-table-driven/` — kmyacc/nikic-style action-goto table interpreter.
- `packed-table-lookups/` — pack/unpack vs PHP-array action-table lookups.
- `full-pcre-recognizer/` — fold the whole grammar into one recursive PCRE pattern.
- `regex-prevalidate-hybrid/` — regex yes/no gate in front of the AST parser.
- `multishape-fast-parser/` — per-query-shape regex → direct AST construction.
- `pcre2-capture-trace/` — extract a parse tree from PCRE2 captures.
- `pcre2-callouts-ffi/` — PCRE2 callouts via FFI to emit a structural trace.
- `preg-replace-callback-shiftreduce/` — iterative mega-pattern reduction.
- `binary-bottomup-reduction/` — the same, with fixed-width binary encodings.
- `oniguruma-capture-trees/` — `(?@...)` capture trees (31-group cap; unreachable in PHP).
- `strtr-blind-reduction/` — strtr iterate-to-stable reduction (toy grammar).
- `native-tree-builders/` — json_decode/unserialize/DOMDocument (circular).
- `parle-extension/` — the `parle` PECL LALR(1) extension.
- `other-php-parser-libs/` — PHP-PEG / Hoa\Compiler / Phlexy.
- `sqlite-as-parser/` — use SQLite's own parser as a classifier.
- `ast-cache/` — cache the AST on a parameterized token-stream signature.
- `native-rust-extension/` — the optional Rust extension (PR #381/#423/#378).
