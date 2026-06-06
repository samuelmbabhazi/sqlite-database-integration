<?php

/**
 * Per-process LRU cache for parsed MySQL ASTs keyed by parameterised
 * token-stream signatures.
 *
 * Two queries that differ only in literal values (e.g. WHERE id=5 vs
 * WHERE id=8) produce the same signature and reuse a single cached AST.
 * On a cache hit the cached AST is cloned and the cloned tokens are bound
 * to the *current* query's token instances, so consumers see the input
 * offsets of the current query, not the original cached one.
 *
 * The cache is populated by successful parses only; failures never poison
 * it. The grammar version is folded into every key so the cache is
 * automatically invalidated when the grammar changes.
 *
 * Cache sizing: with the default cap of 200 entries, peak memory is around
 * ~2.8 MB on PHP 8.5 for typical WordPress workloads.
 */
final class WP_MySQL_Parser_Ast_Cache {
	/**
	 * Default cache capacity (number of entries).
	 */
	const DEFAULT_CAPACITY = 200;

	/**
	 * The MySQL lexer assigns token ids in compact ranges. Identifier-like
	 * tokens (whose bytes affect what the query refers to) and literal
	 * tokens (whose bytes are intentionally elided from the signature)
	 * occupy the contiguous range 781..794. Range checks against these
	 * constants compile to simple integer compares under tracing JIT,
	 * which is cheaper than isset() on a small array on the hot path.
	 *
	 * 781        : BACK_TICK_QUOTED_ID  (identifier - bytes included)
	 * 782..791   : literal numeric/string types  (no bytes)
	 * 792..794   : AT_TEXT_SUFFIX, IDENTIFIER, UNDERSCORE_CHARSET (bytes included)
	 */
	const PARAMETERIC_RANGE_START = 781;
	const PARAMETERIC_RANGE_END   = 794;
	const LITERAL_RANGE_START     = 782;
	const LITERAL_RANGE_END       = 791;

	/**
	 * Grammar version prefix included in every cache key.
	 *
	 * @var string
	 */
	private $grammar_version;

	/**
	 * Maximum number of entries before LRU eviction kicks in.
	 *
	 * @var int
	 */
	private $capacity;

	/**
	 * Cached entries, in least-recently-used insertion order.
	 *
	 * Each entry is `[ WP_Parser_Node $ast_template, int $consumed ]`.
	 *
	 * @var array<string,array{0:WP_Parser_Node,1:int}>
	 */
	private $entries = array();

	/**
	 * Number of cache hits.
	 *
	 * @var int
	 */
	private $hits = 0;

	/**
	 * Number of cache misses.
	 *
	 * @var int
	 */
	private $misses = 0;

	/**
	 * Number of LRU evictions performed.
	 *
	 * @var int
	 */
	private $evictions = 0;

	/**
	 * Constructor.
	 *
	 * @param string $grammar_version An opaque marker that distinguishes the
	 *                                grammar version. Including a different
	 *                                value invalidates all entries.
	 * @param int    $capacity        Maximum number of entries (default 200).
	 */
	public function __construct( string $grammar_version, int $capacity = self::DEFAULT_CAPACITY ) {
		$this->grammar_version = $grammar_version;
		$this->capacity        = $capacity > 0 ? $capacity : self::DEFAULT_CAPACITY;
	}

	/**
	 * Get the cache capacity.
	 *
	 * @return int
	 */
	public function get_capacity(): int {
		return $this->capacity;
	}

	/**
	 * Update the cache capacity. If the new cap is smaller than the current
	 * size, oldest entries are evicted until size matches.
	 *
	 * @param int $capacity New capacity. Must be greater than 0.
	 */
	public function set_capacity( int $capacity ): void {
		if ( $capacity <= 0 ) {
			$capacity = self::DEFAULT_CAPACITY;
		}
		$this->capacity = $capacity;
		while ( count( $this->entries ) > $this->capacity ) {
			// PHP 7.2-compatible "drop the oldest entry": foreach yields the
			// least-recently-used key first because PHP arrays preserve
			// insertion order.
			foreach ( $this->entries as $first_key => $_unused ) {
				unset( $this->entries[ $first_key ] );
				break;
			}
			++$this->evictions;
		}
	}

	/**
	 * Drop all entries and reset counters.
	 */
	public function clear(): void {
		$this->entries   = array();
		$this->hits      = 0;
		$this->misses    = 0;
		$this->evictions = 0;
	}

	/**
	 * Get current cache statistics.
	 *
	 * @return array{entries:int,capacity:int,hits:int,misses:int,evictions:int}
	 */
	public function get_stats(): array {
		return array(
			'entries'   => count( $this->entries ),
			'capacity'  => $this->capacity,
			'hits'      => $this->hits,
			'misses'    => $this->misses,
			'evictions' => $this->evictions,
		);
	}

	/**
	 * Compute a cache key for a slice of the token stream.
	 *
	 * The signature emits the token id for every token, plus the raw bytes
	 * for identifier-like tokens. Literal token bytes are intentionally
	 * elided so that two queries that differ only in literal values produce
	 * the same key.
	 *
	 * @param  WP_Parser_Token[] $tokens The full token stream.
	 * @param  int               $start  Inclusive start index.
	 * @param  int               $end    Exclusive end index.
	 * @return string                    A binary cache key (grammar version + 20-byte sha1).
	 */
	public function compute_signature( array $tokens, int $start, int $end ): string {
		// PHP arrays use binary-safe string keys natively, so we use the
		// raw buffer directly as the cache key instead of paying a hash
		// pass like sha1. The grammar version is prefixed verbatim.
		$buffer = $this->grammar_version;
		for ( $i = $start; $i < $end; ++$i ) {
			$token   = $tokens[ $i ];
			$id      = $token->id;
			$buffer .= pack( 'N', $id );
			// Fast path: most tokens are keywords/operators outside the
			// identifier/literal range, so we just emit the id.
			if ( $id < self::PARAMETERIC_RANGE_START || $id > self::PARAMETERIC_RANGE_END ) {
				continue;
			}
			// Literal: id only, no bytes (so 5 vs 8 etc. share a key).
			if ( $id >= self::LITERAL_RANGE_START && $id <= self::LITERAL_RANGE_END ) {
				continue;
			}
			// Identifier-like: id + bytes (so `FROM a` and `FROM b` differ).
			$bytes   = $token->get_bytes();
			$buffer .= pack( 'N', strlen( $bytes ) ) . $bytes;
		}
		return $buffer;
	}

	/**
	 * Look up a cached AST by token slice.
	 *
	 * Returns `null` on miss. On hit, returns a freshly-cloned AST whose
	 * token leaves point to the entries of `$tokens` at the corresponding
	 * positions (so callers reading `$token->start` see the current query),
	 * along with the number of tokens consumed.
	 *
	 * @param  WP_Parser_Token[]                       $tokens The full token stream.
	 * @param  int                                     $start  Inclusive start index.
	 * @param  int                                     $end    Exclusive end index.
	 * @return array{0:WP_Parser_Node,1:int}|null              [$ast, $consumed] or null.
	 */
	public function lookup( array $tokens, int $start, int $end ): ?array {
		$key = $this->compute_signature( $tokens, $start, $end );
		return $this->lookup_by_key( $key, $tokens, $start );
	}

	/**
	 * Look up a cached AST by precomputed key.
	 *
	 * Lets callers compute the signature once and reuse it for the
	 * subsequent {@see store_by_key()} call on a miss. This halves
	 * signature work on the parser's hot path.
	 *
	 * @param  string                                  $key    Cache key from compute_signature().
	 * @param  WP_Parser_Token[]                       $tokens The full token stream.
	 * @param  int                                     $start  Inclusive start index.
	 * @return array{0:WP_Parser_Node,1:int}|null              [$ast, $consumed] or null.
	 */
	public function lookup_by_key( string $key, array $tokens, int $start ): ?array {
		if ( ! isset( $this->entries[ $key ] ) ) {
			++$this->misses;
			return null;
		}

		++$this->hits;
		$entry = $this->entries[ $key ];
		// LRU: re-insert at the end so this becomes the most recently used.
		unset( $this->entries[ $key ] );
		$this->entries[ $key ] = $entry;

		$ast = $this->rebuild_ast_from_template( $entry[0], $entry[1], $entry[2], $tokens, $start );
		return array( $ast, $entry[3] );
	}

	/**
	 * Store a successful parse in the cache.
	 *
	 * The signature is computed over `[$start, $start + $consumed)` (the
	 * exact tokens that produced the AST). The AST is stored by reference;
	 * callers must not mutate it after handing it to the cache.
	 *
	 * @param WP_Parser_Token[] $tokens   The full token stream.
	 * @param int               $start    Inclusive start index.
	 * @param int               $consumed Number of tokens consumed by the parse.
	 * @param WP_Parser_Node    $ast      The parsed AST.
	 */
	public function store( array $tokens, int $start, int $consumed, WP_Parser_Node $ast ): void {
		$key = $this->compute_signature( $tokens, $start, $start + $consumed );
		$this->store_by_key( $key, $consumed, $ast );
	}

	/**
	 * Store a successful parse using a precomputed key.
	 *
	 * The AST is flattened into a post-order op stream so the hit path can
	 * rebuild it with a single linear loop and an explicit stack -- no
	 * recursion, no per-node method calls, no instanceof checks.
	 *
	 * @param string         $key      Cache key from compute_signature().
	 * @param int            $consumed Number of tokens consumed by the parse.
	 * @param WP_Parser_Node $ast      The parsed AST.
	 */
	public function store_by_key( string $key, int $consumed, WP_Parser_Node $ast ): void {
		// If the key already exists, dropping it first ensures the
		// re-insertion below puts it back at the most-recently-used end.
		if ( isset( $this->entries[ $key ] ) ) {
			unset( $this->entries[ $key ] );
		}

		$rule_ids     = array();
		$rule_names   = array();
		$child_counts = array();
		$this->flatten_ast_post_order( $ast, $rule_ids, $rule_names, $child_counts );
		$this->entries[ $key ] = array( $rule_ids, $rule_names, $child_counts, $consumed );

		if ( count( $this->entries ) > $this->capacity ) {
			// PHP 7.2-compatible "drop the oldest entry": foreach yields the
			// least-recently-used key first because PHP arrays preserve
			// insertion order.
			foreach ( $this->entries as $first_key => $_unused ) {
				unset( $this->entries[ $first_key ] );
				break;
			}
			++$this->evictions;
		}
	}

	/**
	 * Walk the AST in post-order, emitting one op per node and one op
	 * per token leaf into parallel flat arrays.
	 *
	 * Tokens are encoded as a sentinel rule id of 0 (which never matches
	 * a real grammar rule). Nodes carry their rule id, rule name, and the
	 * number of immediate children, so the replay loop can pop the right
	 * number of items from its stack.
	 *
	 * @param WP_Parser_Node|WP_Parser_Token $node
	 * @param int[]                          $rule_ids
	 * @param string[]                       $rule_names
	 * @param int[]                          $child_counts
	 */
	private function flatten_ast_post_order( $node, array &$rule_ids, array &$rule_names, array &$child_counts ): void {
		if ( $node instanceof WP_Parser_Token ) {
			$rule_ids[]     = 0;
			$rule_names[]   = '';
			$child_counts[] = 0;
			return;
		}
		$children = $node->get_children();
		$count    = count( $children );
		for ( $i = 0; $i < $count; ++$i ) {
			$this->flatten_ast_post_order( $children[ $i ], $rule_ids, $rule_names, $child_counts );
		}
		$rule_ids[]     = $node->rule_id;
		$rule_names[]   = $node->rule_name;
		$child_counts[] = $count;
	}

	/**
	 * Replay a flattened cache entry, binding token leaves to entries from
	 * the current token stream.
	 *
	 * Single linear loop, one explicit stack. No recursion, no
	 * `get_children()` method calls, no `instanceof` per child.
	 *
	 * @param  array<int>       $rule_ids
	 * @param  array<string>    $rule_names
	 * @param  array<int>       $child_counts
	 * @param  WP_Parser_Token[] $tokens  Current query's full token stream.
	 * @param  int              $start   Inclusive start index in $tokens.
	 * @return WP_Parser_Node
	 */
	private function rebuild_ast_from_template( array $rule_ids, array $rule_names, array $child_counts, array $tokens, int $start ): WP_Parser_Node {
		$op_count  = count( $rule_ids );
		$stack     = array();
		$top       = -1;
		$token_idx = 0;
		for ( $i = 0; $i < $op_count; ++$i ) {
			$rule_id = $rule_ids[ $i ];
			if ( 0 === $rule_id ) {
				$stack[ ++$top ] = $tokens[ $start + $token_idx ];
				++$token_idx;
				continue;
			}
			$count = $child_counts[ $i ];
			if ( 0 === $count ) {
				// Leaf node with no children. Should not happen because the
				// parser returns `true` for empty children, never a Node.
				$stack[ ++$top ] = new WP_Parser_Node( $rule_id, $rule_names[ $i ], array() );
				continue;
			}
			// One C-level call to gather the last $count children into a
			// fresh array, then drop them from the stack.
			$children        = array_slice( $stack, $top - $count + 1, $count );
			$top            -= $count;
			$stack[ ++$top ] = new WP_Parser_Node( $rule_id, $rule_names[ $i ], $children );
		}
		return $stack[0];
	}
}
