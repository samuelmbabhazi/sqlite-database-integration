<?php

require_once __DIR__ . '/../../src/parser/class-wp-parser-grammar.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the grammar build-time transforms that the parser hot path
 * depends on: epsilon stripping, single-branch fragment inlining, per-token
 * branch selectors (FIRST/NULLABLE sets), single-candidate classification,
 * and the sorted-merge helper.
 *
 * These transforms are exercised end-to-end by the parser corpus, but the
 * tests below lock their output shape directly so a regression surfaces here
 * rather than as an opaque parse failure.
 */
class WP_Parser_Grammar_Tests extends TestCase {

	/**
	 * Build a grammar from a compact definition.
	 *
	 * Terminals are ids below $rules_offset; non-terminals are
	 * $rules_offset + the rule's index. EMPTY_RULE_ID (0) is the epsilon marker.
	 *
	 * @param int      $rules_offset Lowest non-terminal id.
	 * @param string[] $names        Rule names by index (id = index + offset).
	 * @param array    $grammar      Branches by index; each branch is an int[].
	 */
	private function build_grammar( int $rules_offset, array $names, array $grammar ): WP_Parser_Grammar {
		$g = new WP_Parser_Grammar(
			array(
				'rules_offset' => $rules_offset,
				'rules_names'  => $names,
				'grammar'      => $grammar,
			)
		);
		// Selectors are denormalized lazily per rule; force a full build so the
		// assertions below can read the complete branches_for_token table.
		$g->build_all_selectors();
		return $g;
	}

	public function test_strip_epsilon_markers_and_nullable_fallback(): void {
		// opt ::= A ε | ε   (A = 1)
		$g = $this->build_grammar(
			10,
			array( 'opt' ),
			array(
				array( array( 1, 0 ), array( 0 ) ),
			)
		);

		// Epsilon markers are removed; the pure-epsilon branch becomes empty.
		$this->assertSame( array( array( 1 ), array() ), $g->rules[10] );

		// The rule is nullable (it has an empty branch).
		$this->assertArrayHasKey( 10, $g->nullable_branches );

		// Token A selects both branches: the A-led one and the nullable one.
		$this->assertSame( array( array( 1 ), array() ), $g->branches_for_token[10][1] );

		// Two candidate branches for token A, so it is not single-candidate.
		$this->assertArrayNotHasKey( 10, $g->single_candidate_rules );
	}

	public function test_inline_single_branch_fragment(): void {
		// r ::= %f C ;  %f ::= A B   (A=1, B=2, C=3)
		$g = $this->build_grammar(
			10,
			array( 'r', '%f' ),
			array(
				array( array( 11, 3 ) ),
				array( array( 1, 2 ) ),
			)
		);

		// The single-branch fragment is expanded in place.
		$this->assertSame( array( array( 1, 2, 3 ) ), $g->rules[10] );

		// The fragment rule itself is left intact.
		$this->assertSame( array( array( 1, 2 ) ), $g->rules[11] );

		// Only token A (the inlined first symbol) starts the rule.
		$this->assertSame( array( 1 ), array_keys( $g->branches_for_token[10] ) );
		$this->assertSame( array( array( 1, 2, 3 ) ), $g->branches_for_token[10][1] );
		$this->assertArrayHasKey( 10, $g->single_candidate_rules );
	}

	public function test_multi_candidate_rule_is_not_single_candidate(): void {
		// top ::= A B | A C   (both branches start with A)
		$g = $this->build_grammar(
			10,
			array( 'top', 'alt' ),
			array(
				array( array( 1, 2 ), array( 1, 3 ) ),
				array( array( 1 ) ),
			)
		);

		$this->assertSame( array( array( 1, 2 ), array( 1, 3 ) ), $g->branches_for_token[10][1] );
		$this->assertArrayNotHasKey( 10, $g->single_candidate_rules );

		// The single-branch rule is single-candidate.
		$this->assertArrayHasKey( 11, $g->single_candidate_rules );
	}

	public function test_first_set_propagates_through_non_terminal(): void {
		// top ::= child ;  child ::= A | B
		$g = $this->build_grammar(
			10,
			array( 'top', 'child' ),
			array(
				array( array( 11 ) ),
				array( array( 1 ), array( 2 ) ),
			)
		);

		// FIRST(child) = {A, B} flows up into top's selector.
		$this->assertSame( array( 1, 2 ), array_keys( $g->branches_for_token[10] ) );
		$this->assertSame( array( array( 11 ) ), $g->branches_for_token[10][1] );
		$this->assertSame( array( array( 11 ) ), $g->branches_for_token[10][2] );
		$this->assertArrayHasKey( 10, $g->single_candidate_rules );

		$this->assertSame( array( array( 1 ) ), $g->branches_for_token[11][1] );
		$this->assertSame( array( array( 2 ) ), $g->branches_for_token[11][2] );
	}

	public function test_inlining_terminates_on_cyclic_fragments(): void {
		// r ::= %a ;  %a ::= %b ;  %b ::= %a   (mutually recursive fragments)
		// The inliner must detect the cycle and leave a reference in place
		// instead of recursing forever.
		$g = $this->build_grammar(
			10,
			array( 'r', '%a', '%b' ),
			array(
				array( array( 11 ) ),
				array( array( 12 ) ),
				array( array( 11 ) ),
			)
		);

		$this->assertSame( array( array( 11 ) ), $g->rules[10] );
	}

	public function test_merge_sorted_dedupes_and_preserves_ascending_order(): void {
		$merge = new ReflectionMethod( WP_Parser_Grammar::class, 'merge_sorted' );
		// setAccessible() is required on PHP < 8.1 and deprecated (no-op) from 8.5.
		if ( PHP_VERSION_ID < 80100 ) {
			$merge->setAccessible( true );
		}

		$this->assertSame( array( 1, 2, 3 ), $merge->invoke( null, array( 1, 3 ), array( 2, 3 ) ) );
		$this->assertSame( array( 2 ), $merge->invoke( null, array(), array( 2 ) ) );
		$this->assertSame( array( 1, 2 ), $merge->invoke( null, array( 1, 2 ), array() ) );
		$this->assertSame( array( 0, 1 ), $merge->invoke( null, array( 0, 1 ), array( 1 ) ) );
	}

	public function test_lazy_selector_matches_full_build(): void {
		// child ::= A | B ;  top ::= child   (A=1, B=2)
		$g        = $this->build_grammar(
			10,
			array( 'top', 'child' ),
			array(
				array( array( 11 ) ),
				array( array( 1 ), array( 2 ) ),
			)
		);
		$expected = $g->branches_for_token[10];

		// A fresh grammar that never forces a full build must produce the same
		// selector for a rule the moment it is requested, and be idempotent.
		$lazy = new WP_Parser_Grammar(
			array(
				'rules_offset' => 10,
				'rules_names'  => array( 'top', 'child' ),
				'grammar'      => array( array( array( 11 ) ), array( array( 1 ), array( 2 ) ) ),
			)
		);
		$this->assertArrayNotHasKey( 10, $lazy->branches_for_token );
		$lazy->ensure_rule_selector( 10 );
		$lazy->ensure_rule_selector( 10 );
		$this->assertSame( $expected, $lazy->branches_for_token[10] );
	}

	public function test_real_mysql_grammar_invariants(): void {
		$g = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );
		$g->build_all_selectors();

		// Epsilon markers are fully stripped from every branch. The parser's
		// end-of-input sentinel relies on no real branch symbol being 0.
		foreach ( $g->rules as $rule_id => $branches ) {
			foreach ( $branches as $branch ) {
				$this->assertNotContains(
					WP_Parser_Grammar::EMPTY_RULE_ID,
					$branch,
					"Rule {$rule_id} still contains an epsilon marker."
				);
			}
		}

		// Every single-candidate rule has a selector, and each of its token
		// entries points to exactly one branch sequence (what the fast path
		// assumes when it reads $candidate_branches[0]).
		foreach ( array_keys( $g->single_candidate_rules ) as $rule_id ) {
			$this->assertArrayHasKey( $rule_id, $g->branches_for_token );
			foreach ( $g->branches_for_token[ $rule_id ] as $token_id => $sequences ) {
				$this->assertCount(
					1,
					$sequences,
					"Single-candidate rule {$rule_id} has multiple branches for token {$token_id}."
				);
			}
		}
	}
}
