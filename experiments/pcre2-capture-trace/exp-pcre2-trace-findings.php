<?php
/**
 * Findings: can we extract a per-recursion-frame structural trace from
 * a single preg_* call (or a small bounded number of calls) using only
 * stock PHP, fast enough to beat the recursive-descent parser AND
 * reconstruct the same AST?
 *
 * SHORT ANSWER
 * ============
 * No. PCRE2's design rule "one ovector slot per static occurrence,
 * last-write-wins on the matching path" is enforced everywhere a PHP
 * userland API touches it. Pre-recursion frame state is not exposed.
 *
 * The only PCRE2 feature engineered to expose intermediate state to
 * the caller is the callout interface (pcre2_set_callout), and PHP's
 * preg_*, FFI, and substitute paths all decline to wire it through.
 * Without callouts we can recover at most:
 *   - one MARK per top-level preg_match, OR
 *   - one MARK per iteration of a \G-anchored preg_match_all loop
 *     (one MARK per top-level alternative consumed), OR
 *   - one inner capture per (*scs:...) substring-scan layer
 *     (bounded depth, last-wins per name).
 * None of these reaches "stack of values per recursion frame".
 *
 * EMPIRICAL DECISION
 * ==================
 * With 30000 queries from mysql-server-tests-queries.csv, JIT on,
 * pre-tokenized to encoded PCRE2 input:
 *
 *   validator only:    105K QPS
 *   validator + MARK:  102K QPS (3% overhead, near-free)
 *   parser (full AST): 41K QPS
 *
 * Adding (*MARK:NAME) to the START rule's branches yields exactly TWO
 * distinct MARK values across all 30000 queries (because the `query`
 * rule has 2 branches). Putting MARKs deeper would just overwrite to
 * "the deepest mark on the matching path", giving one tag per match
 * regardless of how much structure was traversed.
 *
 * The validator → parser headroom is real (~63K QPS), but it cannot
 * be used: extracting one MARK per query gives no useful structural
 * information beyond what FIRST-set lookup already gives the parser.
 *
 * FEATURES TESTED (pure-PHP / preg_* / FFI)
 * =========================================
 * Tests live in:
 *   tests/tools/exp-pcre2-probes.php
 *   tests/tools/exp-pcre2-probes-2.php
 *   tests/tools/exp-pcre2-mark-trace.php
 *
 *  Works as documented:
 *  --------------------
 *   preg_match_all + \G + (*MARK:NAME)
 *     Per-iteration MARK survives in PREG_SET_ORDER and
 *     PREG_PATTERN_ORDER. Throughput: ~24 Mtok/s (PATTERN_ORDER) and
 *     ~17 Mtok/s (SET_ORDER). Limit: each iteration is a fresh
 *     pcre2_match call; only the LAST mark on that call's path
 *     survives. Useful for tokenization, not for nested structure.
 *
 *   preg_replace_callback carries 'MARK' in $matches
 *     Confirmed: $matches['MARK'] is set per callback invocation.
 *     Throughput: ~13 Mtok/s. Same per-call last-mark limit applies.
 *     Outermost-only — recursion (?R) does not invoke the callback
 *     for inner matches.
 *
 *   (*ACCEPT:NAME), (*COMMIT:NAME), (*THEN:NAME) all feed MARK
 *     Verified. Useful when you want to mark the success-classification
 *     point separately from a structural marker — last-on-path wins.
 *     (*PRUNE:NAME) and (*FAIL:NAME) do NOT surface in PHP's $matches
 *     when the wrapping match returns 0.
 *
 *   (*scan_substring:(<group>)...) / (*scs:(<group>)...)  [PCRE2 10.46+]
 *     Compiles and runs. Inner named captures inside (*scs:...) DO
 *     persist to the outer match's $matches. The scs operates on the
 *     captured substring, so this is effectively a free second-pass
 *     parse with composable capture exposure. Bounded depth — useful
 *     for, e.g., capturing a SELECT clause then its column-list, but
 *     not for arbitrary tree depth.
 *
 *   (?&rule(<tag>)) capture-retaining subroutine call  [PCRE2 10.46+]
 *     Compiles. <tag> is retained after the subroutine returns. BUT:
 *     repeated (?&rule(<tag>))+ overwrites the same slot — the post-
 *     match value is the LAST iteration's value. Confirmed
 *     empirically with input "abc" → tag="c". The new feature does
 *     not give a stack; it only changes the default of "throw
 *     captures away" to "keep them, last-wins".
 *
 *   MARK from inside (?&rule), lookarounds, atomic groups
 *     Surfaces to outer match (matching-path rule applies).
 *
 *  Verified dead-ends:
 *  -------------------
 *   ${*MARK} / $MARK / ${MARK} substitution syntax
 *     PHP's preg_replace does not parse these — output contains the
 *     literal text. PHP does not call pcre2_substitute().
 *
 *   Failed-match MARK
 *     pcre2_get_mark() returns the deepest MARK reached even on
 *     failure, but PHP's preg_match populates $matches['MARK'] only
 *     when the match succeeds.
 *
 *   Recursive backref \k<name> with re-entered captures
 *     PCRE2 returns "Internal error" in PHP — recursion does not
 *     stack capture values for backref purposes.
 *
 *   PCRE2_AUTO_CALLOUT, (?C) callouts without a registered callback
 *     Compile fine, silently no-op.
 *
 *   pcre2_dfa_match (multi-result match)
 *     Not exposed by PHP; also doesn't populate captures even if it
 *     were.
 *
 *   pcre2_callout_enumerate, pcre2_substring_nametable_scan
 *     Not exposed by PHP.
 *
 *   FFI binding of a PHP closure to pcre2_set_callout's function
 *     pointer
 *     PHP's libffi closure support is not enabled. Documented in
 *     exp-pcre-ffi.php.
 *
 *   (?J) duplicate names with PREG_OFFSET_CAPTURE
 *     One slot per static occurrence (NOT per recursion). Last-wins.
 *
 *   PCRE2 substitute callouts (pcre2_set_substitute_callout)
 *     Would expose subscount per replacement, but PHP does not call
 *     pcre2_substitute(); preg_replace[ _callback]() iterate
 *     pcre2_match() manually.
 *
 * WHY THE BAR CAN'T BE MET IN PURE PHP
 * ====================================
 * The corpus AST has, on average, dozens of nodes per query. Even
 * with a perfect O(N)-token MARK trace at validator speed
 * (105K QPS), reconstructing the same AST that the parser produces
 * requires creating those PHP nodes — and PHP object construction
 * alone (~200-500ns per node) consumes most of the 16µs/query
 * budget that separates 105K QPS from the parser's 41K QPS. A
 * coarse top-level MARK gives nothing the parser doesn't already
 * derive from the FIRST-set table at zero cost.
 *
 * The parser is the floor in pure PHP. Beating it requires either
 * (a) a smaller AST (different consumer), or (b) a callout-driven
 * trace from a single PCRE2 match.
 *
 * MINIMAL PHP EXTENSION PROPOSAL
 * ==============================
 * The cleanest path forward is a tiny PHP extension that exposes
 * pcre2_set_callout(). It needs ~150 lines of C and three exposed
 * functions:
 *
 *   resource pcre_callout_compile(string $pattern, int $flags = 0)
 *     Wraps pcre2_compile_8() + pcre2_jit_compile_8().
 *
 *   array pcre_callout_match(resource $code, string $subject,
 *                            int $options = 0)
 *     Calls pcre2_match_8() with a C trampoline registered via
 *     pcre2_set_callout_8(). The trampoline appends a fixed-size
 *     record (callout_number, current_position, capture_top) to a
 *     pre-allocated buffer for each fired callout. After the match,
 *     the buffer is materialised into a PHP array of [num, pos, cap]
 *     tuples and returned alongside the standard ovector.
 *
 *   void pcre_callout_set_buffer_size(int $bytes)
 *     Tunes the per-thread callout buffer (default 64K records,
 *     ~1MB).
 *
 * Why this is enough: with auto-callouts enabled (PCRE2_AUTO_CALLOUT)
 * or explicit (?C<num>) at every grammar rule entry / branch boundary,
 * a single pcre2_match_8 call against the existing 76KB grammar
 * pattern yields a complete trace of which alternative entered and
 * succeeded at each rule, in chronological order. Reconstructing the
 * AST is then a linear walk of the trace.
 *
 * Cost estimate: the existing validator at 126K QPS (warm JIT) does
 * ~8µs of PCRE2 work per query. Adding a callout trampoline that
 * appends 24 bytes per fire is a few-ns overhead per callout; for an
 * average MySQL query with ~30-50 firing callouts we'd add <1µs of
 * trampoline overhead, leaving budget for AST construction.
 *
 * Build complexity: a single .c file linked against the same
 * libpcre2-8 PHP itself uses, packaged as a loadable Zend extension.
 * No third-party deps. Could ship as a vendored optional extension
 * (parallel to opcache) without affecting the pure-PHP fallback path.
 *
 * Risks:
 *   - JIT-mode callouts: per pcre2callout(3), JIT supports user
 *     callouts but sets callout_flags=0. Field availability on
 *     pcre2_callout_block needs to be sanity-checked when
 *     pcre2_jit_compile is enabled. Worst case: fall back to
 *     interpreted matching for traced runs.
 *   - PHP version skew: pcre2_set_callout signature is stable since
 *     PCRE2 10.00, but PHP bundles its own libpcre2 (`pcrelib`) on
 *     Windows and some BSDs. The extension should dlsym against the
 *     same library PHP uses, not a system one.
 *
 * If the extension is built and the trampoline emits ~24 bytes per
 * callout into a flat buffer, the realistic ceiling is in the
 * 80K-100K QPS range with full AST reconstruction — a 2× win over
 * the current parser. That is the payoff that makes the C work
 * worth it; nothing in pure PHP comes close.
 */
