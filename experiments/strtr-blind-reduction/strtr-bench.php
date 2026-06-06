<?php
/**
 * strtr blind reduction (toy expression grammar).
 *
 * Grammar:
 *   E -> E ('+'|'*') T | T
 *   T -> num | '(' E ')'
 *
 * Four recognizers over a stream of TOY TOKENS (single-char symbols):
 *   n = num literal, + * ( ) as themselves.
 *
 * (a) hand-written recursive descent (validating)
 * (b) preg_match validate-only (regex for the toy language via recursion (?R))
 * (c) preg_replace_callback shift-reduce (iterate reducing an RHS -> placeholder)
 * (d) strtr iterate-to-stable with a LARGE (~79K-entry) translation table,
 *     padded with synthetic non-matching keys to reproduce the table-scan cost.
 *
 * We measure QPS = recognitions per second, warm JIT, best-of-N.
 */

// ---------------------------------------------------------------------------
// Token alphabet used by all recognizers:
//   'n' = number, '+','*','(',')'
// Reducers (c) and (d) work on these single-char symbols; non-terminals are
// encoded as single bytes too so RHS sequences are fixed strings.
//   'E' and 'T' are the non-terminals.
// ---------------------------------------------------------------------------

/** (a) Hand-written recursive descent. Returns true if the token string is a valid E. */
function rd_parse(string $s): bool {
    $pos = 0;
    $len = strlen($s);
    $ok = rd_E($s, $pos, $len);
    return $ok && $pos === $len;
}
function rd_E(string $s, int &$pos, int $len): bool {
    if (!rd_T($s, $pos, $len)) return false;
    while ($pos < $len && ($s[$pos] === '+' || $s[$pos] === '*')) {
        $pos++;
        if (!rd_T($s, $pos, $len)) return false;
    }
    return true;
}
function rd_T(string $s, int &$pos, int $len): bool {
    if ($pos >= $len) return false;
    $c = $s[$pos];
    if ($c === 'n') { $pos++; return true; }
    if ($c === '(') {
        $pos++;
        if (!rd_E($s, $pos, $len)) return false;
        if ($pos >= $len || $s[$pos] !== ')') return false;
        $pos++;
        return true;
    }
    return false;
}

/** (b) preg_match validate-only. PCRE recursive subpattern for the toy language. */
$GLOBALS['toy_re'] = '/^(?<E>(?<T>n|\((?&E)\))(?:[+*](?&T))*)$/';
function pcre_validate(string $s): bool {
    return (bool) preg_match($GLOBALS['toy_re'], $s);
}

/** (c) preg_replace_callback shift-reduce.
 * Repeatedly reduce reducible RHS sequences to a single non-terminal until
 * the string is exactly "E" (accept) or no further reduction applies (reject).
 * RHS handled:  T->n , T->(E) , E->T , E->E[+*]T
 */
function prc_reduce(string $s): bool {
    // Same confluent rule set as the strtr reducer, but driven by a regex that
    // matches any reducible RHS; iterate to a fixed point. Each call rewrites all
    // non-overlapping matches found in the current string (callback re-derives the
    // replacement), and we loop until the string stops changing.
    //   n -> E ; (E) -> E ; E+E -> E ; E*E -> E
    $re = '/\(E\)|E[+*]E|n/';
    $guard = 0;
    while (true) {
        $s = preg_replace_callback($re, static function () { return 'E'; }, $s, -1, $count);
        if ($count === 0) break;
        if (++$guard > 100000) break;
    }
    return $s === 'E';
}

/** (d) strtr iterate-to-stable with a LARGE padded table.
 * The reduction rules are the same RHS->placeholder rewrites, but applied via
 * strtr() against a table padded to ~PAD_TARGET entries with synthetic
 * non-matching keys, to reproduce the "whole-table scan per call" cost.
 */
$GLOBALS['strtr_table'] = null;
function build_strtr_table(int $pad_target): array {
    // Real reduction rules (longest-key-first is handled by strtr automatically:
    // strtr prefers the longest matching key).
    // strtr does a SINGLE left-to-right non-overlapping pass per call, preferring
    // the longest matching key, and applies all rules SIMULTANEOUSLY (no re-scan of
    // already-substituted output within the same call). So the rule set must be
    // CONFLUENT under that semantics. We encode every value as the single non-terminal
    // 'E' and collapse binary forms, which converges to 'E' for any valid expression:
    //   n      -> E      (atom)
    //   (E)    -> E      (parenthesised)
    //   E+E    -> E      (binary)
    //   E*E    -> E      (binary)
    $table = [
        '(E)' => 'E',
        'E+E' => 'E',
        'E*E' => 'E',
        'n'   => 'E',
    ];
    // Pad with synthetic non-matching keys. Use a byte range that never appears
    // in our token strings (uppercase hex of a counter prefixed with '#').
    $i = 0;
    while (count($table) < $pad_target) {
        $key = '#' . dechex($i);   // '#0','#1',... never present in token input
        $table[$key] = '~';        // arbitrary non-terminal-ish value, never used
        $i++;
    }
    return $table;
}
function strtr_reduce(string $s): bool {
    $table =& $GLOBALS['strtr_table'];
    $guard = 0;
    while (true) {
        $next = strtr($s, $table);
        if ($next === $s) break;     // fixed point
        $s = $next;
        if (++$guard > 100000) break;
    }
    return $s === 'E';
}

// ---------------------------------------------------------------------------
// Representative toy input set (token strings). All are VALID expressions.
// ---------------------------------------------------------------------------
function make_inputs(): array {
    return [
        'n',
        'n+n',
        'n*n',
        'n+n*n',
        '(n)',
        '(n+n)',
        '(n+n)*n',
        'n+(n*n)+n',
        '((n))',
        '(n+n)*(n+n)',
        'n+n+n+n+n',
        'n*n*n*n*n',
        '(n+n*n)+(n*n+n)',
        '((n+n)*(n+n))+n',
        'n+n*(n+n)*n+n',
    ];
}

// ---------------------------------------------------------------------------
// Sanity check: all four agree on the input set (and on a few invalids).
// ---------------------------------------------------------------------------
function sanity(): void {
    $valids = make_inputs();
    $invalids = ['', 'n+', '+n', '(n', 'n)', 'n++n', '()', 'nn', '(n+)'];
    foreach (['rd_parse','pcre_validate','prc_reduce','strtr_reduce'] as $fn) {
        foreach ($valids as $v) {
            if ($fn($v) !== true) { fwrite(STDERR, "SANITY FAIL: $fn rejected valid '$v'\n"); exit(1); }
        }
        foreach ($invalids as $iv) {
            if ($fn($iv) !== false) { fwrite(STDERR, "SANITY FAIL: $fn accepted invalid '$iv'\n"); exit(1); }
        }
    }
    fwrite(STDERR, "sanity OK (all 4 agree on " . count($valids) . " valid + " . count($invalids) . " invalid)\n");
}

// ---------------------------------------------------------------------------
// Benchmark harness: QPS = total recognitions / elapsed, best-of-N runs.
// ---------------------------------------------------------------------------
function bench(callable $fn, array $inputs, int $iters): float {
    // returns ops/sec for one run
    $t0 = hrtime(true);
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        foreach ($inputs as $in) {
            $acc += $fn($in) ? 1 : 0;
        }
    }
    $dt = (hrtime(true) - $t0) / 1e9;
    $ops = $iters * count($inputs);
    if ($acc < 0) echo $acc; // prevent DCE
    return $ops / $dt;
}

$pad = (int) ($argv[1] ?? 79000);
$GLOBALS['strtr_table'] = build_strtr_table($pad);
fwrite(STDERR, "strtr table entries: " . count($GLOBALS['strtr_table']) . "\n");

sanity();

$inputs = make_inputs();

$cfgs = [
    'hand RD                ' => ['fn' => 'rd_parse',       'iters' => 200000],
    'preg_match validate    ' => ['fn' => 'pcre_validate',  'iters' => 200000],
    'preg_replace_callback  ' => ['fn' => 'prc_reduce',     'iters' => 20000],
    'strtr iterate-to-stable' => ['fn' => 'strtr_reduce',   'iters' => 2000],
];

$N = (int) ($argv[2] ?? 7);
$warmup = 2;

$results = [];
foreach ($cfgs as $label => $c) {
    $fn = $c['fn']; $iters = $c['iters'];
    for ($w = 0; $w < $warmup; $w++) bench($fn, $inputs, max(1, (int)($iters/4)));
    $best = 0.0;
    for ($r = 0; $r < $N; $r++) {
        $qps = bench($fn, $inputs, $iters);
        if ($qps > $best) $best = $qps;
    }
    $results[$label] = $best;
}

echo "\n=== QPS (best-of-$N, warm) — strtr pad=$pad ===\n";
$rd = $results['hand RD                '];
foreach ($results as $label => $qps) {
    printf("%-25s %12s QPS   (%.1fx vs hand RD)\n",
        $label, number_format($qps, 0), $qps / $rd);
}
printf("\nstrtr is %.0fx slower than hand RD\n", $rd / $results['strtr iterate-to-stable']);
