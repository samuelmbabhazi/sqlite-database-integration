# Table-driven LALR(1) in pure PHP (proposal)

**Status:** evaluated via reality-check only, not implemented. No code.

**Idea:** generate an action/goto table from a yacc-style grammar and interpret it
in a tight while loop — the shape nikic/PHP-Parser uses to parse PHP itself
(kmyacc-generated LALR).

**Reality check:** on parsing PHP source, microsoft/tolerant-php-parser (hand-written
recursive descent) is roughly 40% faster than nikic/PHP-Parser (kmyacc-LALR). The
intuition that "LALR is faster because there's no method dispatch" doesn't clearly
hold in PHP: table dispatch in the hot loop can cost more than method calls the JIT
can inline.

**Verdict:** worth a focused spike if we accept the grammar-conversion cost; not a
clear win, and not prototyped.
