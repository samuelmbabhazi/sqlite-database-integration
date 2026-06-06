# Oniguruma (?@...) capture trees (source finding)

**Status:** not runnable in stock PHP — a source/headers finding, not an executed
experiment. No code.

**Idea:** Oniguruma's `mb_ereg` engine has a feature PCRE2 lacks —
`onig_get_capture_tree` returns a structured tree of captures, including those
inside recursion (`(?@...)` capture history) — which could yield a parse tree.

**Findings:**
- The capacity cap is real: `ONIG_MAX_CAPTURE_HISTORY_GROUP = 31` (confirmed in the
  Oniguruma headers PHP links against; error
  `ONIGERR_GROUP_NUMBER_OVER_FOR_CAPTURE_HISTORY = -222`). 31 groups is nowhere near
  a grammar of this size.
- More fundamentally, PHP's mbstring exposes neither the `(?@...)` syntax option nor
  any capture-tree accessor to userland — `mb_ereg` can't even enable it, and the
  tree can't be read without FFI/C.

**Verdict:** not enough capacity, and not reachable from stock PHP regardless. Dead end.
