<?php
/**
 * Throughput benchmark: PCRE2 callouts via PHP FFI.
 *
 * Measures full-match QPS at increasing callout densities, reusing ONE match
 * context + closure + match-data across all iterations (per the memory caveat
 * about a per-registration leak in pcre2_set_callout). The closure appends
 * (callout_number, position) tuples to a PHP array on every callout, i.e. it
 * actually builds the trace a parser would consume — not a no-op.
 */

if ( ! extension_loaded( 'ffi' ) ) {
	fwrite( STDERR, "FFI extension not loaded\n" );
	exit( 1 );
}

$cdef = <<<'CDEF'
typedef unsigned char  PCRE2_UCHAR8;
typedef const PCRE2_UCHAR8 *PCRE2_SPTR8;
typedef size_t PCRE2_SIZE;

typedef struct pcre2_real_compile_context_8 pcre2_compile_context_8;
typedef struct pcre2_real_match_context_8   pcre2_match_context_8;
typedef struct pcre2_real_general_context_8 pcre2_general_context_8;
typedef struct pcre2_real_code_8            pcre2_code_8;
typedef struct pcre2_real_match_data_8      pcre2_match_data_8;

typedef struct pcre2_callout_block_8 {
    uint32_t      version;
    uint32_t      callout_number;
    uint32_t      capture_top;
    uint32_t      capture_last;
    PCRE2_SIZE   *offset_vector;
    PCRE2_SPTR8   mark;
    PCRE2_SPTR8   subject;
    PCRE2_SIZE    subject_length;
    PCRE2_SIZE    start_match;
    PCRE2_SIZE    current_position;
    PCRE2_SIZE    pattern_position;
    PCRE2_SIZE    next_item_length;
    PCRE2_SIZE    callout_string_offset;
    PCRE2_SIZE    callout_string_length;
    PCRE2_SPTR8   callout_string;
    uint32_t      callout_flags;
} pcre2_callout_block_8;

pcre2_code_8 *pcre2_compile_8(PCRE2_SPTR8 pattern, PCRE2_SIZE length,
    uint32_t options, int *errorcode, PCRE2_SIZE *erroroffset,
    pcre2_compile_context_8 *ccontext);
void pcre2_code_free_8(pcre2_code_8 *code);
int pcre2_jit_compile_8(pcre2_code_8 *code, uint32_t options);

pcre2_match_data_8 *pcre2_match_data_create_from_pattern_8(
    const pcre2_code_8 *code, pcre2_general_context_8 *gcontext);
void pcre2_match_data_free_8(pcre2_match_data_8 *match_data);

pcre2_match_context_8 *pcre2_match_context_create_8(pcre2_general_context_8 *gcontext);
void pcre2_match_context_free_8(pcre2_match_context_8 *mcontext);

int pcre2_set_callout_8(pcre2_match_context_8 *mcontext,
    int (*callout_function)(pcre2_callout_block_8 *, void *),
    void *callout_data);

int pcre2_match_8(const pcre2_code_8 *code, PCRE2_SPTR8 subject,
    PCRE2_SIZE length, PCRE2_SIZE startoffset, uint32_t options,
    pcre2_match_data_8 *match_data, pcre2_match_context_8 *mcontext);

void pcre2_get_error_message_8(int errorcode, PCRE2_UCHAR8 *buffer, PCRE2_SIZE bufflen);
CDEF;

$ffi = FFI::cdef( $cdef, '/opt/homebrew/lib/libpcre2-8.dylib' );

// Right-recursive arithmetic grammar; ~4 callouts per token (sum, product,
// atom + recursion). (?C1)=sum (?C2)=product (?C3)=number (?C4)=paren.
$pattern = '(?(DEFINE)'
	. '(?<sum>(?C1)(?&product)(?:\+(?&sum))?)'
	. '(?<product>(?C2)(?&atom)(?:\*(?&product))?)'
	. '(?<atom>(?C3)\d+|(?C4)\((?&sum)\))'
	. ')^(?&sum)$';

function compile_pattern( FFI $ffi, string $pattern ) {
	$err_code = $ffi->new( 'int' );
	$err_off  = $ffi->new( 'size_t' );
	$pat_arr  = $ffi->new( 'char[' . strlen( $pattern ) . ']', false );
	FFI::memcpy( $pat_arr, $pattern, strlen( $pattern ) );
	$code = $ffi->pcre2_compile_8(
		$ffi->cast( 'PCRE2_SPTR8', FFI::addr( $pat_arr ) ),
		strlen( $pattern ),
		0,
		FFI::addr( $err_code ),
		FFI::addr( $err_off ),
		null
	);
	FFI::free( FFI::addr( $pat_arr ) );
	if ( null === $code ) {
		$buf = $ffi->new( 'char[256]' );
		$ffi->pcre2_get_error_message_8( $err_code->cdata, $ffi->cast( 'PCRE2_UCHAR8 *', FFI::addr( $buf ) ), 256 );
		fwrite( STDERR, 'compile failed: ' . FFI::string( FFI::addr( $buf ) ) . "\n" );
		exit( 1 );
	}
	return $code;
}

// Build a subject of roughly N tokens: "1+1+...+1*1" style alternating ops.
function make_subject( int $tokens ) {
	$parts = array();
	for ( $i = 0; $i < $tokens; $i++ ) {
		$parts[] = (string) ( ( $i % 9 ) + 1 );
	}
	// Join with alternating + and * so the grammar exercises both rules.
	$out = $parts[0];
	for ( $i = 1; $i < count( $parts ); $i++ ) {
		$out .= ( $i % 2 ) ? '+' : '*';
		$out .= $parts[ $i ];
	}
	return $out;
}

$jit = getenv( 'USE_JIT_COMPILE' ) === '1';

$code = compile_pattern( $ffi, $pattern );
if ( $jit ) {
	$rc = $ffi->pcre2_jit_compile_8( $code, 1 ); // PCRE2_JIT_COMPLETE
	fwrite( STDERR, "pcre2_jit_compile rc=$rc\n" );
}

// Reused across ALL iterations.
$mctx  = $ffi->pcre2_match_context_create_8( null );
$mdata = $ffi->pcre2_match_data_create_from_pattern_8( $code, null );

$trace   = array();
$callout = function ( $blockptr, $data ) use ( &$trace ) {
	$blk     = $blockptr[0];
	$trace[] = array( $blk->callout_number, $blk->current_position );
	return 0;
};
$ffi->pcre2_set_callout_8( $mctx, $callout, null );

$sizes = array( 10, 50, 100 );
echo str_pad( 'tokens', 8 ) . str_pad( 'callouts', 10 ) . str_pad( 'QPS', 12 ) . "\n";

foreach ( $sizes as $tokens ) {
	$subject = make_subject( $tokens );
	$slen    = strlen( $subject );
	$subj    = $ffi->new( 'char[' . $slen . ']', false );
	FFI::memcpy( $subj, $subject, $slen );
	$subj_ptr = $ffi->cast( 'PCRE2_SPTR8', FFI::addr( $subj ) );

	// Warm + capture callout count.
	$trace = array();
	$rc    = $ffi->pcre2_match_8( $code, $subj_ptr, $slen, 0, 0, $mdata, $mctx );
	if ( $rc < 0 ) {
		fwrite( STDERR, "no match for tokens=$tokens (rc=$rc), subj=$subject\n" );
		FFI::free( FFI::addr( $subj ) );
		continue;
	}
	$callout_count = count( $trace );

	$best = 0.0;
	for ( $run = 0; $run < 7; $run++ ) {
		$iters = 2000;
		$t0    = hrtime( true );
		for ( $i = 0; $i < $iters; $i++ ) {
			$trace = array();
			$ffi->pcre2_match_8( $code, $subj_ptr, $slen, 0, 0, $mdata, $mctx );
		}
		$dt  = ( hrtime( true ) - $t0 ) / 1e9;
		$qps = $iters / $dt;
		if ( $qps > $best ) {
			$best = $qps;
		}
	}

	printf( "%-8d%-10d%-12s\n", $tokens, $callout_count, number_format( $best, 0 ) );
	FFI::free( FFI::addr( $subj ) );
}
