# SQLite as the parser (proposal)

**Status:** proposal, not implemented. No code.

**Idea:** SQLite is already a dependency; for DML that overlaps SQLite syntax,
lean on SQLite's own (fast, native) parser to classify or pre-parse queries, only
falling back to the full MySQL parser for the rest.

**Caveat (important):** SQLite does NOT expose a parse tree/AST. `EXPLAIN QUERY PLAN`
returns an *execution plan* (table scans, index usage, sort order — post-optimization),
not a syntactic structure, and its output is documented as unstable. The most
SQLite can give cheaply is syntactic accept/reject via `prepare()` — a coarse
classifier with no structure to translate, and syntactic acceptance ≠ MySQL
semantics anyway.

**Verdict:** at best a yes/no gate for trivial queries; can't supply an AST. Maybe a
feasibility spike for the proxy. Not prototyped.
