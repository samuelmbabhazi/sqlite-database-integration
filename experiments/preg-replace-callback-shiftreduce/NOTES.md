# Iterative preg_replace_callback shift-reduce

**Origin:** ephemeral exploration (rebuilt fresh). No PR/commit.

**Idea:** build a mega-pattern = alternation of every rule right-hand side; each
`preg_replace_callback` pass reduces matches into non-terminal placeholders;
iterate until the input collapses to the start symbol. `build-mega.php` is the
shared builder (also used by `../binary-bottomup-reduction/`).

**Run:** `php -d ...jit... bench-13.php`

**Result:** mega-pattern = 4223 alternatives, ~30 KB, JIT-compiles. Per-call costs:
preg_match (first) ~278K QPS; preg_match_all ~22K; preg_replace_callback no-op
~21K; parser baseline ~56K.

**Verdict:** even one no-op `preg_replace_callback` pass is ~2.5× slower than the
parser; a real reducer needs 6+ passes plus AST building (~4K QPS, ~15× slower).
The "find all non-overlapping matches" cost dominates per call — a native C
function is not free. Structural blocker: ~25% of rules have an empty (ε) branch,
which bottom-up reduction can't synthesize.
