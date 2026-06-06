# Other PHP parser libraries: PHP-PEG, Hoa\Compiler, Phlexy (literature)

**Status:** literature/reasoning, not benchmarked. No code.

**Assessment:**
- PEG/packrat parsers in PHP (e.g. PHP-PEG) carry memo-store overhead that makes
  them slower than a tuned hand-written recursive descent.
- `Hoa\Compiler` is itself a `.pp`-grammar interpreter — the same architecture as
  this parser — and slower in practice (it's LL(k), not packrat).
- `Phlexy` (nikic) is lexer-only, and the lexer isn't the bottleneck.

**Verdict:** none of these is likely to beat the current optimized parser; not worth
adopting.
