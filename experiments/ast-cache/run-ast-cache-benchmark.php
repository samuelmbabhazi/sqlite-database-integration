<?php

/**
 * AST cache benchmark.
 *
 * Measures parser+cache performance across five scenarios and prints a
 * summary table. ABAB-alternates cache-off vs cache-on across iterations
 * so JIT warmup, thermal noise, and process state cancel out.
 *
 * Usage:
 *
 *   php run-ast-cache-benchmark.php
 *       [--scenarios=miss,hit,corpus,wp,cap_sweep]
 *       [--rounds=3]
 *       [--iters=5]
 *       [--warmup=2]
 *       [--sleep=30]              # seconds between rounds for thermal recovery
 *       [--corpus-limit=69577]    # cap on corpus queries (full file is ~114k)
 *       [--csv=path]              # write per-iter rows to CSV
 *
 * Environment:
 *   The script does NOT spawn child processes. Run it once per PHP version
 *   to compare 8.1 vs 8.5.
 */

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

require_once __DIR__ . '/../../src/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-node.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-token.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-lexer.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-parser.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-parser-ast-cache.php';

// --- CLI parsing ---
$opts = getopt(
	'',
	array(
		'scenarios::',
		'rounds::',
		'iters::',
		'warmup::',
		'sleep::',
		'corpus-limit::',
		'csv::',
	)
);

$scenarios     = isset( $opts['scenarios'] ) ? explode( ',', $opts['scenarios'] ) : array( 'miss', 'hit', 'corpus', 'wp', 'cap_sweep' );
$rounds        = (int) ( $opts['rounds'] ?? 3 );
$iters         = (int) ( $opts['iters'] ?? 5 );
$warmup        = (int) ( $opts['warmup'] ?? 2 );
$sleep_between = (int) ( $opts['sleep'] ?? 30 );
$corpus_limit  = (int) ( $opts['corpus-limit'] ?? 69577 );
$csv_path      = $opts['csv'] ?? null;

$rows = array();

// Header line for the per-iter CSV.
if ( null !== $csv_path ) {
	file_put_contents(
		$csv_path,
		"scenario,php,cache,cap,round,iter,qps,ms,hit_rate\n"
	);
}

// --- Grammar load ---
$grammar         = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );
$grammar_version = (string) md5_file( __DIR__ . '/../../src/mysql/mysql-grammar.php' );
$php_version     = PHP_VERSION;
$jit_status      = function_exists( 'opcache_get_status' )
	? ( opcache_get_status( false )['jit']['on'] ?? false )
	: false;

fwrite( STDERR, "PHP $php_version  JIT=" . ( $jit_status ? 'on' : 'off' ) . "\n" );

// --- Workloads ---
function load_corpus_queries( int $limit ): array {
	$path   = __DIR__ . '/../mysql/data/mysql-server-tests-queries.csv';
	$handle = fopen( $path, 'r' );
	$out    = array();
	fgetcsv( $handle, null, ',', '"', '\\' ); // header
	$count = 0;
	while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
		$query = $record[0] ?? null;
		if ( null === $query || '' === $query ) {
			continue;
		}
		$out[] = $query;
		++$count;
		if ( $count >= $limit ) {
			break;
		}
	}
	fclose( $handle );
	return $out;
}

function build_unique_miss_queries( int $count ): array {
	// Each query has a different identifier so signatures differ; cache
	// will only ever miss. Tests pure overhead of the cache check on the
	// hot path.
	$out = array();
	for ( $i = 0; $i < $count; ++$i ) {
		$out[] = 'SELECT * FROM table_' . $i . ' WHERE col_' . $i . ' = 1';
	}
	return $out;
}

function build_single_hit_query(): array {
	// One query repeated -- cache always hits after the first miss.
	return array( 'SELECT * FROM users WHERE id = 1' );
}

function build_wp_workload( int $repeats ): array {
	// Mirror the shape of a WordPress page load: heavy wp_options reads
	// with varying option_name, post lookups by ID, postmeta by post_id +
	// meta_key, plus a sprinkle of writes.
	$option_names = array(
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'admin_email',
		'start_of_week',
		'use_balanceTags',
		'use_smilies',
		'require_name_email',
		'comments_notify',
		'posts_per_rss',
		'rss_use_excerpt',
		'mailserver_url',
		'mailserver_login',
		'default_category',
		'default_comment_status',
		'default_ping_status',
		'default_pingback_flag',
		'permalink_structure',
		'gmt_offset',
		'default_email_category',
		'recently_edited',
		'template',
		'stylesheet',
		'comment_registration',
		'html_type',
		'use_trackback',
		'default_role',
		'db_version',
		'uploads_use_yearmonth_folders',
		'upload_path',
		'blog_public',
		'default_link_category',
		'show_on_front',
		'tag_base',
		'show_avatars',
		'avatar_rating',
		'upload_url_path',
		'thumbnail_size_w',
		'thumbnail_size_h',
	);
	$meta_keys    = array(
		'_edit_last',
		'_edit_lock',
		'_thumbnail_id',
		'_wp_page_template',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_menu_item_type',
		'_menu_item_object',
		'_menu_item_object_id',
		'_wp_old_slug',
	);

	$templates = array();
	foreach ( $option_names as $name ) {
		$templates[] = "SELECT option_value FROM wp_options WHERE option_name = '$name' LIMIT 1";
	}
	for ( $id = 1; $id <= 25; ++$id ) {
		$templates[] = "SELECT * FROM wp_posts WHERE ID = $id";
	}
	foreach ( $meta_keys as $mk ) {
		for ( $pid = 1; $pid <= 5; ++$pid ) {
			$templates[] = "SELECT meta_value FROM wp_postmeta WHERE post_id = $pid AND meta_key = '$mk' LIMIT 1";
		}
	}
	for ( $id = 1; $id <= 10; ++$id ) {
		$templates[] = "SELECT * FROM wp_users WHERE ID = $id";
		$templates[] = "SELECT * FROM wp_terms WHERE term_id = $id";
		$templates[] = "SELECT * FROM wp_term_taxonomy WHERE term_taxonomy_id = $id";
		$templates[] = "SELECT * FROM wp_comments WHERE comment_post_ID = $id AND comment_approved = '1'";
	}
	$templates[] = "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('cron', 'a:0:{}', 'yes')";
	$templates[] = "UPDATE wp_options SET option_value = 'a:1:{}' WHERE option_name = 'cron'";
	$templates[] = "DELETE FROM wp_options WHERE option_name = 'cron'";
	$templates[] = "SELECT COUNT(*) FROM wp_posts WHERE post_status = 'publish' AND post_type = 'post'";
	$templates[] = "SELECT * FROM wp_posts WHERE post_status = 'publish' ORDER BY post_date DESC LIMIT 10";

	// Repeat the templates several times to amortise warmup; shuffle once
	// so cached/miss patterns interleave.
	$out = array();
	for ( $r = 0; $r < $repeats; ++$r ) {
		foreach ( $templates as $t ) {
			$out[] = $t;
		}
	}
	mt_srand( 42 );
	shuffle( $out );
	return $out;
}

// --- Bench primitive ---
function pretokenize( array $queries ): array {
	$tokenized = array();
	foreach ( $queries as $q ) {
		$lexer       = new WP_MySQL_Lexer( $q );
		$tokens      = $lexer->remaining_tokens();
		$tokenized[] = $tokens;
	}
	return $tokenized;
}

/**
 * Run one timed pass over the pre-tokenized workload.
 *
 * Returns [qps, elapsed_seconds, success_count, hit_rate].
 */
function bench_pass( array $tokenized, WP_Parser_Grammar $grammar, ?WP_MySQL_Parser_Ast_Cache $cache ): array {
	$success       = 0;
	$start         = microtime( true );
	$hits_before   = $cache ? $cache->get_stats()['hits'] : 0;
	$misses_before = $cache ? $cache->get_stats()['misses'] : 0;
	foreach ( $tokenized as $tokens ) {
		$parser = new WP_MySQL_Parser( $grammar, $tokens, $cache );
		$parser->next_query();
		if ( null !== $parser->get_query_ast() ) {
			++$success;
		}
	}
	$elapsed = microtime( true ) - $start;
	$qps     = $elapsed > 0 ? count( $tokenized ) / $elapsed : 0.0;

	$hit_rate = 0.0;
	if ( $cache ) {
		$delta_hits   = $cache->get_stats()['hits'] - $hits_before;
		$delta_misses = $cache->get_stats()['misses'] - $misses_before;
		$total        = $delta_hits + $delta_misses;
		$hit_rate     = $total > 0 ? $delta_hits / $total : 0.0;
	}
	return array( $qps, $elapsed, $success, $hit_rate );
}

/**
 * Run one scenario in ABAB order.
 *
 * Returns array with keys 'off' and 'on', each holding raw QPS samples
 * collected across all rounds.
 */
function run_abab(
	array $tokenized,
	WP_Parser_Grammar $grammar,
	int $cap,
	int $rounds,
	int $iters,
	int $warmup,
	int $sleep_between,
	string $scenario,
	string $php_version,
	$csv_path,
	$grammar_version
): array {
	$samples_off      = array();
	$samples_on       = array();
	$last_hit_rate_on = 0.0;

	for ( $r = 1; $r <= $rounds; ++$r ) {
		// --- A pass: cache OFF ---
		fwrite( STDERR, "[$scenario] round $r A (cache=off)" );
		for ( $w = 0; $w < $warmup; ++$w ) {
			bench_pass( $tokenized, $grammar, null );
		}
		for ( $i = 0; $i < $iters; ++$i ) {
			list( $qps, $ms ) = bench_pass( $tokenized, $grammar, null );
			$samples_off[]    = $qps;
			fwrite( STDERR, sprintf( ' %.0f', $qps ) );
			if ( null !== $csv_path ) {
				file_put_contents(
					$csv_path,
					"$scenario,$php_version,off,0,$r," . ( $i + 1 ) . ",$qps," . ( $ms * 1000 ) . ",0\n",
					FILE_APPEND
				);
			}
		}
		fwrite( STDERR, "\n" );

		if ( $sleep_between > 0 ) {
			fwrite( STDERR, "  sleeping $sleep_between s ...\n" );
			sleep( $sleep_between );
		}

		// --- B pass: cache ON ---
		// Fresh cache per round so memory and warm-up state are
		// comparable across rounds.
		$cache = new WP_MySQL_Parser_Ast_Cache( $grammar_version, $cap );
		fwrite( STDERR, "[$scenario] round $r B (cache=on,cap=$cap)" );
		for ( $w = 0; $w < $warmup; ++$w ) {
			bench_pass( $tokenized, $grammar, $cache );
		}
		for ( $i = 0; $i < $iters; ++$i ) {
			list( $qps, $ms, $success, $hit_rate ) = bench_pass( $tokenized, $grammar, $cache );
			$samples_on[]                          = $qps;
			$last_hit_rate_on                      = $hit_rate;
			fwrite( STDERR, sprintf( ' %.0f(hr=%.2f)', $qps, $hit_rate ) );
			if ( null !== $csv_path ) {
				file_put_contents(
					$csv_path,
					"$scenario,$php_version,on,$cap,$r," . ( $i + 1 ) . ",$qps," . ( $ms * 1000 ) . ",$hit_rate\n",
					FILE_APPEND
				);
			}
		}
		fwrite( STDERR, "\n" );

		if ( $r < $rounds && $sleep_between > 0 ) {
			fwrite( STDERR, "  sleeping $sleep_between s ...\n" );
			sleep( $sleep_between );
		}
	}

	return array(
		'off'      => $samples_off,
		'on'       => $samples_on,
		'hit_rate' => $last_hit_rate_on,
	);
}

function summarize( array $samples ): array {
	if ( ! $samples ) {
		return array(
			'best'   => 0.0,
			'median' => 0.0,
			'mean'   => 0.0,
		);
	}
	sort( $samples );
	$n      = count( $samples );
	$best   = $samples[ $n - 1 ];
	$median = $n % 2
		? $samples[ (int) ( $n / 2 ) ]
		: ( $samples[ $n / 2 - 1 ] + $samples[ $n / 2 ] ) / 2.0;
	$mean   = array_sum( $samples ) / $n;
	return compact( 'best', 'median', 'mean' );
}

function format_row( string $label, array $off_summary, array $on_summary, float $hit_rate ): string {
	$speedup = $off_summary['median'] > 0 ? $on_summary['median'] / $off_summary['median'] : 0.0;
	return sprintf(
		"%-22s | off med %7.0f QPS  best %7.0f | on med %7.0f QPS  best %7.0f | hr %5.2f | speedup %5.2fx\n",
		$label,
		$off_summary['median'],
		$off_summary['best'],
		$on_summary['median'],
		$on_summary['best'],
		$hit_rate,
		$speedup
	);
}

// --- Memory measurement helper ---
function measure_cache_memory( WP_Parser_Grammar $grammar, string $grammar_version, array $tokenized, int $cap ): array {
	gc_collect_cycles();
	$baseline = memory_get_usage();
	$cache    = new WP_MySQL_Parser_Ast_Cache( $grammar_version, $cap );
	// Drive enough distinct queries through the cache to fill it.
	$filled = 0;
	foreach ( $tokenized as $tokens ) {
		$parser = new WP_MySQL_Parser( $grammar, $tokens, $cache );
		$parser->next_query();
		++$filled;
		if ( $cache->get_stats()['entries'] >= $cap ) {
			break;
		}
	}
	gc_collect_cycles();
	$after = memory_get_usage();
	$peak  = memory_get_peak_usage();
	return array(
		'entries'        => $cache->get_stats()['entries'],
		'driven_queries' => $filled,
		'cold_bytes'     => $baseline,
		'full_bytes'     => $after,
		'delta_bytes'    => $after - $baseline,
		'peak_bytes'     => $peak,
	);
}

// --- Run scenarios ---
$results = array();

if ( in_array( 'miss', $scenarios, true ) ) {
	$queries         = build_unique_miss_queries( 2000 );
	$tokenized       = pretokenize( $queries );
	$results['miss'] = run_abab( $tokenized, $grammar, 200, $rounds, $iters, $warmup, $sleep_between, 'miss', $php_version, $csv_path, $grammar_version );
}

if ( in_array( 'hit', $scenarios, true ) ) {
	$queries = build_single_hit_query();
	// Replicate to keep timing per-iter measurable.
	$queries        = array_merge( ...array_fill( 0, 50000, $queries ) );
	$tokenized      = pretokenize( $queries );
	$results['hit'] = run_abab( $tokenized, $grammar, 200, $rounds, $iters, $warmup, $sleep_between, 'hit', $php_version, $csv_path, $grammar_version );
}

if ( in_array( 'corpus', $scenarios, true ) ) {
	$queries           = load_corpus_queries( $corpus_limit );
	$tokenized         = pretokenize( $queries );
	$results['corpus'] = run_abab( $tokenized, $grammar, 200, $rounds, $iters, $warmup, $sleep_between, 'corpus', $php_version, $csv_path, $grammar_version );
}

if ( in_array( 'wp', $scenarios, true ) ) {
	$queries       = build_wp_workload( 100 );
	$tokenized     = pretokenize( $queries );
	$results['wp'] = run_abab( $tokenized, $grammar, 200, $rounds, $iters, $warmup, $sleep_between, 'wp', $php_version, $csv_path, $grammar_version );
}

if ( in_array( 'cap_sweep', $scenarios, true ) ) {
	// Cap-sweep needs enough *distinct* signatures to make caps actually
	// bite. We synthesise ~600 distinct shapes (templates) and replay
	// them in a long Zipf-ish stream so the LRU policy is exercised.
	$cap_distinct = 600;
	$cap_replays  = 30;
	$templates    = array();
	for ( $i = 0; $i < $cap_distinct; ++$i ) {
		$op = $i % 4;
		if ( 0 === $op ) {
			$templates[] = "SELECT col_$i FROM table_$i WHERE id = 1";
		} elseif ( 1 === $op ) {
			$templates[] = "INSERT INTO table_$i (a, b) VALUES (1, 'x')";
		} elseif ( 2 === $op ) {
			$templates[] = "UPDATE table_$i SET col_$i = 1 WHERE id = 1";
		} else {
			$templates[] = "SELECT col_$i, other_$i FROM table_$i t JOIN other_$i o ON t.id = o.id WHERE t.col_$i > 0";
		}
	}
	$queries = array();
	for ( $r = 0; $r < $cap_replays; ++$r ) {
		foreach ( $templates as $t ) {
			$queries[] = $t;
		}
	}
	mt_srand( 13 );
	shuffle( $queries );
	$tokenized = pretokenize( $queries );
	foreach ( array( 50, 100, 200, 500 ) as $cap ) {
		$results[ "cap=$cap" ] = run_abab( $tokenized, $grammar, $cap, $rounds, $iters, $warmup, $sleep_between, "cap=$cap", $php_version, $csv_path, $grammar_version );
	}
}

// --- Memory: separate (no ABAB needed). Use synthetic distinct shapes
// so we can actually fill the cache to cap. ---
$memory_summary = null;
if ( in_array( 'wp', $scenarios, true ) || in_array( 'cap_sweep', $scenarios, true ) || in_array( 'memory', $scenarios, true ) ) {
	$mem_queries = array();
	for ( $i = 0; $i < 250; ++$i ) {
		$op = $i % 4;
		if ( 0 === $op ) {
			$mem_queries[] = "SELECT col_$i FROM table_$i WHERE id = 1";
		} elseif ( 1 === $op ) {
			$mem_queries[] = "INSERT INTO table_$i (a, b) VALUES (1, 'x')";
		} elseif ( 2 === $op ) {
			$mem_queries[] = "UPDATE table_$i SET col_$i = 1 WHERE id = 1";
		} else {
			$mem_queries[] = "SELECT col_$i, other_$i FROM table_$i t JOIN other_$i o ON t.id = o.id WHERE t.col_$i > 0";
		}
	}
	$tokenized      = pretokenize( $mem_queries );
	$memory_summary = measure_cache_memory( $grammar, $grammar_version, $tokenized, 200 );
}

// --- Output table ---
echo "\n";
echo str_repeat( '=', 100 ) . "\n";
echo "AST cache benchmark  |  PHP $php_version  |  rounds=$rounds  iters=$iters  warmup=$warmup  sleep={$sleep_between}s\n";
echo str_repeat( '=', 100 ) . "\n";

foreach ( $results as $name => $res ) {
	$off_summary = summarize( $res['off'] );
	$on_summary  = summarize( $res['on'] );
	echo format_row( $name, $off_summary, $on_summary, $res['hit_rate'] );
}

if ( null !== $memory_summary ) {
	echo "\n";
	echo "Memory at cap=200 (WP workload):\n";
	printf(
		"  entries filled = %d (after %d queries)\n  delta = %.2f MB\n  peak  = %.2f MB\n",
		$memory_summary['entries'],
		$memory_summary['driven_queries'],
		$memory_summary['delta_bytes'] / ( 1024 * 1024 ),
		$memory_summary['peak_bytes'] / ( 1024 * 1024 )
	);
}
