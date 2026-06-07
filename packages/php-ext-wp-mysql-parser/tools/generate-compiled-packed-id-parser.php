<?php

$root = dirname( __DIR__, 3 );
require_once $root . '/packages/mysql-on-sqlite/src/load.php';
require_once $root . '/packages/mysql-on-sqlite/src/mysql/native/mysql-rust-bridge.php';

$grammar = new WP_Parser_Grammar( require $root . '/packages/mysql-on-sqlite/src/mysql/mysql-grammar.php' );
$export  = wp_sqlite_mysql_native_export_grammar( $grammar );
$target  = __DIR__ . '/../src/compiled_packed_id_parser.rs';

$rules       = $export['rules'];
$first_sets  = $export['first_sets'];
$nullable    = array_fill_keys( array_map( 'intval', array_keys( $export['nullable_branches'] ) ), true );
$fragments   = array_fill_keys( array_map( 'intval', array_keys( $export['fragment_ids'] ) ), true );
$rule_names  = $export['rule_names'];
$query_id    = array_search( 'query', $rule_names, true );
$select_id   = array_search( 'selectStatement', $rule_names, true );
$highest_tid = (int) $export['highest_terminal_id'];
ksort( $rules, SORT_NUMERIC );

function rust_chunks( array $values, int $size ): array {
	return array_chunk( $values, $size );
}

function rust_matches_expr( array $values ): array {
	$values = array_values( array_map( 'intval', $values ) );
	sort( $values, SORT_NUMERIC );
	if ( 0 === count( $values ) ) {
		return array( 'false' );
	}

	$parts = array();
	foreach ( rust_chunks( $values, 18 ) as $chunk ) {
		$parts[] = implode(
			' | ',
			array_map(
				function ( int $value ): string {
					return $value . 'i64';
				},
				$chunk
			)
		);
	}

	if ( 1 === count( $parts ) ) {
		return array( 'matches!(self.current_token_id(), ' . $parts[0] . ')' );
	}

	$lines   = array( 'matches!(', '            self.current_token_id(),' );
	$last_id = count( $parts ) - 1;
	foreach ( $parts as $index => $part ) {
		$lines[] = '            ' . $part . ( $index < $last_id ? ' |' : '' );
	}
	$lines[] = '        )';
	return $lines;
}

$out = array(
	'// This file is generated from packages/mysql-on-sqlite/src/mysql/mysql-grammar.php.',
	'// Regenerate with packages/php-ext-wp-mysql-parser/tools/generate-compiled-packed-id-parser.php.',
	'#![allow(unused_mut, unused_variables)]',
	'',
	'use super::lex;',
	'use super::native_ast_pack_kind_id;',
	'',
	'pub const HIGHEST_TERMINAL_ID: i64 = ' . $highest_tid . 'i64;',
	'pub const QUERY_RULE_ID: i64 = ' . $query_id . 'i64;',
	'pub const SELECT_STATEMENT_RULE_ID: i64 = ' . $select_id . 'i64;',
	'',
	'#[derive(Clone, Copy, PartialEq, Eq)]',
	'enum CompiledMatch {',
	'    No,',
	'    Empty,',
	'    Match,',
	'}',
	'',
	"pub struct CompiledPackedIdStatsParser<'a> {",
	"    token_ids: &'a [i64],",
	'    position: usize,',
	'    descendants: i64,',
	'    checksum: i64,',
	'}',
	'',
	"impl<'a> CompiledPackedIdStatsParser<'a> {",
	"    pub fn new(token_ids: &'a [i64]) -> Self {",
	'        Self {',
	'            token_ids,',
	'            position: 0,',
	'            descendants: 0,',
	'            checksum: 0,',
	'        }',
	'    }',
	'',
	'    pub fn parse(mut self) -> Option<(i64, i64)> {',
	'        match self.parse_rule(QUERY_RULE_ID, true) {',
	'            CompiledMatch::No => None,',
	'            CompiledMatch::Empty | CompiledMatch::Match => Some((self.descendants, self.checksum)),',
	'        }',
	'    }',
	'',
	'    fn current_token_id(&self) -> i64 {',
	'        self.token_ids.get(self.position).copied().unwrap_or(0)',
	'    }',
	'',
	'    fn state(&self) -> (usize, i64, i64) {',
	'        (self.position, self.descendants, self.checksum)',
	'    }',
	'',
	'    fn restore(&mut self, state: (usize, i64, i64)) {',
	'        self.position = state.0;',
	'        self.descendants = state.1;',
	'        self.checksum = state.2;',
	'    }',
	'',
	'    fn push_node(&mut self, rule_id: i64) {',
	'        self.descendants += 1;',
	'        self.checksum += native_ast_pack_kind_id(0, rule_id);',
	'    }',
	'',
	'    fn push_token(&mut self, token_id: i64) {',
	'        self.descendants += 1;',
	'        self.checksum += native_ast_pack_kind_id(1, token_id);',
	'    }',
	'',
	'    fn parse_symbol(&mut self, symbol_id: i64) -> CompiledMatch {',
	'        if symbol_id <= HIGHEST_TERMINAL_ID {',
	'            if self.position >= self.token_ids.len() {',
	'                return CompiledMatch::No;',
	'            }',
	'            if symbol_id == 0 {',
	'                return CompiledMatch::Empty;',
	'            }',
	'            if self.token_ids[self.position] == symbol_id {',
	'                self.position += 1;',
	'                self.push_token(symbol_id);',
	'                return CompiledMatch::Match;',
	'            }',
	'            return CompiledMatch::No;',
	'        }',
	'        self.parse_rule(symbol_id, false)',
	'    }',
	'',
	'    fn parse_child(&mut self, symbol_id: i64, branch_matches: &mut bool, has_children: &mut bool) {',
	'        if !*branch_matches {',
	'            return;',
	'        }',
	'        let child_starting_descendants = self.descendants;',
	'        match self.parse_symbol(symbol_id) {',
	'            CompiledMatch::No => *branch_matches = false,',
	'            CompiledMatch::Empty => {}',
	'            CompiledMatch::Match => {',
	'                if self.descendants != child_starting_descendants {',
	'                    *has_children = true;',
	'                }',
	'            }',
	'        }',
	'    }',
	'',
	'    fn parse_rule(&mut self, rule_id: i64, skip_current_node: bool) -> CompiledMatch {',
	'        match rule_id {',
);

foreach ( array_keys( $rules ) as $rule_id ) {
	$out[] = '            ' . (int) $rule_id . 'i64 => self.parse_rule_' . (int) $rule_id . '(skip_current_node),';
}
$out[] = '            _ => CompiledMatch::No,';
$out[] = '        }';
$out[] = '    }';

foreach ( $rules as $rule_id => $branches ) {
	$rule_id     = (int) $rule_id;
	$rule_name   = $rule_names[ $rule_id ] ?? '';
	$is_nullable = isset( $nullable[ $rule_id ] );
	$is_fragment = isset( $fragments[ $rule_id ] );

	$out[] = '';
	$out[] = '    // ' . $rule_name;
	$out[] = '    fn parse_rule_' . $rule_id . '(&mut self, skip_current_node: bool) -> CompiledMatch {';

	if ( 0 === count( $branches ) ) {
		$out[] = '        CompiledMatch::No';
		$out[] = '    }';
		continue;
	}

	if ( $is_nullable ) {
		$out[] = '        // Nullable rules may still match without consuming input.';
	} else {
		$first_set = array_keys( $first_sets[ $rule_id ] ?? array() );
		$expr      = rust_matches_expr( $first_set );
		if ( 1 === count( $expr ) ) {
			$out[] = '        if !' . $expr[0] . ' {';
		} else {
			$out[] = '        if !(';
			foreach ( $expr as $line ) {
				$out[] = $line;
			}
			$out[] = '        ) {';
		}
		$out[] = '            return CompiledMatch::No;';
		$out[] = '        }';
	}

	$out[] = '';
	$out[] = '        let starting_state = self.state();';
	foreach ( $branches as $branch_index => $branch ) {
		$out[] = '        // branch ' . $branch_index;
		$out[] = '        self.restore(starting_state);';
		$out[] = '        let mut branch_matches = true;';
		$out[] = '        let mut has_children = false;';
		if ( ! $is_fragment ) {
			$out[] = '        if !skip_current_node {';
			$out[] = '            self.push_node(' . $rule_id . 'i64);';
			$out[] = '        }';
		}
		foreach ( $branch as $symbol ) {
			$out[] = '        self.parse_child(' . (int) $symbol . 'i64, &mut branch_matches, &mut has_children);';
		}
		if ( $rule_id === (int) $select_id ) {
			$out[] = '        if branch_matches && self.current_token_id() == lex::INTO_SYMBOL {';
			$out[] = '            branch_matches = false;';
			$out[] = '        }';
		}
		$out[] = '        if branch_matches {';
		$out[] = '            if has_children {';
		$out[] = '                return CompiledMatch::Match;';
		$out[] = '            }';
		$out[] = '            self.restore(starting_state);';
		$out[] = '            return CompiledMatch::Empty;';
		$out[] = '        }';
		$out[] = '';
	}
	$out[] = '        self.restore(starting_state);';
	$out[] = '        CompiledMatch::No';
	$out[] = '    }';
}

$out[] = '}';

file_put_contents( $target, implode( "\n", $out ) . "\n" );
printf( "Wrote %s\n", $target );
