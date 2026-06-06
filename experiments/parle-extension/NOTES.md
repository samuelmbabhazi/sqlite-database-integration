# parle PECL extension (proposal)

**Status:** evaluated, not installed/benchmarked. No code. The "3–10×" below is an
estimate, not a measurement.

**Idea:** `parle` is a PECL extension wrapping Ben Hanson's C++ `lexertl`/`parsertl`
template libraries (LALR(1)). Push grammar rules at runtime, build the tables at
startup, then parse with semantic actions in native code.

**Constraints (confirmed):** PHP 7.4+; requires a PECL install (absent on most
shared/managed hosting); the parser tables can't be serialized, so the
table-build cost is paid on every cold worker — significant for a grammar of this
complexity.

**Verdict:** a realistic native fast path (est. 3–10×) WHERE it can be installed,
but shared-hosting reality rules it out as a default. Not prototyped here.
