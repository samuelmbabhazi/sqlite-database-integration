# Regex pre-validate + parser hybrid

**Origin:** local branch `_parser_perf` (commit 9d36df4c), `exp-regex-hybrid.php`. No PR.

**Idea:** run the full-PCRE recognizer first as a fast yes/no gate; only invoke the
AST-building parser when the regex confirms the query is valid.

**Run:** `php -d ...jit... exp-regex-hybrid.php 100000`

**Result (full corpus, warm JIT):** regex-only ≈ 95K QPS; parser-only (AST) ≈ 65K;
regex + parser ≈ **50K**.

**Verdict:** The hybrid is slower than the parser alone, because essentially every
corpus query is valid SQL — the parser still has to run to build the AST, so the
regex is pure overhead. Pre-validation only pays when invalid input is the common
case, which it isn't in any realistic workload.
