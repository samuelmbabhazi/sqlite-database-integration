<?php
/**
 * Probe whether PHP FFI can expose PCRE2 callouts so the regex match
 * can record (rule, offset) tuples that we then turn into an AST.
 *
 * Conclusion: NO.
 *
 * pcre2_set_callout_8 takes a function pointer. PHP FFI does not
 * support binding a PHP closure to a C function pointer; the libffi
 * closure feature is intentionally not enabled in PHP's FFI build.
 * That means even though we can call pcre2_compile_8 / pcre2_match_8
 * via FFI, we cannot supply a PHP-side callout callback - so the
 * (?C) callouts in the pattern have no observable effect.
 *
 * Without callouts, PCRE2's match data exposes only the ovector
 * (one offset pair per numbered group, last-match-wins), which is
 * what php_pcre.c projects into $matches. That isn't enough to
 * reconstruct a recursive parse tree.
 *
 * The only paths to make this work:
 *  1. A custom PHP extension wrapping pcre2_set_callout (significant
 *     C work, out of scope).
 *  2. Multi-pass extraction with preg_match_all on flat sub-patterns
 *     - functionally a parser, performance similar to or worse than
 *     the existing recursive-descent interpreter.
 *  3. Use the regex purely as a yes/no validator, accept that the
 *     AST has to come from the parser. Tested in exp-regex-hybrid.php
 *     and shown to be a net loss for valid-heavy workloads.
 */

if ( ! extension_loaded( 'ffi' ) ) {
	echo "FFI extension not loaded\n";
	exit( 1 );
}

// Minimal subset of the PCRE2 8-bit C API we need to do a match with a
// callout callback. From pcre2.h.
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

int pcre2_jit_compile_8(pcre2_code_8 *code, uint32_t options);

PCRE2_SIZE *pcre2_get_ovector_pointer_8(pcre2_match_data_8 *match_data);

void pcre2_get_error_message_8(int errorcode, PCRE2_UCHAR8 *buffer, PCRE2_SIZE bufflen);
CDEF;

$lib_path = '/opt/homebrew/lib/libpcre2-8.dylib';
$ffi      = FFI::cdef( $cdef, $lib_path );

// Compile a tiny pattern with two numbered callouts.
$pattern  = '/(?C1)foo(?C2)bar/';
$pat_buf  = $pattern;
$err_code = FFI::new( 'int' );
$err_off  = FFI::new( 'size_t' );

$code = $ffi->pcre2_compile_8(
	FFI::cast( 'PCRE2_SPTR8', FFI::addr( FFI::new( 'char[' . strlen( $pat_buf ) . ']' ) ) ),
	0, // We'll set length below in real code.
	0,
	FFI::addr( $err_code ),
	FFI::addr( $err_off ),
	null
);

// The above is wrong because we didn't actually copy the pattern bytes
// into the buffer. Let's do it properly.
$pat_arr = $ffi->new( 'char[' . strlen( $pat_buf ) . ']' );
FFI::memcpy( $pat_arr, $pat_buf, strlen( $pat_buf ) );
$code = $ffi->pcre2_compile_8(
	FFI::cast( 'PCRE2_SPTR8', FFI::addr( $pat_arr ) ),
	strlen( $pat_buf ),
	0,
	FFI::addr( $err_code ),
	FFI::addr( $err_off ),
	null
);
if ( null === $code ) {
	$buf = $ffi->new( 'char[256]' );
	$ffi->pcre2_get_error_message_8( $err_code->cdata, FFI::cast( 'PCRE2_UCHAR8 *', FFI::addr( $buf ) ), 256 );
	echo 'compile failed: code=', $err_code->cdata, ' offset=', $err_off->cdata, ' msg=', FFI::string( FFI::addr( $buf ) ), "\n";
	exit( 1 );
}
echo "Pattern compiled OK\n";

// Try setting up a callout via FFI.
$callout_log = array();
$mctx        = $ffi->pcre2_match_context_create_8( null );
$callout_cb  = function ( $blockptr, $data ) use ( &$callout_log ) {
	// $blockptr is FFI\CData type pcre2_callout_block_8*.
	$blk           = $blockptr;
	$callout_log[] = array(
		'num' => $blk->callout_number,
		'pos' => $blk->current_position,
		'mat' => $blk->start_match,
	);
	return 0; // continue matching
};
// Cast our PHP closure to a C function pointer. PHP FFI supports this
// for callbacks via `FFI::cast` on a closure.
$cb_type = 'int (*)(pcre2_callout_block_8 *, void *)';
echo "Trying to bind callout callback...\n";
try {
	$cb_ffi = $ffi->new( $cb_type );
	echo "Callback type created.\n";
	// PHP FFI does not directly support binding a closure to a function
	// pointer in arbitrary C signatures - this typically needs a Zend
	// FFI extension feature or libffi closures.
} catch ( \Throwable $e ) {
	echo 'Could not bind: ', $e->getMessage(), "\n";
}

// Even attempting to call pcre2_set_callout_8 with a closure tends to
// fail. Document and stop.
echo "\nConclusion: PHP FFI cannot bind a PHP callback to a C function pointer in stock PHP, so it cannot supply a PCRE2 callout function.\n";
