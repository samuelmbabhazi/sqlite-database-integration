# Native Rust parser extension

**Origin:** the one native path actually built and shipped — PR #381 (the
extension), follow-ups #384–#394, #423 (native-backed AST nodes), and #378 (which
reworked the AST to materialize eagerly). The crate lives at
`packages/php-ext-wp-mysql-parser/` on those branches; not copied here to keep this
branch a clean diff against trunk.

**Idea:** skip the regex tricks; write the lexer and parser in Rust and ship them
as an optional PHP extension for environments we control.

**Result (parser-only, AST materialized, this machine):**

| config                | trunk PHP | optimized PHP | native       |
|-----------------------|-----------|---------------|--------------|
| opcache + tracing JIT | ~28K QPS  | ~57K          | ~77K (~1.33×) |
| no opcache / no JIT   | ~12K QPS  | ~34K          | ~75K (~2.19×) |

The original "~10×" claim was too optimistic for two reasons, both confirmed:
(1) the native pipeline loaded AST nodes LAZILY and the first benchmark never
materialized them (materializing costs ~3.5×: ~265K → ~77K); (2) it compared
native against pure PHP WITHOUT JIT. With JIT and materialization, native is only
~1.33× over the optimized PHP parser.

**Verdict:** native is faster, but only ~1.3× over optimized PHP under production
JIT — questionable whether the native build is worth maintaining. Notably, the
optimized PHP parser run in validate-only mode (no node materialization, ~246K) is
in the same league as the native lazy path, so the cheapest remaining win is
PHP-side (skip materialization), not native.
