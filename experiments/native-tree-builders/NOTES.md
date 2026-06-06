# Native tree builders: json_decode / unserialize / DOMDocument (reasoning)

**Status:** reasoning, not benchmarked. No code.

**Idea:** PHP ships several very fast C-implemented tree parsers. Transform the SQL
token stream into a format one of them understands (JSON, PHP-serialize, XML), then
let the native function build the tree for free.

**Why it fails:** any meaningful transform from SQL into JSON/serialize/XML must
encode the nesting structure — and computing that structure *is* parsing. SQL is
not mechanically pre-formattable into a nested JSON/XML shape without already
having parsed it. (The one shallow exception is a flat, non-recursive subset — e.g.
INSERT value lists — the same narrow-shape limit as the multi-shape fast parser.)

**Verdict:** conceptually appealing, structurally circular.
