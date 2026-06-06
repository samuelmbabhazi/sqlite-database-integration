# Packed binary vs PHP-array table lookups

**Origin:** ephemeral microbenchmark. No PR/commit.

**Idea:** could a parser action/selector table be stored as a packed binary string
(substr + unpack) more cheaply than nested/flat PHP arrays?

**Run:** `php -d ...jit... pack-microbench.php` (2000×300 table, 2^20 random probes).

**Result (ns per lookup, warm JIT):**

| operation                        | ns/lookup |
|----------------------------------|-----------|
| nested PHP array `$a[$s][$t]`     | ~13.7     |
| flat PHP array `$a[$s*W+$t]`       | ~9.7      |
| packed binary `substr`+`unpack`    | ~40 (≈4× slower) |
| bulk `unpack('n*', $bytes)`        | ~4.9× faster than an `ord()` loop |

**Verdict:** pack/unpack wins for BULK decoding at boundaries (one call, many ints)
but loses ~4× on hot-path random lookups. opcache-shared PHP arrays beat any
packed action table for per-step dispatch. Useful as a serialization primitive,
not a hot-path primitive.
