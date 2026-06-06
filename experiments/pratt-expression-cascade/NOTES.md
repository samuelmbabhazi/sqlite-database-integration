# Pratt parser for the expression cascade (proposal)

**Status:** evaluated, not implemented. No code.

**Idea:** replace the deep `expr → boolPri → predicate → bitExpr → simpleExpr → …`
recursive cascade — where most method-dispatch overhead lives — with a single
`parseExpression(min_bp)` driven by a per-token-id `(left_bp, right_bp, parse_fn)`
table. Production C compilers (GCC, Clang) use a Pratt-style inner loop inside
their hand-written recursive descent for exactly this.

**Premise check:** the cascade is real — `expr`, `boolPri`, `predicate`, `bitExpr`,
`simpleExpr` all exist in the grammar.

**Verdict:** estimated 5–25% on expression-heavy queries (WHERE clauses, complex
projections); medium engineering cost, low risk. Worth a prototype; not yet built.
