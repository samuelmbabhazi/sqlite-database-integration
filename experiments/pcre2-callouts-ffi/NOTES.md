# PCRE2 callouts via FFI

**Origin:** the working probe (`probe-callout.php`, `bench-callout.php`) was rebuilt
fresh. `exp-pcre-ffi-stale.php` is the earlier `_parser_perf` probe (commit dea9df7)
whose conclusion is WRONG — kept here only to record the correction. No PR.

**Idea:** PCRE2 user callouts ((?C) markers) fire a C callback during matching. With
a callout at every rule entry, one `pcre2_match_8` yields a full trace of which
alternative entered at each rule; a linear walk reconstructs the AST. Stock PHP
doesn't expose `pcre2_set_callout`, so the bridge is PHP FFI.

**Correction:** the stale probe concluded "PHP FFI cannot bind a closure to a C
function pointer → callouts blocked." That is FALSE. The correct idiom is to pass
the PHP closure DIRECTLY as the function-pointer argument to `pcre2_set_callout_8`
(PHP FFI builds a libffi trampoline) — NOT `FFI::cast('cb_t', $closure)`. Verified
on PHP 8.5.5 + libpcre2-8 10.47: matching `1+2*3` against a recursive arithmetic
grammar produced a correct (rule, position) trace from a single match.

**Run:** `php -d ...jit... probe-callout.php` (shows the trace); `bench-callout.php`
(throughput by callout density).

**Result (trace-building closure, best-of-7):**

| input    | callouts/match | QPS   |
|----------|----------------|-------|
| 10 tok   | ~35            | ~314K |
| 50 tok   | ~175           | ~63K  |
| 100 tok  | ~350           | ~29K  |

Per-callout overhead ~50 ns (libffi trampoline + Zend re-entry); register the
closure once per request and reuse the match context (per-registration leak).

**Verdict:** Real and powerful WHERE AVAILABLE — a callout-emitting grammar regex +
trace-driven AST builder is a genuine architecture. But FFI was introduced in PHP
7.4 (so PHP 7.2/7.3 have none) and `ffi.enable` is routinely disabled on shared/
managed WP hosting. The deployment story rules it out as a default.
