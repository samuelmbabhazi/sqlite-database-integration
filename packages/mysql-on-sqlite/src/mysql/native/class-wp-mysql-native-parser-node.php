<?php

/**
 * Parser node backed by a native (Rust) AST.
 *
 * Instances of this class are constructed exclusively by the native MySQL
 * parser extension: when the extension parses a query, it produces a tree of
 * `WP_MySQL_Native_Parser_Node` objects whose `$native_ast` and
 * `$native_node_index` fields point into a Rust-owned AST buffer. Read methods
 * (`get_start`, `has_child`, `get_children`, ...) delegate to the extension so
 * children are never materialized into PHP arrays unless something actually
 * asks for them.
 *
 * The hedge in those methods (`if ( $this->has_unmaterialized_native_ast() )`)
 * is NOT a runtime check for whether the native extension is loaded — if this
 * class is in use, the extension is loaded by definition. It checks whether
 * THIS specific node still has an authoritative native AST behind it. A node
 * loses its native backing the first time it is mutated from PHP via
 * `append_child()` or `merge_fragment()`: those overrides call
 * `materialize_native_children()`, which copies the native children into the
 * inherited `$children` array and then drops the native AST reference. From
 * that point on, the node is a plain PHP-backed `WP_Parser_Node` and the read
 * methods fall through to the parent implementation.
 *
 * Mutation from PHP is real and intentional — query rewriters in
 * `WP_PDO_MySQL_On_SQLite` (e.g. building synthetic `count(*)` expressions)
 * call `append_child()` on parsed nodes. The lazy-then-materialize design
 * keeps the fast path (read-only traversal) cheap while still allowing
 * mutation when callers need it.
 */
class WP_MySQL_Native_Parser_Node extends WP_Parser_Node {
	private $native_ast        = null;
	private $native_node_index = null;

	public function __construct( $rule_id, $rule_name, $native_ast = null, $native_node_index = null ) {
		parent::__construct( $rule_id, $rule_name );

		$this->native_ast        = $native_ast;
		$this->native_node_index = $native_node_index;
	}

	/**
	 * Materializes any native children before mutating, then appends.
	 *
	 * Once a node is mutated, its native AST is no longer authoritative, so we
	 * copy the native children into PHP storage first and drop the native
	 * reference. Subsequent reads use the parent's PHP implementation.
	 */
	public function append_child( $node ) {
		$this->materialize_native_children();
		parent::append_child( $node );
	}

	/**
	 * Materializes any native children on both nodes before merging.
	 *
	 * @see self::append_child() for why materialization is required before
	 * mutation.
	 */
	public function merge_fragment( $node ) {
		$this->materialize_native_children();
		if ( $node instanceof self ) {
			$node->materialize_native_children();
		}
		parent::merge_fragment( $node );
	}

	/** @inheritDoc */
	public function has_child(): bool {
		$this->materialize_native_children();
		return parent::has_child();
	}

	/** @inheritDoc */
	public function has_child_node( ?string $rule_name = null ): bool {
		$this->materialize_native_children();
		return parent::has_child_node( $rule_name );
	}

	/** @inheritDoc */
	public function has_child_token( ?int $token_id = null ): bool {
		$this->materialize_native_children();
		return parent::has_child_token( $token_id );
	}

	/** @inheritDoc */
	public function get_first_child() {
		$this->materialize_native_children();
		return parent::get_first_child();
	}

	/** @inheritDoc */
	public function get_first_child_node( ?string $rule_name = null ): ?WP_Parser_Node {
		$this->materialize_native_children();
		return parent::get_first_child_node( $rule_name );
	}

	/** @inheritDoc */
	public function get_first_child_token( ?int $token_id = null ): ?WP_Parser_Token {
		$this->materialize_native_children();
		return parent::get_first_child_token( $token_id );
	}

	/** @inheritDoc */
	public function get_first_descendant_node( ?string $rule_name = null ): ?WP_Parser_Node {
		$this->materialize_native_children();
		return parent::get_first_descendant_node( $rule_name );
	}

	/** @inheritDoc */
	public function get_first_descendant_token( ?int $token_id = null ): ?WP_Parser_Token {
		$this->materialize_native_children();
		return parent::get_first_descendant_token( $token_id );
	}

	/** @inheritDoc */
	public function get_children(): array {
		$this->materialize_native_children();
		return parent::get_children();
	}

	/** @inheritDoc */
	public function get_child_nodes( ?string $rule_name = null ): array {
		$this->materialize_native_children();
		return parent::get_child_nodes( $rule_name );
	}

	/** @inheritDoc */
	public function get_child_tokens( ?int $token_id = null ): array {
		$this->materialize_native_children();
		return parent::get_child_tokens( $token_id );
	}

	/** @inheritDoc */
	public function get_descendants(): array {
		$this->materialize_native_children();
		return parent::get_descendants();
	}

	/** @inheritDoc */
	public function get_descendant_nodes( ?string $rule_name = null ): array {
		$this->materialize_native_children();
		return parent::get_descendant_nodes( $rule_name );
	}

	/** @inheritDoc */
	public function get_descendant_tokens( ?int $token_id = null ): array {
		$this->materialize_native_children();
		return parent::get_descendant_tokens( $token_id );
	}

	/** @inheritDoc */
	public function get_start(): int {
		$this->materialize_native_children();
		return parent::get_start();
	}

	/** @inheritDoc */
	public function get_length(): int {
		$this->materialize_native_children();
		return parent::get_length();
	}

	/**
	 * Indicates whether this node still has an unmaterialized native AST.
	 *
	 * Returns true for freshly-parsed nodes whose children live in the
	 * Rust-owned AST buffer; returns false once the node has been mutated and
	 * its children copied into the inherited `$children` array (see
	 * self::materialize_native_children()).
	 *
	 * This is a per-instance state check, not a check for whether the native
	 * extension is loaded.
	 */
	private function has_unmaterialized_native_ast(): bool {
		return null !== $this->native_ast;
	}

	/**
	 * Copies native children into the inherited PHP $children array and drops
	 * the native AST reference for this node.
	 *
	 * Called before any mutation (append_child, merge_fragment) so the node's
	 * authoritative state lives in PHP from that point on. After this runs,
	 * has_unmaterialized_native_ast() returns false and read methods fall
	 * through to the parent WP_Parser_Node implementation.
	 */
	private function materialize_native_children(): void {
		if ( ! $this->has_unmaterialized_native_ast() ) {
			return;
		}

		$this->children          = wp_sqlite_mysql_native_ast_get_children( $this->native_ast, $this->native_node_index );
		$this->native_ast        = null;
		$this->native_node_index = null;
	}
}
